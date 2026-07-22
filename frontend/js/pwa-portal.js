/**
 * ============================================================
 * PWA Portal do Morador — Firebase Cloud Messaging
 * Versão: 2.0.0
 * Credenciais: carregadas dinamicamente via api_pwa_sw_config.php
 * ============================================================
 */

// URL base da API
const API_PWA     = '../api/api_pwa_push.php';
const API_SW_CFG  = '/api/api_pwa_sw_config.php?format=json';

// Credenciais preenchidas após fetchConfig()
let FIREBASE_CONFIG = null;
let VAPID_KEY       = null;

// ============================================================
// MÓDULO PWA PORTAL
// ============================================================
const PWAPortal = (() => {
    'use strict';

    let _messaging    = null;
    let _swRegistered = false;
    let _fcmToken     = null;
    let _initialized  = false;
    let _recarregandoParaAtualizar = false; // evita reload duplicado ao trocar de Service Worker

    // ── Carregar configuração dinâmica do banco ──────────────
    async function _fetchConfig() {
        try {
            const r = await fetch(API_SW_CFG, { cache: 'no-store' });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const cfg = await r.json();
            // firebaseConfigurado = false quando credenciais são placeholder ou estão vazias
            if (cfg.firebaseConfigurado && cfg.firebase && cfg.firebase.apiKey) {
                FIREBASE_CONFIG = cfg.firebase;
                VAPID_KEY       = cfg.vapidKey || null;
                console.log('[PWA] Config carregada do banco. Projeto:', FIREBASE_CONFIG.projectId);
                return true;
            }
            console.warn('[PWA] Firebase não configurado — acesse o ERP → Portal PWA → Central PWA para configurar.');
            return false;
        } catch (e) {
            console.error('[PWA] Erro ao carregar config:', e);
            return false;
        }
    }

    // ── Inicializar PWA ──────────────────────────────────────
    async function init() {
        if (_initialized) return;
        _initialized = true;

        console.log('[PWA] Inicializando Portal PWA...');

        // Carregar credenciais do banco (dinâmicas)
        const configOk = await _fetchConfig();

        // Registrar Service Worker (independente da config Firebase)
        await _registrarServiceWorker();

        // Verificar suporte a notificações
        if (!('Notification' in window)) {
            console.warn('[PWA] Este navegador não suporta notificações push.');
            return;
        }

        // Inicializar Firebase apenas se config disponível
        if (configOk) {
            _inicializarFirebase();
            _verificarPermissao();
        } else {
            console.warn('[PWA] Notificações push desativadas — Firebase não configurado.');
        }

        // Escutar mensagens do Service Worker
        _escutarMensagensSW();

        // Exibir banner de instalação se aplicável
        _configurarBannerInstalacao();

        console.log('[PWA] Inicialização concluída.');
    }

    // ── Registrar Service Worker ─────────────────────────────
    async function _registrarServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            console.warn('[PWA] Service Workers não suportados neste navegador.');
            return;
        }

        // O reload após "Atualizar" só deve acontecer quando o novo Service Worker
        // realmente assumir o controle da página — nunca antes disso. Sem este
        // listener, o reload disparava imediatamente após o postMessage (corrida),
        // a página voltava a ser controlada pelo worker antigo e o banner de
        // "Nova versão disponível" reaparecia em loop mesmo depois de clicar.
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (_recarregandoParaAtualizar) return;
            _recarregandoParaAtualizar = true;
            window.location.reload();
        });

        try {
            const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js', {
                scope: '/'
            });
            _swRegistered = true;
            console.log('[PWA] Service Worker registrado com sucesso. Scope:', registration.scope);

            // Verificar atualizações
            registration.addEventListener('updatefound', () => {
                console.log('[PWA] Nova versão do Service Worker disponível.');
                const newWorker = registration.installing;
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        _mostrarBannerAtualizacao(newWorker);
                    }
                });
            });

        } catch (err) {
            console.error('[PWA] Erro ao registrar Service Worker:', err);
        }
    }

    // ── Inicializar Firebase ─────────────────────────────────
    function _inicializarFirebase() {
        try {
            if (typeof firebase === 'undefined') {
                console.error('[PWA] SDK Firebase não carregado. Verifique os scripts no HTML.');
                return;
            }

            // Verificar se já foi inicializado
            if (!firebase.apps.length) {
                firebase.initializeApp(FIREBASE_CONFIG);
            }

            _messaging = firebase.messaging();
            console.log('[PWA] Firebase inicializado com sucesso.');

            // Escutar mensagens em foreground (app aberto)
            _messaging.onMessage((payload) => {
                console.log('[PWA] Mensagem recebida em foreground:', payload);
                _exibirNotificacaoForeground(payload);
            });

        } catch (err) {
            console.error('[PWA] Erro ao inicializar Firebase:', err);
        }
    }

    // ── Verificar permissão de notificação ───────────────────
    function _verificarPermissao() {
        const permissao = Notification.permission;
        console.log('[PWA] Permissão de notificação atual:', permissao);

        if (permissao === 'granted') {
            _obterERegistrarToken();
            _atualizarUIPermissao('granted');
        } else if (permissao === 'default') {
            _atualizarUIPermissao('default');
            // Mostrar banner de solicitação após 3 segundos
            setTimeout(_mostrarBannerPermissao, 3000);
        } else {
            _atualizarUIPermissao('denied');
        }
    }

    // ── Solicitar permissão ──────────────────────────────────
    async function solicitarPermissao() {
        if (!_messaging) {
            console.error('[PWA] Firebase não inicializado.');
            return false;
        }

        try {
            console.log('[PWA] Solicitando permissão de notificação...');
            const permissao = await Notification.requestPermission();

            if (permissao === 'granted') {
                console.log('[PWA] Permissão concedida!');
                _atualizarUIPermissao('granted');
                await _obterERegistrarToken();
                _fecharBannerPermissao();
                return true;
            } else {
                console.warn('[PWA] Permissão negada pelo usuário.');
                _atualizarUIPermissao('denied');
                _fecharBannerPermissao();
                return false;
            }
        } catch (err) {
            console.error('[PWA] Erro ao solicitar permissão:', err);
            return false;
        }
    }

    // ── Obter e registrar token FCM ──────────────────────────
    async function _obterERegistrarToken() {
        if (!_messaging) return;

        try {
            const token = await _messaging.getToken({ vapidKey: VAPID_KEY });

            if (!token) {
                console.warn('[PWA] Não foi possível obter token FCM. Verifique a VAPID Key.');
                return;
            }

            console.log('[PWA] Token FCM obtido:', token.substring(0, 20) + '...');
            _fcmToken = token;

            // Registrar token na API
            await _registrarTokenNaAPI(token);

        } catch (err) {
            console.error('[PWA] Erro ao obter token FCM:', err);
        }
    }

    // ── Registrar token na API PHP ───────────────────────────
    async function _registrarTokenNaAPI(token) {
        const authToken = localStorage.getItem('portal_token');
        if (!authToken) {
            console.warn('[PWA] Token de autenticação não encontrado. Usuário não logado?');
            return;
        }

        try {
            const response = await fetch(`${API_PWA}?action=registrar_token`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                },
                body: JSON.stringify({
                    fcm_token:   token,
                    device_info: navigator.userAgent,
                    plataforma:  _detectarPlataforma()
                })
            });

            const data = await response.json();
            if (data.sucesso) {
                console.log('[PWA] Token registrado na API com sucesso. ID:', data.dados?.token_id);
                localStorage.setItem('pwa_fcm_token', token);
            } else {
                console.error('[PWA] Erro ao registrar token na API:', data.mensagem);
            }
        } catch (err) {
            console.error('[PWA] Erro de rede ao registrar token:', err);
        }
    }

    // ── Detectar plataforma ──────────────────────────────────
    function _detectarPlataforma() {
        const ua = navigator.userAgent.toLowerCase();
        if (/android/.test(ua)) return 'android';
        if (/iphone|ipad|ipod/.test(ua)) return 'ios';
        return 'web';
    }

    // ── Exibir notificação em foreground ─────────────────────
    function _exibirNotificacaoForeground(payload) {
        const { notification, data } = payload;
        const titulo = notification?.title || data?.title || 'Portal do Morador';
        const corpo  = notification?.body  || data?.body  || 'Nova notificação';
        const tipo   = data?.tipo || 'geral';
        const url    = data?.url  || '#';

        // Criar toast de notificação na tela
        _criarToast(titulo, corpo, tipo, url);

        // Atualizar badge do sino se existir
        _atualizarBadgeSino();
    }

    // ── Criar toast de notificação ───────────────────────────
    function _criarToast(titulo, corpo, tipo, url) {
        // Remover toast anterior do mesmo tipo
        const existente = document.querySelector(`.pwa-toast[data-tipo="${tipo}"]`);
        if (existente) existente.remove();

        const ICONES_TIPO = {
            visitante:     '🚶',
            inadimplencia: '💰',
            comunicado:    '📢',
            aviso:         '⚠️',
            os:            '🔧',
            urgente:       '🚨',
            geral:         '🔔'
        };

        const CORES_TIPO = {
            visitante:     '#22c55e',
            inadimplencia: '#ef4444',
            comunicado:    '#2563eb',
            aviso:         '#f59e0b',
            os:            '#f97316',
            urgente:       '#dc2626',
            geral:         '#6366f1'
        };

        const toast = document.createElement('div');
        toast.className = 'pwa-toast';
        toast.setAttribute('data-tipo', tipo);
        toast.style.cssText = `
            position: fixed;
            top: 80px;
            right: 16px;
            z-index: 99999;
            max-width: 360px;
            width: calc(100vw - 32px);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            border-left: 4px solid ${CORES_TIPO[tipo] || '#6366f1'};
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: pwaToastIn 0.4s cubic-bezier(0.34,1.56,0.64,1);
            cursor: pointer;
        `;

        toast.innerHTML = `
            <span style="font-size:24px;flex-shrink:0;line-height:1">${ICONES_TIPO[tipo] || '🔔'}</span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:14px;color:#1e293b;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${_escHtml(titulo)}</div>
                <div style="font-size:13px;color:#64748b;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${_escHtml(corpo)}</div>
            </div>
            <button onclick="this.closest('.pwa-toast').remove()" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:18px;padding:0;flex-shrink:0;line-height:1">×</button>
        `;

        // Clicar no toast navega para a URL
        toast.addEventListener('click', (e) => {
            if (e.target.tagName !== 'BUTTON') {
                toast.remove();
                if (url && url !== '#') {
                    if (url.includes('#')) {
                        const aba = url.split('#')[1];
                        if (typeof abrirAba === 'function') abrirAba(aba);
                    } else {
                        window.location.href = url;
                    }
                }
            }
        });

        document.body.appendChild(toast);

        // Auto-remover após 6 segundos
        setTimeout(() => {
            if (toast.parentNode) {
                toast.style.animation = 'pwaToastOut 0.3s ease forwards';
                setTimeout(() => toast.remove(), 300);
            }
        }, 6000);
    }

    // ── Atualizar badge do sino ──────────────────────────────
    async function _atualizarBadgeSino() {
        const authToken = localStorage.getItem('portal_token');
        if (!authToken) return;

        try {
            const response = await fetch(`${API_PWA}?action=listar_minhas&limite=1`, {
                headers: { 'Authorization': `Bearer ${authToken}` }
            });
            const data = await response.json();
            if (data.sucesso) {
                const naoLidas = data.dados?.nao_lidas || 0;
                _definirBadge(naoLidas);
            }
        } catch (err) {
            // Silencioso
        }
    }

    // ── Definir badge numérico ───────────────────────────────
    function _definirBadge(count) {
        // Badge no sino do portal
        const badge = document.querySelector('#pwa-notif-badge');
        if (badge) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = count > 0 ? 'flex' : 'none';
        }

        // Badge na API do navegador (Android Chrome)
        if ('setAppBadge' in navigator && count > 0) {
            navigator.setAppBadge(count).catch(() => {});
        } else if ('clearAppBadge' in navigator && count === 0) {
            navigator.clearAppBadge().catch(() => {});
        }
    }

    // ── Mostrar banner de permissão ──────────────────────────
    function _mostrarBannerPermissao() {
        if (Notification.permission !== 'default') return;
        if (document.getElementById('pwa-permissao-banner')) return;

        const banner = document.createElement('div');
        banner.id = 'pwa-permissao-banner';
        banner.style.cssText = `
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 99998;
            background: #1e293b;
            color: #fff;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
            animation: pwaSlideUp 0.4s ease;
        `;

        banner.innerHTML = `
            <span style="font-size:28px;flex-shrink:0">🔔</span>
            <div style="flex:1">
                <div style="font-weight:700;font-size:15px;margin-bottom:2px">Ativar Notificações</div>
                <div style="font-size:13px;color:#94a3b8">Receba avisos de visitantes, comunicados e chamados em tempo real.</div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0">
                <button id="pwa-btn-negar" style="padding:8px 14px;border-radius:8px;border:1px solid #475569;background:transparent;color:#94a3b8;cursor:pointer;font-size:13px">Agora não</button>
                <button id="pwa-btn-ativar" style="padding:8px 16px;border-radius:8px;border:none;background:#2563eb;color:#fff;cursor:pointer;font-size:13px;font-weight:600">Ativar</button>
            </div>
        `;

        document.body.appendChild(banner);

        document.getElementById('pwa-btn-ativar').addEventListener('click', () => {
            solicitarPermissao();
        });

        document.getElementById('pwa-btn-negar').addEventListener('click', () => {
            banner.remove();
            // Não perguntar novamente por 7 dias
            localStorage.setItem('pwa_permissao_adiada', Date.now() + 7 * 24 * 60 * 60 * 1000);
        });
    }

    // ── Fechar banner de permissão ───────────────────────────
    function _fecharBannerPermissao() {
        const banner = document.getElementById('pwa-permissao-banner');
        if (banner) banner.remove();
    }

    // ── Mostrar banner de atualização ────────────────────────
    function _mostrarBannerAtualizacao(newWorker) {
        // Evita empilhar vários banners caso o evento dispare mais de uma vez
        if (document.getElementById('pwa-atualizacao-banner')) return;

        const banner = document.createElement('div');
        banner.id = 'pwa-atualizacao-banner';
        banner.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 99999;
            background: #059669;
            color: #fff;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        `;
        banner.innerHTML = `
            <span>🔄 Nova versão disponível!</span>
            <button style="padding:6px 14px;border-radius:6px;border:none;background:#fff;color:#059669;cursor:pointer;font-weight:600;font-size:13px">Atualizar</button>
        `;
        const btn = banner.querySelector('button');
        btn.addEventListener('click', () => {
            btn.disabled = true;
            btn.style.cursor = 'default';
            btn.style.opacity = '.7';
            btn.textContent = 'Atualizando...';

            newWorker.postMessage({ type: 'SKIP_WAITING' });

            // O reload de verdade acontece no listener de 'controllerchange'
            // (ver _registrarServiceWorker), assim que o novo Service Worker
            // assumir o controle. Esta rede de segurança só existe para não
            // deixar o usuário travado caso o evento, por algum motivo, não dispare.
            setTimeout(() => {
                if (_recarregandoParaAtualizar) return;
                _recarregandoParaAtualizar = true;
                window.location.reload();
            }, 4000);
        });
        document.body.appendChild(banner);
    }

    // ── Atualizar UI de permissão ────────────────────────────
    function _atualizarUIPermissao(status) {
        const btn = document.getElementById('pwa-toggle-notif');
        if (!btn) return;

        const textos = {
            granted: { texto: '🔔 Notificações ativas',  cor: '#22c55e' },
            default: { texto: '🔕 Ativar notificações',  cor: '#f59e0b' },
            denied:  { texto: '🚫 Notificações bloqueadas', cor: '#ef4444' }
        };

        const info = textos[status] || textos.default;
        btn.textContent = info.texto;
        btn.style.color = info.cor;
    }

    // ── Configurar banner de instalação PWA ──────────────────
    function _configurarBannerInstalacao() {
        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            console.log('[PWA] App pode ser instalado.');

            // Mostrar botão de instalação
            const btnInstalar = document.getElementById('pwa-btn-instalar');
            if (btnInstalar) {
                btnInstalar.style.display = 'flex';
                btnInstalar.addEventListener('click', async () => {
                    if (!deferredPrompt) return;
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    console.log('[PWA] Resultado da instalação:', outcome);
                    deferredPrompt = null;
                    btnInstalar.style.display = 'none';
                });
            }
        });

        window.addEventListener('appinstalled', () => {
            console.log('[PWA] App instalado com sucesso!');
            const btnInstalar = document.getElementById('pwa-btn-instalar');
            if (btnInstalar) btnInstalar.style.display = 'none';
        });
    }

    // ── Escutar mensagens do Service Worker ──────────────────
    function _escutarMensagensSW() {
        if (!navigator.serviceWorker) return;

        navigator.serviceWorker.addEventListener('message', (event) => {
            const { type, url, tipo } = event.data || {};

            if (type === 'NOTIFICATION_CLICK') {
                console.log('[PWA] Clique em notificação recebido do SW:', tipo, url);
                if (url && url.includes('#')) {
                    const aba = url.split('#')[1];
                    if (typeof abrirAba === 'function') {
                        setTimeout(() => abrirAba(aba), 500);
                    }
                }
            }
        });
    }

    // ── Remover token ao fazer logout ────────────────────────
    async function removerTokenAoLogout() {
        const fcmToken  = localStorage.getItem('pwa_fcm_token');
        const authToken = localStorage.getItem('portal_token');
        if (!fcmToken || !authToken) return;

        try {
            await fetch(`${API_PWA}?action=remover_token`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                },
                body: JSON.stringify({ fcm_token: fcmToken })
            });
            localStorage.removeItem('pwa_fcm_token');
            console.log('[PWA] Token removido ao fazer logout.');
        } catch (err) {
            // Silencioso
        }
    }

    // ── Helper: escapar HTML ─────────────────────────────────
    function _escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Injetar CSS de animações ─────────────────────────────
    function _injetarCSS() {
        if (document.getElementById('pwa-portal-css')) return;
        const style = document.createElement('style');
        style.id = 'pwa-portal-css';
        style.textContent = `
            @keyframes pwaToastIn {
                from { opacity: 0; transform: translateX(120%); }
                to   { opacity: 1; transform: translateX(0); }
            }
            @keyframes pwaToastOut {
                from { opacity: 1; transform: translateX(0); }
                to   { opacity: 0; transform: translateX(120%); }
            }
            @keyframes pwaSlideUp {
                from { transform: translateY(100%); }
                to   { transform: translateY(0); }
            }
            #pwa-notif-badge {
                position: absolute;
                top: -4px;
                right: -4px;
                background: #ef4444;
                color: #fff;
                font-size: 10px;
                font-weight: 700;
                min-width: 18px;
                height: 18px;
                border-radius: 9px;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 0 4px;
                line-height: 1;
                pointer-events: none;
            }
        `;
        document.head.appendChild(style);
    }

    // ── API pública ──────────────────────────────────────────
    return {
        init,
        solicitarPermissao,
        removerTokenAoLogout,
        atualizarBadge: _atualizarBadgeSino,
        _injetarCSS
    };
})();

// ── Auto-inicializar quando o DOM estiver pronto ─────────────
(function() {
    // Injetar CSS imediatamente
    PWAPortal._injetarCSS();

    // Inicializar após o portal estar carregado
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            // Aguardar o portal inicializar (500ms)
            setTimeout(PWAPortal.init, 500);
        });
    } else {
        setTimeout(PWAPortal.init, 500);
    }
})();
