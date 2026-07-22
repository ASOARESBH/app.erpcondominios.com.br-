/**
 * dispositivos.js — Módulo de Dispositivos de Acesso v4.0
 * Sistema ERP Condomínio
 *
 * Arquitetura multifabricante: Control iD, Intelbras, Hikvision, Genérico
 * ES Module com export init/destroy para AppRouter
 */

// ── Constantes ────────────────────────────────────────────────────────────────
const API = window.location.origin + '/api/api_dispositivos.php';

const FABRICANTES = {
    controlid: {
        nome:  'Control iD',
        icone: 'fas fa-microchip',
        cor:   '#2563eb',
        ajuda: 'Leitores UHF, RFID, facial e biométricos. Suporta integração via Bridge Local (script Python no PC da portaria) ou Monitor Nativo (equipamento envia eventos diretamente ao servidor).',
    },
    intelbras: {
        nome:  'Intelbras',
        icone: 'fas fa-door-open',
        cor:   '#16a34a',
        ajuda: 'Catracas, controladores de acesso e leitores Intelbras. Integração em desenvolvimento — cadastre o dispositivo para uso futuro.',
    },
    hikvision: {
        nome:  'Hikvision',
        icone: 'fas fa-video',
        cor:   '#dc2626',
        ajuda: 'Câmeras com reconhecimento facial e leitores de acesso Hikvision. Integração em desenvolvimento — cadastre o dispositivo para uso futuro.',
    },
    generico: {
        nome:  'Genérico',
        icone: 'fas fa-plug',
        cor:   '#7c3aed',
        ajuda: 'Dispositivos de outros fabricantes. Preencha os dados de conexão para referência futura.',
    },
};

// ── Estado do módulo ──────────────────────────────────────────────────────────
const _state = {
    fabricanteSelecionado: 'controlid',
    dispositivoEditandoId: null,
    tokenDispositivoId:    null,
    tokenAtual:            '',
    evPagina:              1,
    _listeners:            [],
};

// ── Lifecycle: init / destroy ─────────────────────────────────────────────────
export function init() {
    console.log('[Dispositivos] init() v4.0 — multifabricante');
    _setupTabs();
    _setupFabricantes();
    _setupFormulario();
    _setupEventosTab();
    _setupFilaTab();
    _setupModais();
    _carregarDispositivos();

    window.DispModule = {
        editarDispositivo:  _editarDispositivo,
        excluirDispositivo: _excluirDispositivo,
        verToken:           _verToken,
        cancelarComando:    _cancelarComando,
        verGuia:            _verGuia,
    };
}

export function destroy() {
    console.log('[Dispositivos] destroy()');
    _state._listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _state._listeners            = [];
    _state.fabricanteSelecionado = 'controlid';
    _state.dispositivoEditandoId = null;
    _state.tokenDispositivoId    = null;
    _state.evPagina              = 1;
    delete window.DispModule;
}

