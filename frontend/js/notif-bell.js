/**
 * notif-bell.js — Componente Global de Notificações
 * Injeta o ícone de sino no cabeçalho, faz polling automático,
 * toca som e anima o ícone quando há novas notificações.
 * Versão: 1.2 | 2026-06-23
 *
 * CORREÇÕES v1.2:
 * - Sino inserido dentro de #headerSessionGroup (ao lado da sessão do usuário)
 * - Fallback: antes de #userMiniProfile ou no header se o grupo não existir
 * - Evento corrigido: pageLoaded (era appPageLoaded)
 * - Sistema de retry (10x × 200ms) para aguardar o header ser recriado
 */
(function () {
    'use strict';

    const API         = '/api/api_notificacoes_os.php';
    const POLL_MS     = 30000;  // 30 segundos
    const POLL_FAST   = 10000;  // 10 segundos quando há não lidos
    let _pollTimer    = null;
    let _prevCount    = 0;
    let _alertas      = [];
    let _audioCtx     = null;
    let _initialized  = false;

    // ─── Injetar HTML do sino no cabeçalho ───────────────────────────────
    function _injetar() {
        const header = document.querySelector('header.header');
        if (!header) return false;
        if (document.getElementById('notif-bell-wrap')) return true;

        const wrap = document.createElement('div');
        wrap.id = 'notif-bell-wrap';
        wrap.innerHTML = `
            <button class="notif-bell-btn" id="notif-bell-btn" title="Notificações" aria-label="Notificações">
                <i class="fas fa-bell" id="notif-bell-icon"></i>
                <span class="notif-bell-badge" id="notif-bell-badge" style="display:none;">0</span>
            </button>
            <div class="notif-dropdown" id="notif-dropdown" style="display:none;">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-titulo"><i class="fas fa-bell"></i> Notificações</span>
                    <div class="notif-dropdown-actions">
                        <button class="notif-btn-marcar-todos" id="notif-btn-marcar-todos" title="Marcar todas como lidas">
                            <i class="fas fa-check-double"></i>
                        </button>
                        <button class="notif-btn-dispensar-todos" id="notif-btn-dispensar-todos" title="Dispensar todas">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="notif-dropdown-lista" id="notif-dropdown-lista">
                    <div class="notif-dropdown-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                </div>
                <div class="notif-dropdown-footer">
                    <a href="#" onclick="if(window.AppRouter)window.AppRouter.loadPage('notificacoes');document.getElementById('notif-dropdown').style.display='none';return false;">
                        <i class="fas fa-cog"></i> Configurar notificações
                    </a>
                </div>
            </div>`;

        // Inserir dentro do #headerSessionGroup (ao lado da sessão do usuário)
        // O user-profile-sidebar.js v1.1+ cria o grupo #headerSessionGroup no header
        const sessionGroup = document.getElementById('headerSessionGroup');
        const userMiniProfile = document.getElementById('userMiniProfile');
        const appUserMenu = header.querySelector('app-user-menu');
        if (sessionGroup) {
            // Inserir o sino ANTES do #userMiniProfile dentro do grupo de sessão
            const userMiniInGroup = sessionGroup.querySelector('#userMiniProfile');
            if (userMiniInGroup) {
                sessionGroup.insertBefore(wrap, userMiniInGroup);
            } else {
                sessionGroup.prepend(wrap);
            }
        } else if (userMiniProfile && userMiniProfile.parentElement === header) {
            header.insertBefore(wrap, userMiniProfile);
        } else if (appUserMenu) {
            header.insertBefore(wrap, appUserMenu);
        } else {
            header.appendChild(wrap);
        }

        // Injetar CSS
        _injetarCSS();

        // Bind eventos
        document.getElementById('notif-bell-btn').addEventListener('click', _toggleDropdown);
        document.getElementById('notif-btn-marcar-todos').addEventListener('click', _marcarTodosLidos);
        document.getElementById('notif-btn-dispensar-todos').addEventListener('click', _dispensarTodos);

        // Fechar ao clicar fora
        document.addEventListener('click', function (e) {
            const wrap = document.getElementById('notif-bell-wrap');
            const drop = document.getElementById('notif-dropdown');
            if (wrap && drop && !wrap.contains(e.target)) {
                drop.style.display = 'none';
            }
        });

        _initialized = true;
        return true;
    }

    // ─── CSS do componente ────────────────────────────────────────────────
    function _injetarCSS() {
        if (document.getElementById('notif-bell-css')) return;
        const style = document.createElement('style');
        style.id = 'notif-bell-css';
        style.textContent = `
/* ── Notif Bell ── */
#notif-bell-wrap {
    position: relative;
    display: flex;
    align-items: center;
    margin-right: 8px;
}
.notif-bell-btn {
    position: relative;
    background: none;
    border: none;
    cursor: pointer;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    font-size: 1.15rem;
    transition: background .2s, color .2s;
}
.notif-bell-btn:hover {
    background: #f1f5f9;
    color: #2563eb;
}
.notif-bell-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    background: #ef4444;
    color: #fff;
    font-size: .6rem;
    font-weight: 700;
    min-width: 16px;
    height: 16px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 3px;
    line-height: 1;
    box-shadow: 0 0 0 2px #fff;
    pointer-events: none;
}
/* Animação de shake */
@keyframes notif-shake {
    0%,100% { transform: rotate(0deg); }
    10%,50%  { transform: rotate(-12deg); }
    30%,70%  { transform: rotate(12deg); }
    90%      { transform: rotate(-6deg); }
}
.notif-bell-btn.shaking #notif-bell-icon {
    animation: notif-shake .7s ease-in-out;
    display: inline-block;
    transform-origin: top center;
    color: #f59e0b;
}
/* Dropdown */
.notif-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: -8px;
    width: 380px;
    max-width: calc(100vw - 24px);
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 20px 40px rgba(0,0,0,.12), 0 4px 8px rgba(0,0,0,.06);
    z-index: 999990;
    overflow: hidden;
}
.notif-dropdown-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 12px;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
}
.notif-dropdown-titulo {
    font-size: .9rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 6px;
}
.notif-dropdown-titulo i { color: #f59e0b; }
.notif-dropdown-actions {
    display: flex;
    gap: 6px;
}
.notif-dropdown-actions button {
    background: none;
    border: none;
    cursor: pointer;
    color: #94a3b8;
    font-size: .8rem;
    padding: 4px 6px;
    border-radius: 6px;
    transition: background .2s, color .2s;
}
.notif-dropdown-actions button:hover { background: #e2e8f0; color: #475569; }
.notif-dropdown-lista {
    max-height: 380px;
    overflow-y: auto;
}
.notif-dropdown-loading {
    padding: 2rem;
    text-align: center;
    color: #94a3b8;
    font-size: .85rem;
}
/* Item de notificação */
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    border-bottom: 1px solid #f8fafc;
    transition: background .15s;
    cursor: default;
}
.notif-item:hover { background: #f8fafc; }
.notif-item.nao-lido { background: #eff6ff; }
.notif-item.nao-lido:hover { background: #dbeafe; }
.notif-item-icone {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .85rem;
    flex-shrink: 0;
    margin-top: 2px;
}
.notif-item-icone.orange { background: #fff7ed; color: #ea580c; }
.notif-item-icone.red    { background: #fef2f2; color: #dc2626; }
.notif-item-icone.blue   { background: #eff6ff; color: #2563eb; }
.notif-item-icone.green  { background: #f0fdf4; color: #16a34a; }
.notif-item-icone.yellow { background: #fefce8; color: #ca8a04; }
.notif-item-body { flex: 1; min-width: 0; }
.notif-item-titulo {
    font-size: .82rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.notif-item-corpo {
    font-size: .77rem;
    color: #64748b;
    margin: 0 0 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.notif-item-meta {
    font-size: .7rem;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 8px;
}
.notif-item-link {
    font-size: .7rem;
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
}
.notif-item-link:hover { text-decoration: underline; }
.notif-item-acoes {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex-shrink: 0;
}
.notif-item-acoes button {
    background: none;
    border: none;
    cursor: pointer;
    color: #cbd5e1;
    font-size: .75rem;
    padding: 3px 5px;
    border-radius: 4px;
    transition: background .2s, color .2s;
}
.notif-item-acoes button:hover { background: #f1f5f9; color: #64748b; }
.notif-item-acoes .btn-dispensar:hover { color: #ef4444; }
.notif-vazia {
    padding: 2.5rem 1rem;
    text-align: center;
    color: #94a3b8;
}
.notif-vazia i { font-size: 2rem; margin-bottom: .5rem; display: block; color: #e2e8f0; }
.notif-vazia p { font-size: .85rem; margin: 0; }
.notif-dropdown-footer {
    padding: 10px 16px;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
    text-align: center;
}
.notif-dropdown-footer a {
    font-size: .78rem;
    color: #64748b;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: color .2s;
}
.notif-dropdown-footer a:hover { color: #2563eb; }
        `;
        document.head.appendChild(style);
    }

    // ─── Toggle dropdown ──────────────────────────────────────────────────
    function _toggleDropdown() {
        const drop = document.getElementById('notif-dropdown');
        if (!drop) return;
        const isOpen = drop.style.display !== 'none';
        drop.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) {
            _carregarAlertas();
        }
    }

    // ─── Polling ──────────────────────────────────────────────────────────
    function _iniciarPolling() {
        _poll();
        _pollTimer = setInterval(_poll, POLL_MS);
    }

    async function _poll() {
        try {
            const r    = await fetch(`${API}?acao=contar_nao_lidos`, { credentials: 'include' });
            const json = await r.json();
            if (!json.sucesso) return;
            const count = parseInt(json.dados?.nao_lidos || 0);
            _atualizarBadge(count);

            // Novas notificações chegaram?
            if (count > _prevCount && _prevCount >= 0) {
                _tocarSom();
                _animarSino();
            }
            _prevCount = count;

            // Ajustar intervalo de polling
            clearInterval(_pollTimer);
            _pollTimer = setInterval(_poll, count > 0 ? POLL_FAST : POLL_MS);
        } catch (e) {
            // Silencioso
        }
    }

    // ─── Atualizar badge ──────────────────────────────────────────────────
    function _atualizarBadge(count) {
        const badge = document.getElementById('notif-bell-badge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }

    // ─── Carregar alertas no dropdown ─────────────────────────────────────
    async function _carregarAlertas() {
        const lista = document.getElementById('notif-dropdown-lista');
        if (!lista) return;
        lista.innerHTML = '<div class="notif-dropdown-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

        try {
            const r    = await fetch(`${API}?acao=meus_alertas&limite=20`, { credentials: 'include' });
            const json = await r.json();
            if (!json.sucesso) { lista.innerHTML = '<div class="notif-dropdown-loading">Erro ao carregar.</div>'; return; }

            _alertas = json.dados?.alertas || [];
            const naoLidos = parseInt(json.dados?.nao_lidos || 0);
            _atualizarBadge(naoLidos);
            _prevCount = naoLidos;

            if (!_alertas.length) {
                lista.innerHTML = `<div class="notif-vazia"><i class="fas fa-bell-slash"></i><p>Nenhuma notificação no momento</p></div>`;
                return;
            }

            const _corIcone = { 'os_criada': 'orange', 'os_aberta_horas': 'yellow', 'os_prioridade_urgente': 'red', 'os_prioridade_alta': 'orange' };
            const _iconEvento = { 'os_criada': 'fa-wrench', 'os_aberta_horas': 'fa-clock', 'os_prioridade_urgente': 'fa-exclamation-triangle', 'os_prioridade_alta': 'fa-arrow-up' };

            lista.innerHTML = _alertas.map(a => {
                const cor   = _corIcone[a.evento] || 'blue';
                const icon  = _iconEvento[a.evento] || (a.icone || 'fa-bell');
                const lido  = a.lido == 1;
                const link  = a.link_pagina
                    ? `<a class="notif-item-link" href="#" onclick="if(window.AppRouter)window.AppRouter.loadPage('${a.link_pagina}');document.getElementById('notif-dropdown').style.display='none';return false;">Ver detalhes</a>`
                    : '';
                return `<div class="notif-item ${lido ? '' : 'nao-lido'}" data-dest="${a.dest_id}">
                    <div class="notif-item-icone ${cor}"><i class="fas ${icon}"></i></div>
                    <div class="notif-item-body">
                        <p class="notif-item-titulo">${a.titulo}</p>
                        <p class="notif-item-corpo">${a.corpo || ''}</p>
                        <div class="notif-item-meta">
                            <span>${a.criado_fmt || ''}</span>
                            ${link}
                        </div>
                    </div>
                    <div class="notif-item-acoes">
                        ${!lido ? `<button class="btn-lido" title="Marcar como lida" onclick="window._notifMarcarLido(${a.dest_id})"><i class="fas fa-check"></i></button>` : ''}
                        <button class="btn-dispensar" title="Dispensar" onclick="window._notifDispensar(${a.dest_id})"><i class="fas fa-times"></i></button>
                    </div>
                </div>`;
            }).join('');
        } catch (e) {
            lista.innerHTML = '<div class="notif-dropdown-loading">Erro ao carregar notificações.</div>';
        }
    }

    // ─── Ações ────────────────────────────────────────────────────────────
    window._notifMarcarLido = async function (destId) {
        await fetch(`${API}?acao=marcar_lido`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'marcar_lido', dest_id: destId })
        });
        _carregarAlertas();
        _poll();
    };

    window._notifDispensar = async function (destId) {
        await fetch(`${API}?acao=dispensar`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'dispensar', dest_id: destId })
        });
        _carregarAlertas();
        _poll();
    };

    async function _marcarTodosLidos() {
        await fetch(`${API}?acao=marcar_todos_lidos`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'marcar_todos_lidos' })
        });
        _carregarAlertas();
        _poll();
    }

    async function _dispensarTodos() {
        if (!confirm('Dispensar todas as notificações?')) return;
        await fetch(`${API}?acao=dispensar_todas`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'dispensar_todas' })
        });
        _carregarAlertas();
        _poll();
    }

    // ─── Som de notificação (Web Audio API) ───────────────────────────────
    function _tocarSom() {
        try {
            if (!_audioCtx) {
                _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            // Dois beeps curtos
            [0, 200].forEach(delay => {
                const osc  = _audioCtx.createOscillator();
                const gain = _audioCtx.createGain();
                osc.connect(gain);
                gain.connect(_audioCtx.destination);
                osc.type      = 'sine';
                osc.frequency.setValueAtTime(880, _audioCtx.currentTime + delay / 1000);
                gain.gain.setValueAtTime(0.3, _audioCtx.currentTime + delay / 1000);
                gain.gain.exponentialRampToValueAtTime(0.001, _audioCtx.currentTime + delay / 1000 + 0.25);
                osc.start(_audioCtx.currentTime + delay / 1000);
                osc.stop(_audioCtx.currentTime + delay / 1000 + 0.25);
            });
        } catch (e) {
            // Silencioso se o browser bloquear
        }
    }

    // ─── Animação de shake no sino ────────────────────────────────────────
    function _animarSino() {
        const btn = document.getElementById('notif-bell-btn');
        if (!btn) return;
        btn.classList.remove('shaking');
        void btn.offsetWidth; // reflow
        btn.classList.add('shaking');
        setTimeout(() => btn.classList.remove('shaking'), 1000);
    }

    // ─── Inicialização com retry ──────────────────────────────────────────
    function _init() {
        // Tentar injetar com retry — user-profile-sidebar.js pode recriar o header
        // após o notif-bell.js ser carregado
        let tentativas = 0;
        const maxTentativas = 10;
        const intervalo = 200; // ms entre tentativas

        function _tentarInjetar() {
            tentativas++;
            const ok = _injetar();
            if (ok) {
                // Injeção bem-sucedida — iniciar polling
                if (!_pollTimer) _iniciarPolling();
                console.log('[NotifBell] Sino injetado com sucesso (tentativa ' + tentativas + ')');
            } else if (tentativas < maxTentativas) {
                setTimeout(_tentarInjetar, intervalo);
            } else {
                console.warn('[NotifBell] Não foi possível injetar o sino após ' + maxTentativas + ' tentativas.');
            }
        }

        // Aguardar 800ms para o user-profile-sidebar.js recriar o header
        setTimeout(_tentarInjetar, 800);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _init);
    } else {
        _init();
    }

    // Reinicializar ao navegar entre páginas (SPA)
    // CORRIGIDO: era 'appPageLoaded', o router dispara 'pageLoaded'
    document.addEventListener('pageLoaded', function () {
        // Aguardar um tick para o router terminar de renderizar
        setTimeout(function () {
            if (!document.getElementById('notif-bell-wrap')) {
                const ok = _injetar();
                if (ok && !_pollTimer) _iniciarPolling();
            }
        }, 300);
    });

})();
