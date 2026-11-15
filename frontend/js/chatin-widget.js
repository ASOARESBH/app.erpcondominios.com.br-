/**
 * ERP CONDOMÍNIOS — CHAT-IN (widget global)
 *
 * Comunicador interno entre moradores e usuários do condomínio.
 *  - Painel (layout-base.html): sessão PHP do operador (cookies).
 *  - Portal do Morador: token Bearer de sessoes_portal.
 *
 * Interface: botão flutuante (com contador de não lidas) que abre um painel à direita.
 * O painel pode ser FIXADO (empurra o conteúdo, permanece aberto entre páginas) ou
 * MINIMIZADO (volta ao botão). A página "Chat-In" do painel usa o mesmo componente
 * em modo embutido (ChatIn.montarEmbutido).
 *
 * Tempo real: polling de 2,5–5 s quando ativo, ~20 s quando minimizado e pausa em segundo
 * plano; com notificação do navegador consentida, mantém polling discreto em 20 s.
 * Privacidade: a API só devolve nome + unidade/função; nada de CPF, telefone ou e-mail.
 */
(function () {
    'use strict';
    if (window.ChatIn) return;

    const INTERVALO_ATIVO = 5000;
    const INTERVALO_FOCO = 2500;
    const INTERVALO_FECHADO = 20000;
    const MAX_CARACTERES = 2000;
    const EMOJIS = ['😀', '😂', '😊', '😍', '🙏', '👍', '👏', '🎉', '😉', '😢', '😮', '🤔', '❤️', '✅', '🚗', '📦', '🏠', '🔑', '⚠️', '👋'];
    const CHAVE_FIXADO = 'chatin_fixado';

    const S = {
        api: '/api/api_chatin.php',
        plataforma: 'painel',
        token: null,
        ativo: false,
        bloqueado: false,
        eu: null,
        versao: '',
        conversas: [],
        totalNaoLidas: 0,
        aberto: false,
        fixado: false,
        vista: 'conversas',          // conversas | contatos | conversa | preferencias
        conversa: null,              // objeto da conversa aberta
        mensagens: [],
        ultimoId: 0,
        temMais: false,
        timer: null,
        sincronizando: false,
        buscaTimer: null,
        contatos: [],
        preferencias: null,
        config: null,
        eventosCursor: 0,
        syncInicializado: false,
        ultimaNotificacaoSom: 0,
        tituloOriginal: '',
        tituloTimer: null,
        tituloAlternado: false,
        dicaAudioMostrada: false,
        typingTimer: null,
        cutucarTimer: null,
        cutucarAtePorConversa: Object.create(null),
        typingUltimoEnvio: 0,
        typingAtivo: false,
        contatoDigitando: false,
        metricasSync: [],
        raiz: null,                  // elemento raiz da UI (dock ou embutido)
        embutido: false,
        dock: null,
        launcher: null,
    };

    // ── utilitários ────────────────────────────────────────────────
    function esc(v) {
        return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function lsGet(k) { try { return localStorage.getItem(k); } catch (_) { return null; } }
    function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (_) { /* sem storage */ } }
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'ci-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }
    function iniciais(nome) {
        const p = String(nome || '?').trim().split(/\s+/);
        return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
    }
    function corAvatar(chave) {
        let h = 0;
        for (const ch of String(chave)) h = (h * 31 + ch.charCodeAt(0)) % 360;
        return `hsl(${h}, 55%, 45%)`;
    }
    function dataDe(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }
    function hora(s) {
        const d = dataDe(s);
        return d ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '';
    }
    function rotuloDia(s) {
        const d = dataDe(s); if (!d) return '';
        const hoje = new Date(); hoje.setHours(0, 0, 0, 0);
        const dia = new Date(d); dia.setHours(0, 0, 0, 0);
        const diff = Math.round((hoje - dia) / 86400000);
        if (diff === 0) return 'Hoje';
        if (diff === 1) return 'Ontem';
        return d.toLocaleDateString('pt-BR');
    }
    function quandoCurto(s) {
        const d = dataDe(s); if (!d) return '';
        return rotuloDia(s) === 'Hoje' ? hora(s) : (rotuloDia(s) === 'Ontem' ? 'Ontem' : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }));
    }
    function chavePessoa(p) { return (p.tipo === 'MORADOR' ? 'M' : 'U') + p.id; }
    function avatar(p, online) {
        const nome = p ? p.nome : '?';
        const k = p ? chavePessoa(p) : 'x';
        return `<span class="chatin-avatar" style="background:${corAvatar(k)}">${esc(iniciais(nome))}${online ? '<i class="chatin-online-dot" title="Online"></i>' : ''}</span>`;
    }
    function avatarAvisos() {
        return '<span class="chatin-avatar chatin-avatar-avisos"><i class="fas fa-bullhorn"></i></span>';
    }

    // ── API ────────────────────────────────────────────────────────
    async function api(acao, { metodo = 'GET', params = {}, corpo = null } = {}) {
        const qs = new URLSearchParams({ acao, ...params }).toString();
        const headers = { Accept: 'application/json' };
        if (S.token) headers.Authorization = 'Bearer ' + S.token;
        if (corpo) headers['Content-Type'] = 'application/json';
        const resp = await fetch(S.api + '?' + qs, {
            method: metodo, headers, credentials: S.token ? 'omit' : 'include', cache: 'no-store',
            body: corpo ? JSON.stringify(corpo) : undefined,
        });
        let dados = {};
        try { dados = await resp.json(); } catch (_) { dados = { sucesso: false, mensagem: 'Resposta inválida do servidor.' }; }
        if (!resp.ok || !dados.sucesso) {
            const erro = new Error(dados.mensagem || 'Falha no Chat-In.');
            erro.status = resp.status; erro.codigo = dados.codigo;
            erro.retryAfter = Math.max(0, parseInt(resp.headers.get('Retry-After') || '0', 10) || 0);
            throw erro;
        }
        return dados.dados || {};
    }

    function desativar(motivo) {
        S.bloqueado = true;
        pararPolling();
        if (S.launcher) S.launcher.hidden = true;
        if (S.dock) S.dock.hidden = true;
        document.body.classList.remove('chatin-fixado');
        console.info('[Chat-In] desativado:', motivo);
        if (S.embutido && S.raiz) {
            S.raiz.innerHTML = `<div class="chatin-vazio"><i class="fas fa-comments"></i><p>${esc(motivo)}</p></div>`;
        }
    }

    // ── polling ────────────────────────────────────────────────────
    function visivel() { return S.embutido || (S.aberto && !S.dock.hidden); }
    function agendar(ms, permitirOculta = false) {
        pararPolling();
        if (S.bloqueado || (document.hidden && !permitirOculta)) return;
        S.timer = setTimeout(sincronizar, ms);
    }
    function pararPolling() { if (S.timer) { clearTimeout(S.timer); S.timer = null; } }

    function pararDigitando() {
        if (S.typingTimer) clearTimeout(S.typingTimer);
        S.typingTimer = null;
        if (!S.typingAtivo || !S.conversa || !S.conversa.id) return;
        const conversaId = S.conversa.id;
        S.typingAtivo = false;
        api('digitando', { metodo: 'POST', corpo: { conversa_id: conversaId, ativo: false } }).catch(() => {});
    }
    function enviarDigitando() {
        if (!S.conversa || !S.conversa.id || S.conversa.tipo !== 'DIRETA' || !S.config?.recurso_digitando || S.preferencias?.mostrar_online === false) return;
        const agora = Date.now();
        if (!S.typingAtivo) {
            S.typingAtivo = true;
            S.typingUltimoEnvio = agora;
            api('digitando', { metodo: 'POST', corpo: { conversa_id: S.conversa.id, ativo: true } }).catch(() => { S.typingAtivo = false; });
        } else if (agora - S.typingUltimoEnvio >= 3000) {
            S.typingUltimoEnvio = agora;
            api('digitando', { metodo: 'POST', corpo: { conversa_id: S.conversa.id, ativo: true } }).catch(() => {});
        }
        if (S.typingTimer) clearTimeout(S.typingTimer);
        S.typingTimer = setTimeout(() => pararDigitando(), 6000);
    }

    function atualizarIndicadorDigitando() {
        const alvo = S.raiz && S.raiz.querySelector('[data-ci="mensagens"]');
        if (!alvo) return;
        const atual = alvo.querySelector('.chatin-digitando');
        if (S.contatoDigitando) {
            if (!atual) {
                const indicador = document.createElement('div');
                indicador.className = 'chatin-digitando';
                indicador.setAttribute('aria-live', 'polite');
                indicador.textContent = `${S.conversa?.contato?.nome || 'Contato'} está digitando`;
                indicador.innerHTML = `${esc(S.conversa?.contato?.nome || 'Contato')} está digitando<span aria-hidden="true"><i></i><i></i><i></i></span>`;
                alvo.appendChild(indicador);
            }
        } else if (atual) atual.remove();
    }

    function garantirTituloOriginal() {
        if (!S.tituloOriginal) S.tituloOriginal = document.title || 'ERP Condomínio';
    }
    function piscarTitulo() {
        garantirTituloOriginal();
        if (!document.hidden) return;
        if (S.tituloTimer) return;
        S.tituloAlternado = false;
        S.tituloTimer = setInterval(() => {
            if (!document.hidden || S.bloqueado) { pararTitulo(); return; }
            S.tituloAlternado = !S.tituloAlternado;
            document.title = S.tituloAlternado ? `(${Math.max(1, S.totalNaoLidas)}) Nova mensagem — ${S.tituloOriginal}` : S.tituloOriginal;
        }, 800);
    }
    function pararTitulo() {
        if (S.tituloTimer) clearInterval(S.tituloTimer);
        S.tituloTimer = null;
        if (S.tituloOriginal) document.title = S.tituloOriginal;
    }
    function mostrarDicaAudio() {
        if (S.dicaAudioMostrada || !document.body) return;
        S.dicaAudioMostrada = true;
        const dica = document.createElement('div');
        dica.className = 'chatin-audio-hint';
        dica.setAttribute('role', 'status');
        dica.textContent = 'Clique em qualquer lugar da página para ativar os sons do Chat-In.';
        document.body.appendChild(dica);
        setTimeout(() => dica.remove(), 7000);
    }
    function tocarSom(tipo, previsualizacao = false) {
        if (!S.config || !S.config.recurso_sons || !S.preferencias || (!previsualizacao && !S.preferencias.som_ativo)) return;
        if (Number(S.preferencias.som_volume) <= 0) return;
        const agora = Date.now();
        if (!previsualizacao && agora - S.ultimaNotificacaoSom < 2000) return;
        if (!previsualizacao) S.ultimaNotificacaoSom = agora;
        const ok = window.ChatInSons && window.ChatInSons.tocar(tipo || S.preferencias.som_tipo, S.preferencias.som_volume);
        if (!ok) mostrarDicaAudio();
    }
    function notificarNavegador(ev) {
        if (!S.preferencias || !S.preferencias.notificacao_navegador || !document.hidden || !('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            const n = new Notification(ev.remetente_nome || 'ERP Condomínio', {
                body: 'Nova mensagem', tag: `chatin-${Number(ev.conversa_id) || 0}`, renotify: false,
            });
            n.onclick = () => {
                try { window.focus(); } catch (_) { /* foco da janela pode ser recusado pelo navegador */ }
                abrir();
                const id = Number(ev.conversa_id) || 0;
                if (id) abrirConversaExistente(id);
                n.close();
            };
        } catch (_) { /* Notification API indisponível ou bloqueada pelo navegador */ }
    }
    function vibrarPainel() {
        if (!S.dock || S.embutido || !S.dock.isConnected) return;
        S.dock.classList.remove('chatin-cutucar-tremor');
        void S.dock.offsetWidth;
        S.dock.classList.add('chatin-cutucar-tremor');
        setTimeout(() => S.dock && S.dock.classList.remove('chatin-cutucar-tremor'), 650);
    }
    function atualizarCooldownCutucar() {
        if (S.cutucarTimer) clearTimeout(S.cutucarTimer);
        S.cutucarTimer = null;
        const conversaId = Number(S.conversa?.id || 0);
        if (!conversaId || !S.raiz) return;
        const chave = String(conversaId);
        const ate = Number(S.cutucarAtePorConversa[chave] || 0);
        const restante = Math.max(0, Math.ceil((ate - Date.now()) / 1000));
        const botao = S.raiz.querySelector('[data-ci="cutucar"]');
        const status = S.raiz.querySelector('.chatin-cutucar-status');
        if (botao) {
            botao.disabled = restante > 0;
            botao.title = restante > 0 ? `Aguarde ${restante} s para cutucar novamente` : 'Cutucar para chamar atenção';
            botao.setAttribute('aria-label', botao.title);
        }
        if (status) {
            status.hidden = restante <= 0;
            status.textContent = restante > 0 ? `Aguarde ${restante} s para enviar outra cutucada.` : '';
        }
        if (restante > 0) S.cutucarTimer = setTimeout(atualizarCooldownCutucar, 1000);
        else delete S.cutucarAtePorConversa[chave];
    }
    function processarEventos(novos) {
        if (!Array.isArray(novos) || !novos.length) return;
        const porId = new Map(S.conversas.map(c => [Number(c.id), c]));
        for (const ev of novos) {
            if (S.eu && ev.remetente_tipo === S.eu.tipo && Number(ev.remetente_id) === Number(S.eu.id)) continue;
            const c = porId.get(Number(ev.conversa_id));
            const cutucar = ev.tipo === 'CUTUCAR';
            if (ev.silenciado || (c && c.silenciado) || (cutucar && ev.permitir_cutucar === false)) continue;
            const aviso = ev.tipo === 'AVISO';
            const conversaVisivelEFocada = S.vista === 'conversa' && S.conversa && Number(S.conversa.id) === Number(ev.conversa_id) && !document.hidden && document.hasFocus();
            if (!conversaVisivelEFocada || cutucar) {
                tocarSom(cutucar ? 'cutucar' : (aviso ? 'suave' : S.preferencias?.som_tipo));
            }
            if (document.hidden) {
                piscarTitulo();
                notificarNavegador(ev);
            }
            if (cutucar) {
                if (!S.embutido) abrir();
                vibrarPainel();
                const conversa = S.conversas.find(item => Number(item.id) === Number(ev.conversa_id));
                if (conversa) abrirConversaExistente(Number(conversa.id));
            }
        }
    }

    function processarFallbackNaoLidas(contagensAntes, eventos) {
        const recebidosPorConversa = new Map();
        (Array.isArray(eventos) ? eventos : []).forEach(ev => {
            if (S.eu && ev.remetente_tipo === S.eu.tipo && Number(ev.remetente_id) === Number(S.eu.id)) return;
            if (ev.silenciado || ev.tipo === 'CUTUCAR' && ev.permitir_cutucar === false) return;
            const id = Number(ev.conversa_id) || 0;
            recebidosPorConversa.set(id, (recebidosPorConversa.get(id) || 0) + 1);
        });
        for (const conversa of S.conversas) {
            if (conversa.silenciado) continue;
            const id = Number(conversa.id) || 0;
            const anterior = Number(contagensAntes.get(id) || 0);
            const atual = Number(conversa.nao_lidas) || 0;
            const faltantes = atual - anterior - (recebidosPorConversa.get(id) || 0);
            if (faltantes <= 0) continue;
            const abertaComFoco = S.vista === 'conversa' && S.conversa && Number(S.conversa.id) === id && !document.hidden && document.hasFocus();
            if (!abertaComFoco) tocarSom(S.preferencias?.som_tipo);
            if (document.hidden) {
                piscarTitulo();
                const remetente = conversa.ultima?.remetente_nome || conversa.contato?.nome || 'Administração';
                notificarNavegador({ conversa_id: id, remetente_nome: remetente });
            }
            break;
        }
    }

    async function sincronizar() {
        if (S.bloqueado || S.sincronizando) return;
        S.sincronizando = true;
        const inicioSync = performance.now();
        try {
            const contagensAntes = new Map(S.conversas.map(c => [Number(c.id), Number(c.nao_lidas) || 0]));
            const params = { plataforma: S.plataforma, versao: S.versao };
            if (S.eventosCursor > 0) params.eventos_desde_id = S.eventosCursor;
            const conv = S.vista === 'conversa' && S.conversa && visivel() ? S.conversa : null;
            if (conv) {
                params.conversa_id = conv.id;
                params.apos_id = S.ultimoId;
            }
            const d = await api('sync', { params });
            S.eu = d.eu || S.eu;
            S.versao = d.versao || S.versao;
            S.config = d.config || S.config;
            S.preferencias = d.preferencias || S.preferencias;
            if (Array.isArray(d.conversas)) {
                S.conversas = d.conversas;
                if (S.vista === 'conversas') renderLista();
            }
            if (d.eventos_cursor !== undefined) S.eventosCursor = Math.max(S.eventosCursor, Number(d.eventos_cursor) || 0);
            atualizarContador(d.total_nao_lidas || 0);
            processarEventos(d.eventos_novos);
            if (S.syncInicializado) processarFallbackNaoLidas(contagensAntes, d.eventos_novos);
            S.syncInicializado = true;
            if (conv && S.conversa && S.conversa.id === conv.id) {
                if (Array.isArray(d.mensagens) && d.mensagens.length) anexarMensagens(d.mensagens);
                const atual = S.conversas.find(item => Number(item.id) === Number(conv.id));
                if (!document.hidden && document.hasFocus() && atual && Number(atual.nao_lidas) > 0) {
                    const naoLidasAntes = Number(atual.nao_lidas) || 0;
                    try {
                        await api('lida', { metodo: 'POST', corpo: { conversa_id: Number(conv.id), ate_id: Number(S.ultimoId) || 0 } });
                        atual.nao_lidas = 0;
                        atualizarContador(Math.max(0, Number(S.totalNaoLidas) - naoLidasAntes));
                    } catch (e) { console.warn('[Chat-In] leitura não confirmada:', e.message); }
                }
                if (S.conversa.contato && typeof d.contato_online === 'boolean' && S.conversa.contato.online !== d.contato_online) {
                    S.conversa.contato.online = d.contato_online;
                    atualizarCabecalhoConversa();
                }
                const digitando = Boolean(d.contato_digitando);
                if (digitando !== S.contatoDigitando) { S.contatoDigitando = digitando; atualizarIndicadorDigitando(); }
            }
        } catch (e) {
            if (e.status === 401 || e.status === 403 || e.codigo === 'NAO_INSTALADO') {
                desativar(e.codigo === 'NAO_INSTALADO' ? 'O Chat-In ainda não foi instalado neste servidor.' : 'Chat-In indisponível para esta sessão.');
                return;
            }
            console.warn('[Chat-In] sync falhou:', e.message);
        } finally {
            const duracao = Math.max(0, performance.now() - inicioSync);
            S.metricasSync.push(duracao);
            if (S.metricasSync.length > 100) S.metricasSync.shift();
            S.sincronizando = false;
            if (!S.bloqueado) {
                if (document.hidden) {
                    const notificacoesAtivas = S.preferencias?.notificacao_navegador && ('Notification' in window) && Notification.permission === 'granted';
                    if (notificacoesAtivas) agendar(INTERVALO_FECHADO, true);
                } else agendar(visivel() && S.vista === 'conversa' && document.hasFocus() ? INTERVALO_FOCO : (visivel() ? INTERVALO_ATIVO : INTERVALO_FECHADO));
            }
        }
    }

    function atualizarContador(total) {
        S.totalNaoLidas = total;
        if (S.launcher) {
            const b = S.launcher.querySelector('.chatin-badge');
            b.textContent = total > 99 ? '99+' : String(total);
            b.hidden = total <= 0;
            S.launcher.setAttribute('aria-label', total > 0 ? `Chat-In: ${total} mensagem(ns) não lida(s)` : 'Abrir Chat-In');
        }
        document.querySelectorAll('[data-chatin-contador]').forEach(el => {
            el.textContent = total > 99 ? '99+' : String(total);
            el.hidden = total <= 0;
        });
    }

    // ── estrutura ──────────────────────────────────────────────────
    function montarDock() {
        S.launcher = document.createElement('button');
        S.launcher.type = 'button';
        S.launcher.className = 'chatin-launcher';
        S.launcher.setAttribute('aria-label', 'Abrir Chat-In');
        S.launcher.title = 'Chat-In';
        S.launcher.innerHTML = '<i class="fas fa-comments"></i><span class="chatin-badge" hidden>0</span>';
        S.launcher.addEventListener('click', () => abrir());

        S.dock = document.createElement('aside');
        S.dock.className = 'chatin-dock';
        S.dock.hidden = true;
        S.dock.setAttribute('aria-label', 'Chat-In');
        S.dock.innerHTML = `
            <header class="chatin-dock-topo">
                <span class="chatin-marca"><i class="fas fa-comments"></i> Chat-In</span>
                <span class="chatin-dock-acoes">
                    <button type="button" class="chatin-icone-btn" data-ci="preferencias" title="Preferências" aria-label="Preferências do Chat-In"><i class="fas fa-sliders-h" aria-hidden="true"></i></button>
                    <button type="button" class="chatin-icone-btn" data-ci="fixar" title="Fixar painel" aria-label="Fixar painel do Chat-In"><i class="fas fa-thumbtack" aria-hidden="true"></i></button>
                    <button type="button" class="chatin-icone-btn" data-ci="minimizar" title="Minimizar" aria-label="Minimizar Chat-In"><i class="fas fa-minus" aria-hidden="true"></i></button>
                </span>
            </header>
            <div class="chatin-corpo"></div>`;
        document.body.appendChild(S.dock);
        document.body.appendChild(S.launcher);
        S.dock.querySelector('[data-ci="fixar"]').addEventListener('click', () => fixar(!S.fixado));
        S.dock.querySelector('[data-ci="minimizar"]').addEventListener('click', minimizar);
        S.dock.querySelector('[data-ci="preferencias"]').addEventListener('click', () => irPara('preferencias'));
        S.raiz = S.dock.querySelector('.chatin-corpo');
        ligarRaiz(S.raiz);
        S.dock.addEventListener('keydown', e => { if (e.key === 'Escape' && !S.fixado) minimizar(); });
    }

    function abrir() {
        if (S.bloqueado || S.embutido) return;
        S.aberto = true;
        S.dock.hidden = false;
        S.launcher.hidden = true;
        requestAnimationFrame(() => S.dock.classList.add('aberto'));
        if (S.vista === 'conversas') renderLista();
        sincronizar();
    }
    function minimizar() {
        pararDigitando();
        S.aberto = false;
        fixar(false);   // minimizar também desafixa (preferência lembrada neste navegador)
        S.dock.classList.remove('aberto');
        S.dock.hidden = true;
        S.launcher.hidden = false;
        agendar(INTERVALO_FECHADO);
    }
    function fixar(valor, silencioso) {
        S.fixado = Boolean(valor);
        document.body.classList.toggle('chatin-fixado', S.fixado && S.aberto);
        if (S.dock) {
            const btn = S.dock.querySelector('[data-ci="fixar"]');
            btn.classList.toggle('ativo', S.fixado);
            btn.title = S.fixado ? 'Desafixar painel' : 'Fixar painel';
            btn.setAttribute('aria-label', S.fixado ? 'Desafixar painel do Chat-In' : 'Fixar painel do Chat-In');
            S.dock.classList.toggle('fixado', S.fixado);
        }
        if (!silencioso) lsSet(CHAVE_FIXADO, S.fixado ? '1' : '0');
    }

    /** Delegação de eventos de um container de UI (dock ou página). */
    function ligarRaiz(raiz) {
        raiz.addEventListener('click', aoClicar);
        raiz.addEventListener('input', aoDigitar);
        raiz.addEventListener('keydown', aoTeclar);
        raiz.addEventListener('change', aoAlterar);
        raiz.addEventListener('scroll', aoRolar, true);
    }

    function irPara(vista) {
        if (S.vista === 'conversa' && vista !== 'conversa') pararDigitando();
        S.vista = vista;
        if (vista !== 'conversa') { S.conversa = null; S.mensagens = []; S.ultimoId = 0; }
        if (vista === 'conversas') renderLista();
        else if (vista === 'contatos') renderContatos();
        else if (vista === 'preferencias') renderPreferencias();
    }

    function abas(ativa) {
        return `<nav class="chatin-abas" aria-label="Navegação do Chat-In">
            <button type="button" aria-current="${ativa === 'conversas' ? 'page' : 'false'}" class="${ativa === 'conversas' ? 'ativa' : ''}" data-ci="aba-conversas"><i class="fas fa-comment-dots" aria-hidden="true"></i> Conversas</button>
            <button type="button" aria-current="${ativa === 'contatos' ? 'page' : 'false'}" class="${ativa === 'contatos' ? 'ativa' : ''}" data-ci="aba-contatos"><i class="fas fa-user-plus" aria-hidden="true"></i> Nova conversa</button>
        </nav>`;
    }

    // ── lista de conversas ─────────────────────────────────────────
    function renderLista() {
        if (!S.raiz) return;
        const itens = S.conversas.map(c => {
            const online = c.contato && c.contato.online;
            const prev = c.ultima ? (c.ultima.minha ? 'Você: ' : '') + c.ultima.corpo : '';
            return `<button type="button" class="chatin-item ${c.nao_lidas ? 'nao-lida' : ''}" data-ci="abrir-conversa" data-id="${c.id}">
                ${c.tipo === 'AVISOS' ? avatarAvisos() : avatar(c.contato, online)}
                <span class="chatin-item-texto">
                    <span class="chatin-item-linha"><strong>${esc(c.titulo)}</strong><small>${esc(c.ultima ? quandoCurto(c.ultima.em) : '')}</small></span>
                    <span class="chatin-item-linha"><span class="chatin-sub">${esc(c.subtitulo)}${c.contato && c.contato.bloqueado_por_mim ? ' · bloqueado' : ''}</span></span>
                    <span class="chatin-item-linha"><span class="chatin-previa">${esc(prev)}</span>${c.nao_lidas ? `<span class="chatin-pill">${c.nao_lidas > 99 ? '99+' : c.nao_lidas}</span>` : ''}</span>
                </span>
            </button>`;
        }).join('');
        S.raiz.innerHTML = abas('conversas') + `<div class="chatin-lista" role="list">${itens || `
            <div class="chatin-vazio"><i class="far fa-comments"></i><p>Nenhuma conversa ainda.</p>
            <button type="button" class="chatin-btn" data-ci="aba-contatos"><i class="fas fa-search"></i> Encontrar moradores e administração</button></div>`}</div>`;
    }

    // ── contatos ───────────────────────────────────────────────────
    function renderContatos(manterBusca) {
        const q = manterBusca ? (S.raiz.querySelector('.chatin-busca input')?.value || '') : '';
        S.raiz.innerHTML = abas('contatos') + `
            <div class="chatin-busca"><i class="fas fa-search"></i>
                <input type="search" placeholder="Buscar por nome ou unidade" data-ci="busca" value="${esc(q)}" autocomplete="off" maxlength="60" aria-label="Buscar contatos"></div>
            <div class="chatin-lista" data-ci="lista-contatos"><div class="chatin-carregando"><i class="fas fa-spinner fa-spin"></i></div></div>`;
        const inp = S.raiz.querySelector('[data-ci="busca"]');
        if (inp && !S.embutido) setTimeout(() => inp.focus(), 50);
        buscarContatos(q);
    }
    async function buscarContatos(q) {
        const alvo = S.raiz && S.raiz.querySelector('[data-ci="lista-contatos"]');
        if (!alvo) return;
        try {
            const d = await api('contatos', { params: { q } });
            if (S.vista !== 'contatos') return;
            S.contatos = d.contatos || [];
            alvo.innerHTML = S.contatos.length ? S.contatos.map((p, i) => `
                <button type="button" class="chatin-item" data-ci="abrir-contato" data-idx="${i}">
                    ${avatar(p, p.online)}
                    <span class="chatin-item-texto">
                        <span class="chatin-item-linha"><strong>${esc(p.nome)}</strong>${p.online ? '<small class="chatin-online-txt">online</small>' : ''}</span>
                        <span class="chatin-item-linha"><span class="chatin-sub">${esc(p.subtitulo)}</span></span>
                    </span>
                </button>`).join('') : `<div class="chatin-vazio"><i class="fas fa-user-slash"></i><p>Nenhum contato encontrado${q ? ' para "' + esc(q) + '"' : ''}.</p></div>`;
        } catch (e) {
            alvo.innerHTML = `<div class="chatin-vazio chatin-erro"><i class="fas fa-exclamation-circle"></i><p>${esc(e.message)}</p></div>`;
        }
    }

    // ── conversa ───────────────────────────────────────────────────
    async function abrirConversaExistente(id) {
        const c = S.conversas.find(x => x.id === id);
        if (!c) return;
        prepararConversa(c);
        try {
            const d = await api('mensagens', { params: { conversa_id: id, limite: 40 } });
            if (!S.conversa || S.conversa.id !== id) return;
            S.mensagens = d.mensagens || [];
            S.temMais = Boolean(d.tem_mais);
            S.ultimoId = S.mensagens.length ? S.mensagens[S.mensagens.length - 1].id : 0;
            renderMensagens(true);
            sincronizar();   // marca como lida e atualiza contador
        } catch (e) { mostrarErroConversa(e.message); }
    }

    async function abrirComContato(tipo, id) {
        if (S.bloqueado) return;
        if (!S.embutido && !S.aberto) abrir();
        prepararConversa({ id: 0, tipo: 'DIRETA', titulo: 'Abrindo…', subtitulo: '', contato: null, somente_leitura: false });
        try {
            const d = await api('abrir', { metodo: 'POST', corpo: { tipo, id } });
            prepararConversa(d.conversa);
            S.mensagens = d.mensagens || [];
            S.temMais = S.mensagens.length >= 40;
            S.ultimoId = S.mensagens.length ? S.mensagens[S.mensagens.length - 1].id : 0;
            renderMensagens(true);
            S.versao = '';
            sincronizar();
        } catch (e) { mostrarErroConversa(e.message); }
    }

    function prepararConversa(c) {
        pararDigitando();
        S.contatoDigitando = false;
        S.vista = 'conversa';
        S.conversa = c; S.mensagens = []; S.ultimoId = 0; S.temMais = false;
        const leitura = c.somente_leitura;
        const bloqueado = c.contato && (c.contato.bloqueado_por_mim || c.contato.ativo === false);
        S.raiz.innerHTML = `
            <div class="chatin-conversa-topo">
                <button type="button" class="chatin-icone-btn" data-ci="voltar" title="Voltar" aria-label="Voltar à lista de conversas"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
                <div class="chatin-conversa-id"></div>
                ${c.tipo === 'DIRETA' && !leitura && !bloqueado && S.config?.recurso_cutucar ? '<button type="button" class="chatin-icone-btn chatin-cutucar-btn" data-ci="cutucar" title="Cutucar para chamar atenção" aria-label="Cutucar para chamar atenção"><i class="fas fa-hand-point-right"></i></button>' : ''}
                ${c.tipo === 'DIRETA' && c.contato ? `<button type="button" class="chatin-icone-btn" data-ci="menu-conversa" title="Opções" aria-label="Opções da conversa"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
                <div class="chatin-menu" hidden>
                    <button type="button" data-ci="bloquear">${c.contato.bloqueado_por_mim ? '<i class="fas fa-unlock"></i> Desbloquear contato' : '<i class="fas fa-ban"></i> Bloquear contato'}</button>
                    <button type="button" data-ci="arquivar"><i class="fas fa-archive"></i> Arquivar conversa</button>
                </div>` : ''}
            </div>
            <p class="chatin-cutucar-status" role="status" aria-live="polite" hidden></p>
            <div class="chatin-mensagens" data-ci="mensagens" aria-live="polite"><div class="chatin-carregando"><i class="fas fa-spinner fa-spin"></i></div></div>
            ${c.tipo === 'AVISOS' ? '<div class="chatin-somente-leitura"><i class="fas fa-info-circle"></i> Canal de avisos da portaria e administração — somente leitura.</div>'
                : leitura ? `<div class="chatin-somente-leitura"><i class="fas fa-info-circle"></i> ${esc(c.avisos_leitura?.[0] || 'A configuração atual do condomínio deixou esta conversa somente para leitura.')}</div>`
                : bloqueado ? `<div class="chatin-somente-leitura"><i class="fas fa-ban"></i> ${c.contato.ativo === false ? 'Este contato não está mais disponível.' : 'Você bloqueou este contato. Desbloqueie para enviar mensagens.'}</div>`
                : `<form class="chatin-composer" data-ci="composer" autocomplete="off">
                    ${S.config?.recurso_emojis ? `<div class="chatin-emojis" hidden>${EMOJIS.map(em => `<button type="button" data-ci="emoji" data-emoji="${em}">${em}</button>`).join('')}</div>
                    <button type="button" class="chatin-icone-btn" data-ci="toggle-emojis" title="Emojis" aria-label="Abrir emojis"><i class="far fa-smile"></i></button>` : ''}
                    <textarea rows="1" maxlength="${MAX_CARACTERES}" placeholder="Escreva uma mensagem…" data-ci="texto" aria-label="Mensagem"></textarea>
                    <button type="submit" class="chatin-enviar" title="Enviar (Enter)" aria-label="Enviar mensagem"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
                </form>`}`;
        atualizarCabecalhoConversa();
        atualizarCooldownCutucar();
    }

    function atualizarCabecalhoConversa() {
        const alvo = S.raiz && S.raiz.querySelector('.chatin-conversa-id');
        if (!alvo || !S.conversa) return;
        const c = S.conversa;
        const online = c.contato && c.contato.online;
        alvo.innerHTML = `${c.tipo === 'AVISOS' ? avatarAvisos() : (c.contato ? avatar(c.contato, online) : '')}
            <span><strong>${esc(c.titulo)}</strong><small>${online ? '<span class="chatin-online-txt">online</span> · ' : ''}${esc(c.subtitulo || '')}</small></span>`;
    }

    function mostrarErroConversa(msg) {
        const alvo = S.raiz && S.raiz.querySelector('[data-ci="mensagens"]');
        if (alvo) alvo.innerHTML = `<div class="chatin-vazio chatin-erro"><i class="fas fa-exclamation-circle"></i><p>${esc(msg)}</p></div>`;
    }

    function htmlMensagem(m, anterior) {
        let sep = '';
        if (!anterior || rotuloDia(anterior.criado_em) !== rotuloDia(m.criado_em)) sep = `<div class="chatin-dia"><span>${esc(rotuloDia(m.criado_em))}</span></div>`;
        if (m.tipo === 'AVISO') {
            return sep + `<div class="chatin-aviso" data-mid="${m.id}">
                <div class="chatin-aviso-topo"><i class="fas fa-bullhorn"></i> ${esc(m.remetente.nome)}<small>${esc(hora(m.criado_em))}</small></div>
                ${m.titulo ? `<strong>${esc(m.titulo)}</strong>` : ''}<p>${esc(m.corpo)}</p></div>`;
        }
        if (m.tipo === 'CUTUCAR') {
            const texto = m.minha ? `Você cutucou ${S.conversa?.contato?.nome || 'o contato'}` : `${m.remetente?.nome || 'Contato'} cutucou você`;
            return sep + `<div class="chatin-msg-cutucar ${m.minha ? 'minha' : ''}" data-mid="${esc(m.id)}" role="status">
                <i class="fas fa-hand-point-right" aria-hidden="true"></i><span>${esc(texto)}</span><small>${esc(hora(m.criado_em))}</small></div>`;
        }
        const mostrarNome = !m.minha && S.conversa && S.conversa.tipo !== 'DIRETA';
        return sep + `<div class="chatin-msg ${m.minha ? 'minha' : ''} ${m.pendente ? 'pendente' : ''} ${m.falhou ? 'falhou' : ''}" data-mid="${esc(m.id)}">
            ${mostrarNome ? `<small class="chatin-msg-autor">${esc(m.remetente.nome)}</small>` : ''}
            <p>${esc(m.corpo)}</p>
            <small class="chatin-msg-hora">${m.falhou ? '<i class="fas fa-exclamation-circle"></i> não enviada · <a href="#" data-ci="reenviar" data-mid="' + esc(m.id) + '">tentar de novo</a>' : (m.pendente ? '<i class="far fa-clock"></i>' : esc(hora(m.criado_em)))}</small>
        </div>`;
    }

    function renderMensagens(rolarFim) {
        const alvo = S.raiz && S.raiz.querySelector('[data-ci="mensagens"]');
        if (!alvo) return;
        const html = S.mensagens.map((m, i) => htmlMensagem(m, S.mensagens[i - 1])).join('');
        alvo.innerHTML = (S.temMais ? '<button type="button" class="chatin-mais" data-ci="mais">Carregar mensagens anteriores</button>' : '')
            + (html || `<div class="chatin-vazio"><i class="far fa-paper-plane"></i><p>${S.conversa && S.conversa.somente_leitura ? 'Nenhum aviso por enquanto.' : 'Diga olá! As mensagens ficam guardadas no histórico.'}</p></div>`);
        atualizarIndicadorDigitando();
        if (rolarFim) alvo.scrollTop = alvo.scrollHeight;
    }

    function anexarMensagens(novas) {
        const alvo = S.raiz && S.raiz.querySelector('[data-ci="mensagens"]');
        const noFim = alvo ? alvo.scrollHeight - alvo.scrollTop - alvo.clientHeight < 80 : true;
        const ids = new Set(S.mensagens.map(m => String(m.id)));
        novas.forEach(m => { if (!ids.has(String(m.id))) S.mensagens.push(m); });
        S.ultimoId = Math.max(S.ultimoId, ...novas.map(m => Number(m.id) || 0));
        renderMensagens(noFim);
    }

    async function carregarAnteriores() {
        if (!S.conversa || !S.mensagens.length) return;
        const primeiro = S.mensagens.find(m => typeof m.id === 'number');
        if (!primeiro) return;
        const alvo = S.raiz.querySelector('[data-ci="mensagens"]');
        const alturaAntes = alvo.scrollHeight;
        try {
            const d = await api('mensagens', { params: { conversa_id: S.conversa.id, antes_id: primeiro.id, limite: 40 } });
            S.temMais = Boolean(d.tem_mais);
            S.mensagens = (d.mensagens || []).concat(S.mensagens);
            renderMensagens(false);
            alvo.scrollTop = alvo.scrollHeight - alturaAntes;
        } catch (e) { console.warn('[Chat-In]', e.message); }
    }

    async function enviar(texto, chaveExistente) {
        texto = String(texto || '').trim();
        if (!texto || !S.conversa || !S.conversa.id) return;
        if (texto.length > MAX_CARACTERES) return alert(`A mensagem pode ter até ${MAX_CARACTERES} caracteres.`);
        const chave = chaveExistente || uuid();
        let local = S.mensagens.find(m => m.id === chave);
        if (!local) {
            local = { id: chave, tipo: 'TEXTO', minha: true, pendente: true, corpo: texto, criado_em: new Date().toISOString(), remetente: S.eu || { nome: 'Você' } };
            S.mensagens.push(local);
        } else { local.pendente = true; local.falhou = false; }
        renderMensagens(true);
        const conversaId = S.conversa.id;
        try {
            const d = await api('enviar', { metodo: 'POST', corpo: { conversa_id: conversaId, corpo: texto, idempotency_key: chave } });
            if (!S.conversa || S.conversa.id !== conversaId) return;
            const idx = S.mensagens.indexOf(local);
            const real = d.mensagem;
            if (S.mensagens.some(m => m.id === real.id)) S.mensagens.splice(idx, 1);
            else S.mensagens[idx] = real;
            S.ultimoId = Math.max(S.ultimoId, real.id);
            renderMensagens(true);
            S.versao = '';
        } catch (e) {
            local.pendente = false; local.falhou = true; local.erro = e.message;
            renderMensagens(true);
            if (e.status && e.status !== 0 && e.status < 500) mostrarAvisoComposer(e.message);
        }
    }

    function mostrarAvisoComposer(msg) {
        const form = S.raiz.querySelector('[data-ci="composer"]');
        if (!form) return;
        let el = form.querySelector('.chatin-composer-aviso');
        if (!el) { el = document.createElement('div'); el.className = 'chatin-composer-aviso'; form.prepend(el); }
        el.textContent = msg;
        setTimeout(() => el.remove(), 5000);
    }

    // ── preferências ───────────────────────────────────────────────
    async function renderPreferencias() {
        S.raiz.innerHTML = `<div class="chatin-conversa-topo">
                <button type="button" class="chatin-icone-btn" data-ci="voltar" title="Voltar" aria-label="Voltar à lista de conversas"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
                <div class="chatin-conversa-id"><span><strong>Preferências</strong><small>Privacidade e notificações</small></span></div></div>
            <div class="chatin-prefs"><div class="chatin-carregando"><i class="fas fa-spinner fa-spin"></i></div></div>`;
        try {
            const p = await api('preferencias');
            S.preferencias = p;
            const volume = Number.isFinite(Number(p.som_volume)) ? Math.max(0, Math.min(100, Number(p.som_volume))) : 70;
            const morador = S.eu ? S.eu.tipo === 'MORADOR' : S.plataforma !== 'painel';
            const item = (chave, titulo, desc) => `<label class="chatin-pref">
                <input type="checkbox" data-pref="${chave}" ${p[chave] ? 'checked' : ''}>
                <span><strong>${titulo}</strong><small>${desc}</small></span></label>`;
            const som = S.config?.recurso_sons ? `<section class="chatin-prefs-som" aria-labelledby="chatin-som-titulo">
                ${item('som_ativo', 'Avisos sonoros', 'Tocar um som original quando chegar mensagem, respeitando conversas silenciadas.')}
                <label class="chatin-pref chatin-pref-controle"><span><strong>Som padrão</strong><small>Escolha um dos três sons originais do Chat-In.</small></span>
                    <select data-pref="som_tipo" aria-label="Tipo de som">
                        <option value="classico" ${p.som_tipo === 'classico' ? 'selected' : ''}>Clássico</option>
                        <option value="campainha" ${p.som_tipo === 'campainha' ? 'selected' : ''}>Campainha</option>
                        <option value="suave" ${p.som_tipo === 'suave' ? 'selected' : ''}>Suave</option>
                    </select>
                </label>
                <div class="chatin-pref-previews">
                    <button type="button" class="chatin-preview-som" data-ci="ouvir-som" data-som="classico">Ouvir Clássico</button>
                    <button type="button" class="chatin-preview-som" data-ci="ouvir-som" data-som="campainha">Ouvir Campainha</button>
                    <button type="button" class="chatin-preview-som" data-ci="ouvir-som" data-som="suave">Ouvir Suave</button>
                </div>
                <label class="chatin-pref chatin-pref-controle"><span><strong>Volume</strong><small data-volume-label>${volume}%</small></span><input type="range" min="0" max="100" step="5" value="${volume}" data-pref="som_volume" aria-label="Volume do Chat-In"></label>
            </section>` : '';
            S.raiz.querySelector('.chatin-prefs').innerHTML =
                (morador ? item('visivel_busca', 'Aparecer na busca de moradores', 'Desligado: outros moradores não encontram você. A administração continua podendo falar com você.') : '')
                + item('mostrar_online', 'Mostrar quando estou online', 'Desligado: ninguém vê seu status online.')
                + (morador && S.config?.recurso_push_app ? item('notificar_push', 'Notificações no aplicativo', 'Receber push no celular quando chegar mensagem.') : '')
                + item('notificacao_navegador', 'Notificações do navegador', 'Quando a aba estiver oculta, mostrar o nome do remetente e “Nova mensagem”, sem o conteúdo.')
                + (S.config?.recurso_cutucar ? item('permitir_cutucar', 'Permitir Cutucar', 'Permite que participantes desta conversa enviem um sinal temporário para chamar sua atenção.') : '')
                + som
                + '<p class="chatin-prefs-nota"><i class="fas fa-shield-alt"></i> O Chat-In mostra apenas nome e unidade/função. Você pode bloquear qualquer contato pelo menu da conversa. Os sons são gerados localmente e nunca enviados ao servidor.</p>';
        } catch (e) {
            S.raiz.querySelector('.chatin-prefs').innerHTML = `<div class="chatin-vazio chatin-erro"><p>${esc(e.message)}</p></div>`;
        }
    }

    // ── eventos de UI ──────────────────────────────────────────────
    function aoClicar(e) {
        const el = e.target.closest('[data-ci]');
        if (!el) {
            const menu = S.raiz.querySelector('.chatin-menu');
            if (menu && !menu.hidden && !e.target.closest('.chatin-menu')) menu.hidden = true;
            return;
        }
        const acao = el.dataset.ci;
        if (acao === 'aba-conversas') { irPara('conversas'); S.versao = ''; sincronizar(); }
        else if (acao === 'aba-contatos') irPara('contatos');
        else if (acao === 'abrir-conversa') abrirConversaExistente(Number(el.dataset.id));
        else if (acao === 'abrir-contato') { const p = S.contatos[Number(el.dataset.idx)]; if (p) abrirComContato(p.tipo, p.id); }
        else if (acao === 'voltar') { irPara('conversas'); S.versao = ''; sincronizar(); }
        else if (acao === 'mais') carregarAnteriores();
        else if (acao === 'cutucar') { e.preventDefault(); enviarCutucada(el); }
        else if (acao === 'ouvir-som') { e.preventDefault(); tocarSom(el.dataset.som || S.preferencias?.som_tipo, true); }
        else if (acao === 'toggle-emojis') { const p = S.raiz.querySelector('.chatin-emojis'); if (p) p.hidden = !p.hidden; }
        else if (acao === 'emoji') {
            const ta = S.raiz.querySelector('[data-ci="texto"]');
            if (ta) {
                const ini = ta.selectionStart ?? ta.value.length;
                ta.value = ta.value.slice(0, ini) + el.dataset.emoji + ta.value.slice(ta.selectionEnd ?? ini);
                ta.focus(); ta.selectionStart = ta.selectionEnd = ini + el.dataset.emoji.length;
            }
        }
        else if (acao === 'menu-conversa') { const m = S.raiz.querySelector('.chatin-menu'); if (m) m.hidden = !m.hidden; }
        else if (acao === 'bloquear') alternarBloqueio();
        else if (acao === 'arquivar') arquivarConversa();
        else if (acao === 'reenviar') {
            e.preventDefault();
            const m = S.mensagens.find(x => String(x.id) === el.dataset.mid);
            if (m) enviar(m.corpo, m.id);
        }
    }
    function aoDigitar(e) {
        if (e.target.matches('[data-ci="busca"]')) {
            clearTimeout(S.buscaTimer);
            const q = e.target.value.trim();
            S.buscaTimer = setTimeout(() => buscarContatos(q), 300);
        } else if (e.target.matches('[data-pref="som_volume"]')) {
            const label = S.raiz.querySelector('[data-volume-label]');
            if (label) label.textContent = `${Math.max(0, Math.min(100, Number(e.target.value) || 0))}%`;
        } else if (e.target.matches('[data-ci="texto"]')) {
            e.target.style.height = 'auto';
            e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
            if (e.target.value.trim()) enviarDigitando(); else pararDigitando();
        }
    }
    function aoTeclar(e) {
        if (e.target.matches('[data-ci="texto"]') && e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            submeter(e.target.closest('form'));
        }
    }
    function aoAlterar(e) {
        const chave = e.target.dataset.pref;
        if (!chave) return;
        salvarPreferencia(chave, e.target);
    }

    async function salvarPreferencia(chave, elemento) {
        const anterior = S.preferencias ? S.preferencias[chave] : undefined;
        let valor = elemento.type === 'checkbox' ? elemento.checked : (elemento.type === 'range' ? Number(elemento.value) : elemento.value);
        if (chave === 'notificacao_navegador' && valor) {
            if (!('Notification' in window)) {
                elemento.checked = false;
                alert('Este navegador não oferece notificações do sistema.');
                return;
            }
            if (Notification.permission === 'default') {
                try { await Notification.requestPermission(); } catch (_) { /* permissão pode ser bloqueada pelo navegador */ }
            }
            if (Notification.permission !== 'granted') {
                elemento.checked = false;
                alert('A permissão de notificação não foi concedida. Você pode ativá-la nas configurações do navegador.');
                return;
            }
        }
        const corpo = { [chave]: valor };
        api('preferencias', { metodo: 'POST', corpo }).then(p => {
            S.preferencias = p;
            if (chave === 'mostrar_online' && !p.mostrar_online) pararDigitando();
        }).catch(err => {
            if (elemento.type === 'checkbox') elemento.checked = Boolean(anterior);
            else if (anterior !== undefined) elemento.value = String(anterior);
            alert(err.message);
        });
    }

    async function enviarCutucada(botao) {
        if (!S.conversa || !S.conversa.id || S.conversa.tipo !== 'DIRETA' || S.conversa.somente_leitura) return;
        const conversaId = Number(S.conversa.id);
        if (botao) botao.disabled = true;
        try {
            const d = await api('cutucar', { metodo: 'POST', corpo: { conversa_id: conversaId } });
            if (d.mensagem && Number(S.conversa?.id) === conversaId) {
                S.mensagens.push(d.mensagem);
                S.ultimoId = Math.max(S.ultimoId, Number(d.mensagem.id) || 0);
                renderMensagens(true);
            }
            S.cutucarAtePorConversa[String(conversaId)] = Date.now() + 30000;
            atualizarCooldownCutucar();
            S.versao = '';
        } catch (e) {
            if (e.codigo === 'CUTUCAR_LIMITE' && e.retryAfter > 0) {
                S.cutucarAtePorConversa[String(conversaId)] = Date.now() + e.retryAfter * 1000;
                atualizarCooldownCutucar();
            } else {
                const form = S.raiz && S.raiz.querySelector('[data-ci="composer"]');
                if (form) mostrarAvisoComposer(e.message); else alert(e.message);
            }
        } finally {
            if (botao && botao.isConnected && Number(S.conversa?.id) === conversaId
                && !(S.cutucarAtePorConversa[String(conversaId)] > Date.now())) botao.disabled = false;
        }
    }
    function aoRolar(e) {
        if (e.target.matches && e.target.matches('[data-ci="mensagens"]') && e.target.scrollTop < 20 && S.temMais && !S._carregandoMais) {
            S._carregandoMais = true;
            carregarAnteriores().finally(() => { S._carregandoMais = false; });
        }
    }
    document.addEventListener('submit', e => {
        if (e.target.matches && e.target.matches('.chatin-composer')) { e.preventDefault(); submeter(e.target); }
    });
    function submeter(form) {
        const ta = form && form.querySelector('[data-ci="texto"]');
        if (!ta) return;
        const texto = ta.value;
        ta.value = ''; ta.style.height = 'auto';
        pararDigitando();
        const emo = form.querySelector('.chatin-emojis'); if (emo) emo.hidden = true;
        enviar(texto);
    }

    async function alternarBloqueio() {
        const c = S.conversa; if (!c || !c.contato) return;
        const bloquear = !c.contato.bloqueado_por_mim;
        if (bloquear && !confirm(`Bloquear ${c.contato.nome}? Vocês não poderão trocar mensagens e ${c.contato.nome.split(' ')[0]} deixará de ver você na busca.`)) return;
        try {
            await api('bloquear', { metodo: 'POST', corpo: { tipo: c.contato.tipo, id: c.contato.id, bloquear } });
            c.contato.bloqueado_por_mim = bloquear;
            const msgs = S.mensagens, ult = S.ultimoId, mais = S.temMais;
            prepararConversa(c);
            S.mensagens = msgs; S.ultimoId = ult; S.temMais = mais;
            renderMensagens(true);
            S.versao = '';
        } catch (e) { alert(e.message); }
    }
    async function arquivarConversa() {
        const c = S.conversa; if (!c) return;
        try {
            await api('arquivar', { metodo: 'POST', corpo: { conversa_id: c.id, arquivar: true } });
            irPara('conversas'); S.versao = ''; sincronizar();
        } catch (e) { alert(e.message); }
    }

    // ── ciclo de vida ──────────────────────────────────────────────
    document.addEventListener('visibilitychange', () => {
        if (S.bloqueado || !S.ativo) return;
        if (document.hidden) {
            pararPolling();
            const notificacoesAtivas = S.preferencias?.notificacao_navegador && ('Notification' in window) && Notification.permission === 'granted';
            if (notificacoesAtivas) agendar(INTERVALO_FECHADO, true);
        } else { pararTitulo(); sincronizar(); }
    });

    /**
     * Inicializa o Chat-In.
     * @param {object} opts { api?, plataforma: 'painel'|'portal', token?: string|function }
     */
    function iniciar(opts = {}) {
        if (S.ativo) return;
        S.ativo = true;
        if (opts.api) S.api = opts.api;
        S.plataforma = opts.plataforma || 'painel';
        S.token = typeof opts.token === 'function' ? opts.token() : (opts.token || null);
        montarDock();
        if (lsGet(CHAVE_FIXADO) === '1' && window.innerWidth >= 1100) { abrir(); fixar(true, true); }
        sincronizar();
    }

    /** Página "Chat-In" do painel: usa o mesmo componente embutido em um container. */
    function montarEmbutido(container) {
        if (!S.ativo || S.bloqueado || !container) return;
        if (S.aberto) {   // a página assume o chat; o painel lateral fecha sem mudar a preferência de fixar
            S.aberto = false;
            S.dock.classList.remove('aberto');
            S.dock.hidden = true;
            document.body.classList.remove('chatin-fixado');
        }
        S.embutido = true;
        if (S.launcher) S.launcher.hidden = true;
        container.classList.add('chatin-embutido');
        S.raiz = container;
        ligarRaiz(container);
        irPara('conversas');
        S.versao = '';
        sincronizar();
    }
    function desmontarEmbutido(container) {
        if (!S.embutido) return;
        pararDigitando();
        S.embutido = false;
        if (container) {
            container.removeEventListener('click', aoClicar);
            container.removeEventListener('input', aoDigitar);
            container.removeEventListener('keydown', aoTeclar);
            container.removeEventListener('change', aoAlterar);
            container.removeEventListener('scroll', aoRolar, true);
        }
        S.raiz = S.dock ? S.dock.querySelector('.chatin-corpo') : null;
        S.vista = 'conversas'; S.conversa = null;
        if (S.launcher && !S.bloqueado) S.launcher.hidden = false;
        agendar(INTERVALO_FECHADO);
    }

    function encerrar() {
        pararDigitando();
        pararPolling();
        pararTitulo();
        S.ativo = false; S.bloqueado = true;
        if (S.dock) S.dock.remove();
        if (S.launcher) S.launcher.remove();
        document.body.classList.remove('chatin-fixado');
    }

    window.ChatIn = {
        iniciar, abrir, minimizar, abrirComContato, montarEmbutido, desmontarEmbutido, encerrar,
        get totalNaoLidas() { return S.totalNaoLidas; },
        obterMetricasSync() {
            const amostras = S.metricasSync.slice();
            const total = amostras.reduce((soma, valor) => soma + valor, 0);
            return { amostras: amostras.length, media_ms: amostras.length ? Math.round(total / amostras.length) : null, maior_ms: amostras.length ? Math.round(Math.max(...amostras)) : null };
        },
    };
})();