// ── Abas ──────────────────────────────────────────────────────────────────────
function _setupTabs() {
    document.querySelectorAll('.page-dispositivos .tab-btn').forEach(btn => {
        _on(btn, 'click', () => {
            document.querySelectorAll('.page-dispositivos .tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.page-dispositivos .tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.tab;
            const el  = _el('tab-' + tab);
            if (el) el.classList.add('active');
            if (tab === 'eventos') _carregarEventos();
            if (tab === 'fila')    _carregarFila();
        });
    });
}

// ── Seleção de Fabricante ─────────────────────────────────────────────────────
function _setupFabricantes() {
    document.querySelectorAll('.page-dispositivos .fabricante-card').forEach(card => {
        _on(card, 'click', () => _selecionarFabricante(card.dataset.fab));
    });
    // Inicializa com Control iD selecionado
    _selecionarFabricante('controlid');
}

function _selecionarFabricante(fab) {
    if (!FABRICANTES[fab]) return;
    _state.fabricanteSelecionado = fab;

    // Atualiza cards visuais
    document.querySelectorAll('.page-dispositivos .fabricante-card').forEach(c => {
        c.classList.toggle('active', c.dataset.fab === fab);
    });

    // Mostra/oculta blocos de campos por fabricante
    document.querySelectorAll('.page-dispositivos .fabricante-fields').forEach(f => {
        f.style.display = 'none';
    });
    const bloco = _el('fields-' + fab);
    if (bloco) bloco.style.display = 'block';

    // Atualiza hidden input
    _setVal('disp-fabricante', fab);

    // Exibe ajuda contextual
    const ajudaDiv = _el('fabricante-ajuda');
    const ajudaTxt = _el('fabricante-ajuda-texto');
    const info = FABRICANTES[fab];
    if (ajudaDiv && ajudaTxt && info.ajuda) {
        ajudaTxt.textContent = info.ajuda;
        ajudaDiv.style.display = 'flex';
    }

    // Atualiza label do serial conforme fabricante
    const labelSerial = _el('label-serial');
    if (labelSerial) {
        const labels = {
            controlid: 'Serial Number *',
            intelbras: 'Número de Série *',
            hikvision: 'Número de Série *',
            generico:  'Identificador / Serial *',
        };
        labelSerial.textContent = labels[fab] || 'Serial Number *';
    }

    // Para Control iD: atualiza bloco de rede conforme tipo de integração
    if (fab === 'controlid') {
        _atualizarBlocoRedeControlid();
    }
}

// ── Formulário ────────────────────────────────────────────────────────────────
function _setupFormulario() {
    _on(_el('btnDispSalvar'),   'click', _salvarDispositivo);
    _on(_el('btnDispCancelar'), 'click', _cancelarEdicao);
    _on(_el('btnDispBuscar'),   'click', _carregarDispositivos);
    _on(_el('disp-busca'), 'keydown', e => { if (e.key === 'Enter') _carregarDispositivos(); });

    // Toggle de rede Control iD
    document.querySelectorAll('input[name="tipo_integracao"]').forEach(radio => {
        _on(radio, 'change', _atualizarBlocoRedeControlid);
    });
    _atualizarBlocoRedeControlid();

    // Toggle senhas
    _setupToggleSenha('btnToggleSenha',         'disp-senha',        'disp-senha-icon');
    _setupToggleSenha('btnToggleSenhaIntelbras', 'intelbras-senha',   'intelbras-senha-icon');
    _setupToggleSenha('btnToggleSenhaHikvision', 'hikvision-senha',   'hikvision-senha-icon');
    _setupToggleSenha('btnToggleSenhaGenerico',  'generico-senha',    'generico-senha-icon');

    // Gerar Bridge Key
    _on(_el('btnGerarBridgeKey'), 'click', _gerarBridgeKey);
}

function _setupToggleSenha(btnId, inputId, iconId) {
    const btn = _el(btnId);
    if (!btn) return;
    _on(btn, 'click', () => {
        const inp  = _el(inputId);
        const icon = _el(iconId);
        if (!inp) return;
        if (inp.type === 'password') {
            inp.type = 'text';
            if (icon) { icon.classList.replace('fa-eye', 'fa-eye-slash'); }
        } else {
            inp.type = 'password';
            if (icon) { icon.classList.replace('fa-eye-slash', 'fa-eye'); }
        }
    });
}

function _atualizarBlocoRedeControlid() {
    const tipo  = document.querySelector('input[name="tipo_integracao"]:checked')?.value ?? 'bridge_local';
    const bloco = _el('bloco-rede-controlid');
    if (bloco) bloco.style.display = tipo === 'manual' ? 'none' : 'grid';

    // Destaque visual no radio selecionado
    document.querySelectorAll('.radio-card').forEach(card => {
        const inp = card.querySelector('input[type="radio"]');
        card.classList.toggle('selected', inp?.checked ?? false);
    });
}

function _gerarBridgeKey() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let key = 'bk_';
    for (let i = 0; i < 32; i++) key += chars.charAt(Math.floor(Math.random() * chars.length));
    _setVal('disp-bridge-key', key);
}

// ── CRUD: Carregar ────────────────────────────────────────────────────────────
async function _carregarDispositivos() {
    const busca = _get('disp-busca');
    const lista = _el('disp-lista');
    lista.className = 'loading-state';
    lista.innerHTML = '<i class="fas fa-spinner fa-spin"></i><p>Carregando dispositivos...</p>';

    const data = await _fetchJson(`${API}?acao=listar&busca=${encodeURIComponent(busca)}`);
    lista.className = '';

    if (!data?.sucesso) {
        lista.innerHTML = _emptyHTML('fa-exclamation-triangle', 'Erro ao carregar dispositivos. Verifique a conexão.');
        return;
    }

    const devs = data.dispositivos ?? [];

    // Atualiza KPIs
    _atualizarKPIs(devs);

    if (devs.length === 0) {
        lista.innerHTML = _emptyHTML('fa-microchip', 'Nenhum dispositivo cadastrado. Adicione o primeiro acima.');
        return;
    }

    lista.innerHTML = devs.map(d => _renderCard(d)).join('');

    // Popula selects de eventos e fila
    _popularSelectDispositivos(devs);
}

function _atualizarKPIs(devs) {
    const total      = devs.length;
    const online     = devs.filter(d => !!d.online).length;
    const fabricantes = new Set(devs.map(d => d.fabricante || 'controlid')).size;
    const bridge     = devs.filter(d => d.tipo_integracao === 'bridge_local').length;

    _setText('kpi-total',       String(total));
    _setText('kpi-online',      String(online));
    _setText('kpi-fabricantes', String(fabricantes));
    _setText('kpi-bridge',      String(bridge));
}

