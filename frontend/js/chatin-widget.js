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
 * Tempo real: polling de ~5 s quando o chat está visível, ~20 s quando minimizado e
 * pausa com a aba oculta. Push no app é feito pelo servidor (FCM).
 * Privacidade: a API só devolve nome + unidade/função; nada de CPF, telefone ou e-mail.
 */
(function () {
    'use strict';
    if (window.ChatIn) return;

    const INTERVALO_ATIVO = 5000;
    const INTERVALO_FECHADO = 20000;
    const MAX_CARACTERES = 2000;
    const EMOJIS = ['😀', '😂', '😊', '😍', '🙏', '👍', '👏', '🎉', '😉', '😢', '😮', '🤔', '❤️', '✅', '🚗', '📦', '🏠', '🔑', '⚠️', '👋'];
    const CHAVE_FIXADO = 'chatin_fixado';

    const S = {
        api: '../api/api_chatin.php',
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
    function agendar(ms) {
        pararPolling();
        if (S.bloqueado || document.hidden) return;
        S.timer = setTimeout(sincronizar, ms);
    }
    function pararPolling() { if (S.timer) { clearTimeout(S.timer); S.timer = null; } }

    async function sincronizar() {
        if (S.bloqueado || S.sincronizando) return;
        S.sincronizando = true;
        try {
            const params = { plataforma: S.plataforma, versao: S.versao };
            const conv = S.vista === 'conversa' && S.conversa && visivel() ? S.conversa : null;
            if (conv) {
                params.conversa_id = conv.id;
                params.apos_id = S.ultimoId;
                if (!document.hidden) params.marcar_lida = 1;
            }
            const d = await api('sync', { params });
            S.eu = d.eu || S.eu;
            S.versao = d.versao || S.versao;
            if (Array.isArray(d.conversas)) {
                S.conversas = d.conversas;
                if (S.vista === 'conversas') renderLista();
            }
            atualizarContador(d.total_nao_lidas || 0);
            if (conv && S.conversa && S.conversa.id === conv.id) {
                if (Array.isArray(d.mensagens) && d.mensagens.length) anexarMensagens(d.mensagens);
                if (S.conversa.contato && typeof d.contato_online === 'boolean' && S.conversa.contato.online !== d.contato_online) {
                    S.conversa.contato.online = d.contato_online;
                    atualizarCabecalhoConversa();
                }
            }
        } catch (e) {
            if (e.status === 401 || e.status === 403 || e.codigo === 'NAO_INSTALADO') {
                desativar(e.codigo === 'NAO_INSTALADO' ? 'O Chat-In ainda não foi instalado neste servidor.' : 'Chat-In indisponível para esta sessão.');
                return;
            }
            console.warn('[Chat-In] sync falhou:', e.message);
        } finally {
            S.sincronizando = false;
            if (!S.bloqueado) agendar(visivel() ? INTERVALO_ATIVO : INTERVALO_FECHADO);
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
                    <button type="button" class="chatin-icone-btn" data-ci="preferencias" title="Preferências"><i class="fas fa-sliders-h"></i></button>
                    <button type="button" class="chatin-icone-btn" data-ci="fixar" title="Fixar painel"><i class="fas fa-thumbtack"></i></button>
                    <button type="button" class="chatin-icone-btn" data-ci="minimizar" title="Minimizar"><i class="fas fa-minus"></i></button>
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
        S.vista = vista;
        if (vista !== 'conversa') { S.conversa = null; S.mensagens = []; S.ultimoId = 0; }
        if (vista === 'conversas') renderLista();
        else if (vista === 'contatos') renderContatos();
        else if (vista === 'preferencias') renderPreferencias();
    }

    function abas(ativa) {
        return `<nav class="chatin-abas" role="tablist">
            <button type="button" role="tab" class="${ativa === 'conversas' ? 'ativa' : ''}" data-ci="aba-conversas"><i class="fas fa-comment-dots"></i> Conversas</button>
            <button type="button" role="tab" class="${ativa === 'contatos' ? 'ativa' : ''}" data-ci="aba-contatos"><i class="fas fa-user-plus"></i> Nova conversa</button>
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
        S.vista = 'conversa';
        S.conversa = c; S.mensagens = []; S.ultimoId = 0; S.temMais = false;
        const leitura = c.somente_leitura;
        const bloqueado = c.contato && (c.contato.bloqueado_por_mim || c.contato.ativo === false);
        S.raiz.innerHTML = `
            <div class="chatin-conversa-topo">
                <button type="button" class="chatin-icone-btn" data-ci="voltar" title="Voltar"><i class="fas fa-arrow-left"></i></button>
                <div class="chatin-conversa-id"></div>
                ${c.tipo === 'DIRETA' && c.contato ? `<button type="button" class="chatin-icone-btn" data-ci="menu-conversa" title="Opções"><i class="fas fa-ellipsis-v"></i></button>
                <div class="chatin-menu" hidden>
                    <button type="button" data-ci="bloquear">${c.contato.bloqueado_por_mim ? '<i class="fas fa-unlock"></i> Desbloquear contato' : '<i class="fas fa-ban"></i> Bloquear contato'}</button>
                    <button type="button" data-ci="arquivar"><i class="fas fa-archive"></i> Arquivar conversa</button>
                </div>` : ''}
            </div>
            <div class="chatin-mensagens" data-ci="mensagens" aria-live="polite"><div class="chatin-carregando"><i class="fas fa-spinner fa-spin"></i></div></div>
            ${leitura ? '<div class="chatin-somente-leitura"><i class="fas fa-info-circle"></i> Canal de avisos da portaria e administração — somente leitura.</div>'
                : bloqueado ? `<div class="chatin-somente-leitura"><i class="fas fa-ban"></i> ${c.contato.ativo === false ? 'Este contato não está mais disponível.' : 'Você bloqueou este contato. Desbloqueie para enviar mensagens.'}</div>`
                : `<form class="chatin-composer" data-ci="composer" autocomplete="off">
                    <div class="chatin-emojis" hidden>${EMOJIS.map(em => `<button type="button" data-ci="emoji" data-emoji="${em}">${em}</button>`).join('')}</div>
                    <button type="button" class="chatin-icone-btn" data-ci="toggle-emojis" title="Emojis"><i class="far fa-smile"></i></button>
                    <textarea rows="1" maxlength="${MAX_CARACTERES}" placeholder="Escreva uma mensagem…" data-ci="texto" aria-label="Mensagem"></textarea>
                    <button type="submit" class="chatin-enviar" title="Enviar (Enter)"><i class="fas fa-paper-plane"></i></button>
                </form>`}`;
        atualizarCabecalhoConversa();
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
                <button type="button" class="chatin-icone-btn" data-ci="voltar" title="Voltar"><i class="fas fa-arrow-left"></i></button>
                <div class="chatin-conversa-id"><span><strong>Preferências</strong><small>Privacidade e notificações</small></span></div></div>
            <div class="chatin-prefs"><div class="chatin-carregando"><i class="fas fa-spinner fa-spin"></i></div></div>`;
        try {
            const p = await api('preferencias');
            S.preferencias = p;
            const morador = S.eu ? S.eu.tipo === 'MORADOR' : S.plataforma !== 'painel';
            const item = (chave, titulo, desc) => `<label class="chatin-pref">
                <input type="checkbox" data-pref="${chave}" ${p[chave] ? 'checked' : ''}>
                <span><strong>${titulo}</strong><small>${desc}</small></span></label>`;
            S.raiz.querySelector('.chatin-prefs').innerHTML =
                (morador ? item('visivel_busca', 'Aparecer na busca de moradores', 'Desligado: outros moradores não encontram você. A administração continua podendo falar com você.') : '')
                + item('mostrar_online', 'Mostrar quando estou online', 'Desligado: ninguém vê seu status online.')
                + (morador ? item('notificar_push', 'Notificações no aplicativo', 'Receber push no celular quando chegar mensagem.') : '')
                + '<p class="chatin-prefs-nota"><i class="fas fa-shield-alt"></i> O Chat-In mostra apenas seu nome e unidade. Você pode bloquear qualquer contato pelo menu da conversa.</p>';
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
        } else if (e.target.matches('[data-ci="texto"]')) {
            e.target.style.height = 'auto';
            e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
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
        const corpo = { [chave]: e.target.checked };
        api('preferencias', { metodo: 'POST', corpo }).then(p => { S.preferencias = p; }).catch(err => {
            e.target.checked = !e.target.checked; alert(err.message);
        });
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
        if (document.hidden) pararPolling(); else sincronizar();
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
        pararPolling();
        S.ativo = false; S.bloqueado = true;
        if (S.dock) S.dock.remove();
        if (S.launcher) S.launcher.remove();
        document.body.classList.remove('chatin-fixado');
    }

    window.ChatIn = {
        iniciar, abrir, minimizar, abrirComContato, montarEmbutido, desmontarEmbutido, encerrar,
        get totalNaoLidas() { return S.totalNaoLidas; },
    };
})();