function _renderCard(d) {
    const fab      = d.fabricante || 'controlid';
    const fabInfo  = FABRICANTES[fab] || FABRICANTES.controlid;
    const online   = !!d.online;

    const tipoLabel  = { bridge_local: 'Bridge Local', monitor_nativo: 'Monitor Nativo', manual: 'Manual' }[d.tipo_integracao] ?? d.tipo_integracao ?? '—';
    const tipoIcon   = { bridge_local: 'fa-laptop-code', monitor_nativo: 'fa-satellite-dish', manual: 'fa-hand-paper' }[d.tipo_integracao] ?? 'fa-microchip';
    const leitorLabel = { uhf: 'UHF', rfid: 'RFID', facial: 'Facial', biometria: 'Biometria', qrcode: 'QR Code', outro: 'Outro' }[d.tipo_leitor] ?? 'UHF';
    const sentidoLabel = { entrada: 'Entrada', saida: 'Saída', ambos: 'Entrada/Saída' }[d.sentido_acesso] ?? 'Ambos';

    return `
    <div class="disp-card">
      <div class="disp-card-icon ${online ? 'online' : ''}" style="color:${fabInfo.cor}">
        <i class="${fabInfo.icone}"></i>
      </div>
      <div class="disp-card-info">
        <div class="disp-card-nome">${_esc(d.nome_dispositivo)}</div>
        <div class="disp-card-serial">${_esc(d.serial_number)}</div>
        <div class="disp-card-meta">
          <span class="disp-badge" style="background:${_hexToRgba(fabInfo.cor, 0.1)};color:${fabInfo.cor};">
            <i class="${fabInfo.icone}"></i> ${fabInfo.nome}
          </span>
          ${d.modelo ? `<span class="disp-badge disp-badge-gray"><i class="fas fa-cog"></i> ${_esc(d.modelo)}</span>` : ''}
          <span class="disp-badge disp-badge-blue"><i class="fas ${tipoIcon}"></i> ${tipoLabel}</span>
          <span class="disp-badge disp-badge-gray"><i class="fas fa-tag"></i> ${leitorLabel}</span>
          <span class="disp-badge disp-badge-gray"><i class="fas fa-arrows-alt-h"></i> ${sentidoLabel}</span>
          ${d.area_instalacao ? `<span class="disp-badge disp-badge-gray"><i class="fas fa-map-marker-alt"></i> ${_esc(d.area_instalacao)}</span>` : ''}
          ${d.ip_local ? `<span class="disp-badge disp-badge-gray"><i class="fas fa-network-wired"></i> ${_esc(d.ip_local)}:${d.porta_local ?? 80}</span>` : ''}
        </div>
      </div>
      <div class="disp-card-status">
        <span class="disp-status-badge ${online ? 'online' : 'offline'}">
          ${online ? '● Online' : '○ Offline'}
        </span>
        ${d.ultimo_keep_alive
          ? `<span class="disp-ping">Último ping: ${_fmtDatetime(d.ultimo_keep_alive)}</span>`
          : '<span class="disp-ping">Nunca conectado</span>'}
      </div>
      <div class="disp-card-actions">
        <button class="action-btn edit" title="Editar" onclick="window.DispModule?.editarDispositivo(${d.id})">
          <i class="fas fa-edit"></i>
        </button>
        <button class="action-btn key" title="Token / Bridge Key" onclick="window.DispModule?.verToken(${d.id})">
          <i class="fas fa-key"></i>
        </button>
        <button class="action-btn guide" title="Guia de Configuração" onclick="window.DispModule?.verGuia(${d.id})">
          <i class="fas fa-book"></i>
        </button>
        <button class="action-btn delete" title="Desativar" onclick="window.DispModule?.excluirDispositivo(${d.id}, '${_esc(d.nome_dispositivo)}')">
          <i class="fas fa-trash"></i>
        </button>
      </div>
    </div>`;
}

// ── CRUD: Salvar ──────────────────────────────────────────────────────────────
async function _salvarDispositivo() {
    const nome   = _get('disp-nome');
    const serial = _get('disp-serial');
    const fab    = _get('disp-fabricante') || 'controlid';

    if (!nome)   { _toast('Nome do dispositivo é obrigatório.', 'erro'); return; }
    if (!serial) { _toast('Serial Number é obrigatório.', 'erro'); return; }

    // Monta payload base
    const payload = {
        nome_dispositivo: nome,
        serial_number:    serial,
        descricao:        _get('disp-descricao'),
        fabricante:       fab,
        modelo:           _get('disp-modelo'),
        tipo_leitor:      _getSelect('disp-tipo-leitor'),
        area_instalacao:  _get('disp-area'),
        sentido_acesso:   _getSelect('disp-sentido'),
        tipo_integracao:  'manual',
        ip_local:         '',
        porta_local:      80,
        usuario_api:      'admin',
        senha_api:        '',
        device_id_controlid: 0,
        bridge_api_key:   '',
    };

    // Campos específicos por fabricante
    if (fab === 'controlid') {
        const tipo = document.querySelector('input[name="tipo_integracao"]:checked')?.value ?? 'bridge_local';
        payload.tipo_integracao     = tipo;
        payload.ip_local            = _get('disp-ip');
        payload.porta_local         = parseInt(_get('disp-porta') || '80', 10);
        payload.usuario_api         = _get('disp-usuario') || 'admin';
        payload.senha_api           = _get('disp-senha');
        payload.device_id_controlid = parseInt(_get('disp-device-id') || '0', 10) || 0;
        payload.bridge_api_key      = _get('disp-bridge-key');
    } else if (fab === 'intelbras') {
        payload.tipo_integracao = 'bridge_local';
        payload.ip_local        = _get('intelbras-ip');
        payload.porta_local     = parseInt(_get('intelbras-porta') || '80', 10);
        payload.usuario_api     = _get('intelbras-usuario') || 'admin';
        payload.senha_api       = _get('intelbras-senha');
    } else if (fab === 'hikvision') {
        payload.tipo_integracao = 'bridge_local';
        payload.ip_local        = _get('hikvision-ip');
        payload.porta_local     = parseInt(_get('hikvision-porta') || '80', 10);
        payload.usuario_api     = _get('hikvision-usuario') || 'admin';
        payload.senha_api       = _get('hikvision-senha');
    } else if (fab === 'generico') {
        payload.tipo_integracao = 'manual';
        payload.ip_local        = _get('generico-ip');
        payload.porta_local     = parseInt(_get('generico-porta') || '80', 10);
        payload.usuario_api     = _get('generico-usuario') || '';
        payload.senha_api       = _get('generico-senha');
    }

    const btn = _el('btnDispSalvar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    try {
        let data;
        if (_state.dispositivoEditandoId) {
            data = await _fetchJson(`${API}?acao=atualizar`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: _state.dispositivoEditandoId, ...payload }),
            });
        } else {
            data = await _fetchJson(`${API}?acao=criar`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
        }

        if (!data?.sucesso) {
            _toast(data?.erro ?? 'Erro ao salvar dispositivo.', 'erro');
            return;
        }

        if (!_state.dispositivoEditandoId && data.token_autenticacao) {
            _mostrarToken(data.id, data.token_autenticacao);
        } else {
            const msg = _state.dispositivoEditandoId
                ? 'Dispositivo atualizado com sucesso.'
                : (data.reativado ? 'Dispositivo reativado com sucesso.' : 'Dispositivo cadastrado com sucesso.');
            _toast(msg, 'sucesso');
        }

        _cancelarEdicao();
        _carregarDispositivos();

    } catch (e) {
        _toast('Erro de comunicação: ' + e.message, 'erro');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Salvar Dispositivo';
    }
}

// ── CRUD: Editar ──────────────────────────────────────────────────────────────
async function _editarDispositivo(id) {
    try {
        const data = await _fetchJson(`${API}?acao=obter&id=${id}`);
        if (!data?.sucesso || !data.dispositivo) {
            _toast('Erro ao carregar dispositivo.', 'erro');
            return;
        }
        const d = data.dispositivo;
        _state.dispositivoEditandoId = id;

        // Campos comuns
        _setVal('disp-nome',       d.nome_dispositivo ?? '');
        _setVal('disp-serial',     d.serial_number ?? '');
        _setVal('disp-descricao',  d.descricao ?? '');
        _setVal('disp-modelo',     d.modelo ?? '');
        _setVal('disp-area',       d.area_instalacao ?? '');
        _setSelectVal('disp-tipo-leitor', d.tipo_leitor ?? 'uhf');
        _setSelectVal('disp-sentido',     d.sentido_acesso ?? 'ambos');

        // Seleciona fabricante
        const fab = d.fabricante || 'controlid';
        _selecionarFabricante(fab);

        // Campos específicos por fabricante
        if (fab === 'controlid') {
            const radio = document.querySelector(`input[name="tipo_integracao"][value="${d.tipo_integracao ?? 'bridge_local'}"]`);
            if (radio) radio.checked = true;
            _atualizarBlocoRedeControlid();
            _setVal('disp-ip',         d.ip_local ?? '');
            _setVal('disp-porta',      d.porta_local ?? '80');
            _setVal('disp-usuario',    d.usuario_api ?? 'admin');
            _setVal('disp-senha',      ''); // não preenche senha por segurança
            _setVal('disp-device-id',  d.device_id_controlid ?? '');
            _setVal('disp-bridge-key', d.bridge_api_key ?? '');
        } else if (fab === 'intelbras') {
            _setVal('intelbras-ip',      d.ip_local ?? '');
            _setVal('intelbras-porta',   d.porta_local ?? '80');
            _setVal('intelbras-usuario', d.usuario_api ?? 'admin');
            _setVal('intelbras-senha',   '');
        } else if (fab === 'hikvision') {
            _setVal('hikvision-ip',      d.ip_local ?? '');
            _setVal('hikvision-porta',   d.porta_local ?? '80');
            _setVal('hikvision-usuario', d.usuario_api ?? 'admin');
            _setVal('hikvision-senha',   '');
        } else if (fab === 'generico') {
            _setVal('generico-ip',      d.ip_local ?? '');
            _setVal('generico-porta',   d.porta_local ?? '80');
            _setVal('generico-usuario', d.usuario_api ?? '');
            _setVal('generico-senha',   '');
        }

        _setVal('disp-id', String(id));
        _el('disp-serial').disabled = true;

        const titulo = _el('disp-form-titulo');
        if (titulo) titulo.innerHTML = `<i class="fas fa-edit"></i> Editando: ${_esc(d.nome_dispositivo)}`;

        const btnCancelar = _el('btnDispCancelar');
        if (btnCancelar) btnCancelar.style.display = 'inline-flex';

        _el('disp-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });

    } catch (e) {
        _toast('Erro ao carregar dispositivo: ' + e.message, 'erro');
    }
}

// ── CRUD: Cancelar Edição ─────────────────────────────────────────────────────
function _cancelarEdicao() {
    _state.dispositivoEditandoId = null;

    // Limpa campos comuns
    ['disp-nome','disp-serial','disp-descricao','disp-modelo','disp-area',
     'disp-ip','disp-porta','disp-usuario','disp-senha','disp-device-id',
     'disp-bridge-key','disp-id'].forEach(id => _setVal(id, ''));
    _setVal('disp-porta',    '80');
    _setVal('disp-usuario',  'admin');
    _setSelectVal('disp-tipo-leitor', 'uhf');
    _setSelectVal('disp-sentido',     'ambos');

    // Limpa campos Intelbras
    ['intelbras-ip','intelbras-porta','intelbras-usuario','intelbras-senha'].forEach(id => _setVal(id, ''));
    _setVal('intelbras-porta', '80');
    _setVal('intelbras-usuario', 'admin');

    // Limpa campos Hikvision
    ['hikvision-ip','hikvision-porta','hikvision-usuario','hikvision-senha'].forEach(id => _setVal(id, ''));
    _setVal('hikvision-porta', '80');
    _setVal('hikvision-usuario', 'admin');

    // Limpa campos Genérico
    ['generico-ip','generico-porta','generico-usuario','generico-senha'].forEach(id => _setVal(id, ''));
    _setVal('generico-porta', '80');

    // Reseta radio Control iD
    const radio = document.querySelector('input[name="tipo_integracao"][value="bridge_local"]');
    if (radio) radio.checked = true;
    _atualizarBlocoRedeControlid();

    // Reseta fabricante para Control iD
    _selecionarFabricante('controlid');

    // Reabilita serial
    const serial = _el('disp-serial');
    if (serial) serial.disabled = false;

    const titulo = _el('disp-form-titulo');
    if (titulo) titulo.innerHTML = '<i class="fas fa-plus-circle"></i> Novo Dispositivo';

    const btnCancelar = _el('btnDispCancelar');
    if (btnCancelar) btnCancelar.style.display = 'none';
}

// ── CRUD: Excluir ─────────────────────────────────────────────────────────────
async function _excluirDispositivo(id, nome) {
    if (!confirm(`Desativar o dispositivo "${nome}"?\n\nEle não receberá mais comandos e seus eventos não serão registrados.`)) return;
    try {
        const data = await _fetchJson(`${API}?acao=excluir`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        if (data?.sucesso) {
            _toast('Dispositivo desativado com sucesso.', 'sucesso');
            _carregarDispositivos();
        } else {
            _toast(data?.erro ?? 'Erro ao desativar.', 'erro');
        }
    } catch (e) {
        _toast('Erro: ' + e.message, 'erro');
    }
}

// ── Token ─────────────────────────────────────────────────────────────────────
async function _verToken(id) {
    try {
        const data = await _fetchJson(`${API}?acao=obter&id=${id}`);
        if (!data?.sucesso || !data.dispositivo) { _toast('Erro ao obter token.', 'erro'); return; }
        const d = data.dispositivo;
        _mostrarToken(id, d.token_autenticacao, d.bridge_api_key);
    } catch (e) {
        _toast('Erro: ' + e.message, 'erro');
    }
}

function _mostrarToken(id, token, bridgeKey) {
    _state.tokenDispositivoId = id;
    _state.tokenAtual         = token ?? '';
    _setVal('token-display', token ?? '(sem token — clique em Gerar Novo)');
    // Exibe bridge_api_key se o campo existir no modal
    const bkEl = _el('bridge-key-display');
    if (bkEl) bkEl.value = bridgeKey ?? '';
    _el('disp-token-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

async function _copiarToken() {
    try {
        await navigator.clipboard.writeText(_state.tokenAtual);
        _toast('Token copiado para a área de transferência!', 'sucesso');
    } catch {
        _toast('Não foi possível copiar. Selecione e copie manualmente.', 'info');
    }
}

async function _regenerarToken() {
    if (!confirm('Gerar um novo token e nova Bridge Key?\n\nO token e a chave atuais deixarão de funcionar imediatamente. Atualize o arquivo de configuração do bridge antes de confirmar.')) return;
    try {
        const data = await _fetchJson(`${API}?acao=gerar_token`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: _state.tokenDispositivoId }),
        });
        if (!data?.sucesso) { _toast(data?.erro ?? 'Erro ao gerar token.', 'erro'); return; }
        _state.tokenAtual = data.token_autenticacao;
        _setVal('token-display', data.token_autenticacao);
        const bkEl = _el('bridge-key-display');
        if (bkEl && data.bridge_api_key) bkEl.value = data.bridge_api_key;
        _toast('Novo token e Bridge Key gerados! Atualize o bridge.', 'sucesso');
    } catch (e) {
        _toast('Erro: ' + e.message, 'erro');
    }
}

// ── Guia de Configuração ──────────────────────────────────────────────────────
async function _verGuia(id) {
    try {
        const data = await _fetchJson(`${API}?acao=obter&id=${id}`);
        if (!data?.sucesso || !data.dispositivo) { _toast('Erro ao carregar dados.', 'erro'); return; }
        const d = data.dispositivo;
        const fab = d.fabricante || 'controlid';
        const guia = _el('guia-conteudo');
        if (guia) guia.innerHTML = _renderGuia(d, fab);
        _el('disp-guia-overlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    } catch (e) {
        _toast('Erro: ' + e.message, 'erro');
    }
}

function _renderGuia(d, fab) {
    const token     = d.token_autenticacao ?? '(gere um token clicando na chave)';
    const ip        = d.ip_local ?? '192.168.X.XXX';
    const porta     = d.porta_local ?? 80;
    const user      = d.usuario_api ?? 'admin';
    const tipo      = d.tipo_integracao ?? 'bridge_local';
    const serverUrl = window.location.origin;

    if (fab !== 'controlid') {
        const fabInfo = FABRICANTES[fab] || FABRICANTES.generico;
        return `
          <div class="guia-header">
            <i class="${fabInfo.icone}" style="color:${fabInfo.cor}"></i>
            <h4>${fabInfo.nome} — ${_esc(d.nome_dispositivo)}</h4>
          </div>
          <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <span>A integração automática para <strong>${fabInfo.nome}</strong> está em desenvolvimento.
            Os dados de conexão foram salvos e serão utilizados quando o módulo de integração for ativado.</span>
          </div>
          <div class="guia-info-grid">
            <div><strong>IP:</strong> ${_esc(ip)}</div>
            <div><strong>Porta:</strong> ${porta}</div>
            <div><strong>Usuário:</strong> ${_esc(user)}</div>
          </div>`;
    }

    if (tipo === 'bridge_local') {
        return `
          <div class="guia-header">
            <i class="fas fa-laptop-code" style="color:#2563eb"></i>
            <h4>Bridge Local — Script Python no PC da Portaria</h4>
          </div>
          <ol class="guia-steps">
            <li>Instale o Python 3.x em <a href="https://python.org" target="_blank" rel="noopener">python.org</a></li>
            <li>Baixe o arquivo <code>bridge_local/controlid_bridge.py</code> do repositório do projeto</li>
            <li>Edite as configurações no topo do arquivo:</li>
          </ol>
          <pre class="guia-code">CONTROLID_HOST = "${_esc(ip)}"
CONTROLID_PORT = ${porta}
CONTROLID_USER = "${_esc(user)}"
CONTROLID_PASS = "SENHA_DO_EQUIPAMENTO"

SERVER_URL     = "${serverUrl}/api/bridge_receiver.php"
BRIDGE_API_KEY = "${_esc(token)}"
SERIAL_NUMBER  = "${_esc(d.serial_number)}"</pre>
          <ol class="guia-steps" start="4">
            <li>Execute: <code>python controlid_bridge.py</code></li>
            <li>Para iniciar automaticamente com o Windows, use <code>instalar_servico_windows.bat</code></li>
          </ol>
          <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <span><strong>Dica:</strong> O equipamento continua funcionando mesmo sem internet. O bridge sincroniza os eventos quando a conexão for restabelecida.</span>
          </div>`;
    }

    if (tipo === 'monitor_nativo') {
        return `
          <div class="guia-header">
            <i class="fas fa-satellite-dish" style="color:#2563eb"></i>
            <h4>Monitor Nativo — Equipamento envia eventos diretamente</h4>
          </div>
          <p class="guia-desc">Configure o Monitor no equipamento via API REST:</p>
          <pre class="guia-code">POST http://${_esc(ip)}:${porta}/set_configuration.fcgi?session=TOKEN_SESSION
{
  "monitor": {
    "hostname": "${window.location.hostname}",
    "port": "443",
    "path": "api/controlid_monitor.php",
    "token": "${_esc(token)}"
  }
}</pre>
          <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <span><strong>Atenção:</strong> Requer conexão de internet estável. Se a internet cair, o equipamento pode travar aguardando resposta do servidor.</span>
          </div>`;
    }

    return `
      <div class="guia-header">
        <i class="fas fa-hand-paper" style="color:#64748b"></i>
        <h4>Modo Manual — Sem integração automática</h4>
      </div>
      <p class="guia-desc">Este dispositivo está configurado para registros manuais apenas. Nenhuma integração automática está ativa.</p>
      <p class="guia-desc">Para habilitar integração automática, edite o dispositivo e selecione "Bridge Local" ou "Monitor Nativo".</p>`;
}

// ── Modais ────────────────────────────────────────────────────────────────────
function _setupModais() {
    // Fechar modal token
    _on(_el('btnFecharToken'), 'click', () => _fecharModal('disp-token-overlay'));
    _on(_el('btnCopiarToken'), 'click', _copiarToken);
    _on(_el('btnRegenerarToken'), 'click', _regenerarToken);

    // Fechar modal guia
    _on(_el('btnFecharGuia'), 'click', () => _fecharModal('disp-guia-overlay'));

    // Fechar ao clicar no overlay
    [_el('disp-token-overlay'), _el('disp-guia-overlay')].forEach(overlay => {
        if (!overlay) return;
        _on(overlay, 'click', e => {
            if (e.target === overlay) _fecharModal(overlay.id);
        });
    });
}

function _fecharModal(id) {
    const el = _el(id);
    if (el) el.classList.remove('open');
    document.body.style.overflow = '';
}

// ── Aba: Eventos ──────────────────────────────────────────────────────────────
function _setupEventosTab() {
    _on(_el('btnEventosBuscar'),  'click', () => { _state.evPagina = 1; _carregarEventos(); });
    _on(_el('btnEventosRefresh'), 'click', () => { _state.evPagina = 1; _carregarEventos(); });
}

async function _carregarEventos() {
    // Filtros alinhados com schema real: dispositivo_id, event_type, event_time
    const dispId = _getSelect('ev-filtro-disp');
    const tipo   = _getSelect('ev-filtro-tipo');
    const de     = _get('ev-filtro-de');
    const ate    = _get('ev-filtro-ate');
    const tbody  = _el('ev-tbody');
    tbody.innerHTML = `<tr><td colspan="7" class="empty-cell"><i class="fas fa-spinner fa-spin"></i></td></tr>`;

    const params = new URLSearchParams({ acao: 'listar_eventos', pagina: String(_state.evPagina) });
    if (dispId) params.set('dispositivo_id', dispId);
    if (tipo)   params.set('tipo_evento',    tipo);
    if (de)     params.set('data_de',        de);
    if (ate)    params.set('data_ate',       ate);

    try {
        const data = await _fetchJson(`${API}?${params}`);
        if (!data?.sucesso) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-cell error">Erro ao carregar eventos.</td></tr>`;
            return;
        }

        const evs = data.eventos ?? [];
        if (evs.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-cell">Nenhum evento encontrado.</td></tr>`;
            const pag = _el('ev-paginacao');
            if (pag) pag.innerHTML = '';
            return;
        }

        // Campos reais: event_type, event_time, card_value, user_id, veiculo_id, morador_id
        tbody.innerHTML = evs.map(e => `
          <tr>
            <td class="cell-mono">${e.id}</td>
            <td class="cell-mono cell-sm">${_esc(e.nome_dispositivo ?? e.serial_number ?? '—')}</td>
            <td class="cell-mono cell-sm">${_esc(e.card_value ?? e.user_id ?? '—')}</td>
            <td class="cell-sm">
              ${e.veiculo_id ? `<span class="badge badge-info">Veículo #${e.veiculo_id}</span>` : ''}
              ${e.morador_id ? `<span class="badge badge-info">Morador #${e.morador_id}</span>` : ''}
              ${!e.veiculo_id && !e.morador_id ? '—' : ''}
            </td>
            <td>${_labelTipoEvento(e.event_type)}</td>
            <td class="cell-sm">${_fmtDatetime(e.event_time)}</td>
            <td>${e.processado
              ? '<span class="badge badge-active">Processado</span>'
              : '<span class="badge badge-warning">Pendente</span>'}</td>
          </tr>`).join('');

        _renderPaginacao('ev-paginacao', data.pagina, data.paginas, p => {
            _state.evPagina = p;
            _carregarEventos();
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-cell error">Erro: ${_esc(e.message)}</td></tr>`;
    }
}

// ── Aba: Fila de Comandos ─────────────────────────────────────────────────────
function _setupFilaTab() {
    _on(_el('btnFilaEnfileirar'),  'click', _enfileirarComando);
    _on(_el('btnFilaRefresh'),     'click', _carregarFila);
    _on(_el('fila-filtro-status'), 'change', _carregarFila);
    _on(_el('fila-filtro-disp'),   'change', _carregarFila);
}

async function _carregarFila() {
    // Filtros alinhados com schema real: dispositivo_id, status
    const dispId = _getSelect('fila-filtro-disp');
    const status = _getSelect('fila-filtro-status');
    const tbody  = _el('fila-tbody');
    tbody.innerHTML = `<tr><td colspan="6" class="empty-cell"><i class="fas fa-spinner fa-spin"></i></td></tr>`;

    const params = new URLSearchParams({ acao: 'listar_fila' });
    if (dispId) params.set('dispositivo_id', dispId);
    if (status) params.set('status', status);

    try {
        const data = await _fetchJson(`${API}?${params}`);
        if (!data?.sucesso) {
            tbody.innerHTML = `<tr><td colspan="6" class="empty-cell error">Erro ao carregar fila.</td></tr>`;
            return;
        }

        const cmds = data.comandos ?? [];
        if (cmds.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="empty-cell">Nenhum comando na fila.</td></tr>`;
            return;
        }

        const pendentes = cmds.filter(c => c.status === 'pendente').length;
        const badge = _el('badge-fila');
        if (badge) {
            badge.textContent   = String(pendentes);
            badge.style.display = pendentes > 0 ? 'inline' : 'none';
        }

        // Campos reais: tipo_comando, payload (em vez de verbo, endpoint, corpo_json)
        tbody.innerHTML = cmds.map(c => {
            const payloadStr = c.payload
                ? (typeof c.payload === 'string' ? c.payload : JSON.stringify(c.payload)).slice(0, 60)
                : '—';
            return `
          <tr>
            <td class="cell-mono">${c.id}</td>
            <td class="cell-mono cell-sm">${_esc(c.nome_dispositivo ?? c.serial_number ?? '—')}</td>
            <td><span class="badge badge-info">${_esc(c.tipo_comando)}</span></td>
            <td class="cell-mono cell-sm cell-truncate" title="${_esc(JSON.stringify(c.payload ?? {}))}">${_esc(payloadStr)}</td>
            <td>${_labelStatus(c.status)}</td>
            <td class="cell-sm">${_fmtDatetime(c.criado_em)}</td>
            <td>
              ${c.status === 'pendente'
                ? `<button class="action-btn delete" title="Cancelar" onclick="window.DispModule?.cancelarComando(${c.id})">
                     <i class="fas fa-ban"></i>
                   </button>`
                : '—'}
            </td>
          </tr>`;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="empty-cell error">Erro: ${_esc(e.message)}</td></tr>`;
    }
}

async function _enfileirarComando() {
    // Schema real: dispositivo_id, tipo_comando, payload
    const dispId       = _getSelect('fila-disp');
    const tipoComando  = _get('fila-tipo-comando');
    const payloadTxt   = _get('fila-payload');

    if (!dispId)      { _toast('Selecione o dispositivo.', 'erro'); return; }
    if (!tipoComando) { _toast('Tipo de comando é obrigatório.', 'erro'); return; }

    let payload = {};
    if (payloadTxt) {
        try { payload = JSON.parse(payloadTxt); }
        catch { _toast('Payload JSON inválido. Verifique a sintaxe.', 'erro'); return; }
    }

    const btn = _el('btnFilaEnfileirar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

    try {
        const data = await _fetchJson(`${API}?acao=enfileirar_comando`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                dispositivo_id: parseInt(dispId, 10),
                tipo_comando:   tipoComando,
                payload,
            }),
        });

        if (!data?.sucesso) { _toast(data?.erro ?? 'Erro ao enfileirar.', 'erro'); return; }
        _toast(`Comando #${data.id} enfileirado com sucesso.`, 'sucesso');
        _setVal('fila-tipo-comando', '');
        _setVal('fila-payload',      '');
        _carregarFila();
    } catch (e) {
        _toast('Erro: ' + e.message, 'erro');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enfileirar Comando';
    }
}

async function _cancelarComando(id) {
    if (!confirm(`Cancelar o comando #${id}?`)) return;
    try {
        const data = await _fetchJson(`${API}?acao=cancelar_comando`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        if (data?.sucesso) {
            _toast('Comando cancelado.', 'sucesso');
            _carregarFila();
        } else {
            _toast(data?.erro ?? 'Erro ao cancelar.', 'erro');
        }
    } catch (e) {
        _toast('Erro: ' + e.message, 'erro');
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function _popularSelectDispositivos(devs) {
    // Usa id do dispositivo como valor (alinhado com schema: dispositivo_id)
    ['ev-filtro-disp', 'fila-disp'].forEach(selId => {
        const sel = _el(selId);
        if (!sel) return;
        const atual = sel.value;
        const ph = selId === 'fila-disp'
            ? '<option value="">Selecione o dispositivo...</option>'
            : '<option value="">Todos os dispositivos</option>';
        sel.innerHTML = ph + devs.map(d =>
            `<option value="${d.id}">${_esc(d.nome_dispositivo)} — ${_esc(d.serial_number)}</option>`
        ).join('');
        if (atual) sel.value = atual;
    });
}

function _renderPaginacao(containerId, paginaAtual, totalPaginas, onPage) {
    const c = _el(containerId);
    if (!c) return;
    if (!totalPaginas || totalPaginas <= 1) { c.innerHTML = ''; return; }
    let html = '';
    for (let p = 1; p <= Math.min(totalPaginas, 20); p++) {
        html += `<button class="pag-btn ${p === paginaAtual ? 'active' : ''}" onclick="(${onPage.toString()})(${p})">${p}</button>`;
    }
    c.innerHTML = html;
}

function _labelTipoEvento(tipo) {
    const map = {
        7: '<span class="badge badge-active">Liberado</span>',
        4: '<span class="badge badge-inactive">Negado</span>',
        1: '<span class="badge badge-info">Saída</span>',
    };
    return map[tipo] ?? `<span class="badge">${tipo}</span>`;
}

function _labelStatus(status) {
    const map = {
        pendente:  '<span class="badge badge-warning">Pendente</span>',
        enviado:   '<span class="badge badge-active">Enviado</span>',
        cancelado: '<span class="badge">Cancelado</span>',
    };
    return map[status] ?? status;
}

function _hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r},${g},${b},${alpha})`;
}

function _fmtDatetime(str) {
    if (!str) return '—';
    try { return new Date(str).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); }
    catch { return str; }
}

function _esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _el(id)              { return document.getElementById(id); }
function _get(id)             { return (_el(id)?.value ?? '').trim(); }
function _getSelect(id)       { return _el(id)?.value ?? ''; }
function _setVal(id, v)       { const el = _el(id); if (el) el.value = v; }
function _setSelectVal(id, v) { const el = _el(id); if (el) el.value = v; }
function _setText(id, t)      { const el = _el(id); if (el) el.textContent = t; }

function _on(el, ev, fn) {
    if (!el) return;
    el.addEventListener(ev, fn);
    _state._listeners.push({ el, ev, fn });
}

function _emptyHTML(icon, msg) {
    return `<div class="empty-state"><i class="fas ${icon}"></i><p>${_esc(msg)}</p></div>`;
}

async function _fetchJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'include', ...options });
    const raw = await response.text();
    let data = null;
    if (raw && raw.trim()) {
        try { data = JSON.parse(raw); }
        catch (_) {
            const preview = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
            throw new Error(preview || 'Resposta inválida do servidor.');
        }
    }
    if (!response.ok) throw new Error(data?.erro || `Erro HTTP ${response.status}`);
    return data || {};
}

function _toast(msg, tipo = 'info') {
    const cores = { sucesso: '#16a34a', erro: '#dc2626', info: '#2563eb', warning: '#d97706' };
    const icons = { sucesso: 'check-circle', erro: 'times-circle', info: 'info-circle', warning: 'exclamation-triangle' };
    const t = document.createElement('div');
    t.style.cssText = `
        position:fixed;bottom:24px;right:24px;
        background:${cores[tipo] || '#334155'};
        color:#fff;padding:12px 20px;border-radius:10px;
        font-size:14px;font-weight:500;z-index:99999;
        box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:380px;
        line-height:1.4;display:flex;align-items:center;gap:10px;`;
    t.innerHTML = `<i class="fas fa-${icons[tipo] || 'info-circle'}"></i><span>${_esc(msg)}</span>`;
    document.body.appendChild(t);
    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transition = 'opacity .3s';
        setTimeout(() => t.remove(), 300);
    }, 4000);
}
