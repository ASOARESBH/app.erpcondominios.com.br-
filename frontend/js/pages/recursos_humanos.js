/**
 * Módulo: Recursos Humanos
 * Abas: Colaboradores | Registro de Ponto | Escala | Relatórios
 */

// ── Estado ─────────────────────────────────────────────────────────────────────
let _state = {
    colaboradorAtual    : null,
    periodoAtual        : null,
    lancamentos         : [],
    escalaDias          : ['seg','ter','qua','qui','sex'],
    escalaSalvando      : false,
    escalaTotal         : 0,
    demissaoConfirmada  : false,
};

let _bhSaldoGlobal = 0;
let _bhRequestKey = null;
let _bhRequestSignature = null;
let _bhPendingRequest = null;
let _bhSubmitting = false;
let _bhReprocessPreview = null;
let _bhReprocessApplying = false;
let _bhReprocessProgress = null;
let _bhServerCanEdit = null;
let _bhServerIsAdmin = null;
let _bhPermissionRequest = null;

const RH_DEFAULT_AVATAR = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='110' height='110' viewBox='0 0 110 110'%3E%3Ccircle cx='55' cy='55' r='55' fill='%23e2e8f0'/%3E%3Ccircle cx='55' cy='42' r='18' fill='%2394a3b8'/%3E%3Cellipse cx='55' cy='85' rx='28' ry='20' fill='%2394a3b8'/%3E%3C/svg%3E";

// ── Ciclo de vida ─────────────────────────────────────────────────────────────
export async function init() {
    window.ModuleRHDefaultAvatar = RH_DEFAULT_AVATAR;
    _setupTabs();
    _setupColaboradores();
    _setupPonto();
    _setupEscala();
    _setupRelatorios();
    _setupAbono();
    _popularSelects();
    window.addEventListener('permissoesAtualizadas', _atualizarBtnBHReprocessar);
    _atualizarBtnBHReprocessar();

    window.ModuleRH = {
        editarColaborador : _editarColaborador,
        excluirColaborador: _excluirColaborador,
        abrirPontoColab   : _abrirPontoColab,
        salvarLinhaPonto  : _salvarLinhaPonto,
        gerarRelatorio        : _gerarRelatorio,
        gerarRelatorioPDF     : _gerarRelatorioPDF,
        exportarRelatorioCSV  : _exportarRelatorioCSV,
        editarEscala      : _editarEscala,
        excluirEscala     : _excluirEscala,
        registrarBH       : _registrarBH,
        reprocessarBH     : _iniciarReprocessamentoBH,
    };
}

export function destroy() {
    document.removeEventListener('keydown', _bhEscapeListener);
    document.removeEventListener('keydown', _bhReprocessEscapeListener);
    window.removeEventListener('permissoesAtualizadas', _atualizarBtnBHReprocessar);
    _fecharReprocessamentoBH();
    _fecharBHModal();
    delete window.ModuleRH;
    delete window.ModuleRHDefaultAvatar;
}

// ── Abas ──────────────────────────────────────────────────────────────────────
function _setupTabs() {
    document.querySelectorAll('.page-recursos-humanos .tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.page-recursos-humanos .tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.page-recursos-humanos .tab-content').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab)?.classList.add('active');
        });
    });
}

// ── Toast helper ──────────────────────────────────────────────────────────────
function _toast(msg, type = 'info') {
    const colors = { success: '#16a34a', error: '#dc2626', info: '#667eea' };
    const t = Object.assign(document.createElement('div'), {
        textContent: msg,
        style: `position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;
                border-radius:8px;color:#fff;font-size:14px;font-weight:500;
                background:${colors[type]||colors.info};box-shadow:0 4px 12px rgba(0,0,0,.2);
                animation:fadeIn .3s ease;`,
    });
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

function _setFormStatus(msg = '', type = 'info') {
    const el = document.getElementById('rh-form-status');
    if (!el) return;

    if (!msg) {
        el.style.display = 'none';
        el.innerHTML = '';
        return;
    }

    const palette = {
        success: { bg: '#dcfce7', border: '#16a34a', color: '#166534', icon: 'fa-circle-check' },
        error: { bg: '#fee2e2', border: '#dc2626', color: '#991b1b', icon: 'fa-circle-exclamation' },
        info: { bg: '#dbeafe', border: '#2563eb', color: '#1d4ed8', icon: 'fa-circle-info' },
    };
    const config = palette[type] || palette.info;

    el.style.display = 'flex';
    el.style.alignItems = 'center';
    el.style.gap = '10px';
    el.style.padding = '12px 14px';
    el.style.marginBottom = '16px';
    el.style.borderRadius = '10px';
    el.style.border = `1px solid ${config.border}`;
    el.style.background = config.bg;
    el.style.color = config.color;
    el.innerHTML = `<i class="fas ${config.icon}"></i><span>${_esc(msg)}</span>`;
}

async function _fetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const raw = await response.text();

    let data = null;
    if (raw) {
        try {
            data = JSON.parse(raw);
        } catch (error) {
            const preview = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
            throw new Error(preview || 'Resposta inv\u00e1lida do servidor.');
        }
    }

    if (!response.ok) {
        throw new Error(data?.mensagem || `Erro HTTP ${response.status}`);
    }

    return data || {};
}

// ────────────────────────────────────────────────────────────────────────────
// ABA: COLABORADORES
// ────────────────────────────────────────────────────────────────────────────
function _setupColaboradores() {
    _carregarColaboradores();
    _setFormStatus();

    document.getElementById('btnRhBuscar')?.addEventListener('click', _carregarColaboradores);
    document.getElementById('rh-busca')?.addEventListener('keydown', e => { if (e.key === 'Enter') _carregarColaboradores(); });
    document.getElementById('rh-filtro-ativo')?.addEventListener('change', _carregarColaboradores);

    document.getElementById('btnRhNovoColab')?.addEventListener('click', _limparFormColab);
    document.getElementById('btnRhCancelar')?.addEventListener('click', _limparFormColab);

    const form = document.getElementById('formColaborador');
    if (form) {
        form.noValidate = true;
        form.method = 'post';
        form.action = 'javascript:void(0)';
        form.addEventListener('submit', _salvarColaborador);
    }

    // Validação em tempo real da data de demissão
    document.getElementById('rh-data-demissao')?.addEventListener('change', _validarDataDemissao);

    // Botões do painel de aviso de demissão
    document.getElementById('btnDemissaoConfirmar')?.addEventListener('click', () => {
        _state.demissaoConfirmada = true;
        document.getElementById('rh-demissao-aviso').style.display = 'none';
        document.getElementById('formColaborador').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });
    document.getElementById('btnDemissaoCancelar')?.addEventListener('click', () => {
        document.getElementById('rh-data-demissao').value = '';
        document.getElementById('rh-demissao-aviso').style.display = 'none';
        document.getElementById('rh-demissao-erro').style.display = 'none';
        _state.demissaoConfirmada = false;
    });

    // Foto preview
    document.getElementById('rh-foto-input')?.addEventListener('change', e => {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = ev => { document.getElementById('rh-foto-preview').src = ev.target.result; };
            reader.readAsDataURL(file);
        }
    });

    // CEP auto-fill
    document.getElementById('rh-cep')?.addEventListener('blur', _buscarCep);

    // Mask CPF
    document.getElementById('rh-cpf')?.addEventListener('input', e => {
        let v = e.target.value.replace(/\D/g,'');
        if (v.length > 11) v = v.slice(0,11);
        e.target.value = v.replace(/(\d{3})(\d)/, '$1.$2')
                          .replace(/(\d{3})(\d)/, '$1.$2')
                          .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    });
}

async function _carregarColaboradores() {
    const busca  = document.getElementById('rh-busca')?.value ?? '';
    const ativo  = document.getElementById('rh-filtro-ativo')?.value ?? '1';
    const wrap   = document.getElementById('rh-lista-colaboradores');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading-msg"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

    try {
        const d = await _fetchJson(`../api/api_rh_colaboradores.php?acao=listar&busca=${encodeURIComponent(busca)}&ativo=${ativo}`, { credentials: 'include' });
        if (!d.sucesso) throw new Error(d.mensagem);
        _renderColaboradores(d.dados ?? []);
    } catch (err) {
        wrap.innerHTML = `<p style="color:#dc2626;padding:16px;"><i class="fas fa-exclamation-triangle"></i> ${_esc(err.message)}</p>`;
    }
}

function _renderColaboradores(list) {
    const wrap = document.getElementById('rh-lista-colaboradores');
    if (!list.length) { wrap.innerHTML = '<p style="padding:16px;color:var(--text-secondary,#64748b);">Nenhum colaborador encontrado.</p>'; return; }

    wrap.onclick = event => {
        const button = event.target.closest('[data-rh-colab-action]');
        if (!button || !wrap.contains(button)) return;
        const id = Number.parseInt(button.dataset.id || '', 10);
        if (!Number.isSafeInteger(id) || id <= 0) return;
        const nome = button.dataset.nome || '';
        if (button.dataset.rhColabAction === 'ponto') _abrirPontoColab(id, nome);
        else if (button.dataset.rhColabAction === 'editar') _editarColaborador(id);
        else if (button.dataset.rhColabAction === 'excluir') _excluirColaborador(id, nome);
    };
    wrap.innerHTML = list.map(c => {
        const id = Number.parseInt(c.id, 10);
        if (!Number.isSafeInteger(id) || id <= 0) return '';
        return `
        <div class="rh-colab-card">
            <img class="rh-colab-avatar"
                 src="${_esc(_rhFotoUrl(c.foto_path))}"
                 alt="${_esc(c.nome)}"
                 onerror="this.onerror=null;this.src=window.ModuleRHDefaultAvatar||''">
            <div class="rh-colab-info">
                <div class="rh-colab-nome">${_esc(c.nome)}</div>
                <div class="rh-colab-sub">${_esc(c.cargo||'—')} · ${_esc(c.departamento||'—')} · CPF: ${_esc(c.cpf||'—')}</div>
            </div>
            <span class="rh-badge ${c.ativo == 1 ? 'rh-badge-ativo' : 'rh-badge-inativo'}">${c.ativo == 1 ? 'Ativo' : 'Inativo'}</span>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <button class="action-btn" type="button" title="Registrar ponto" data-rh-colab-action="ponto" data-id="${id}" data-nome="${_esc(c.nome)}">
                    <i class="fas fa-clock"></i>
                </button>
                <button class="action-btn" type="button" title="Editar" data-rh-colab-action="editar" data-id="${id}" data-nome="${_esc(c.nome)}">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="action-btn" type="button" title="Excluir" data-rh-colab-action="excluir" data-id="${id}" data-nome="${_esc(c.nome)}">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    }).join('');
}

async function _editarColaborador(id) {
    try {
        const d = await _fetchJson(`../api/api_rh_colaboradores.php?acao=obter&id=${id}`, { credentials: 'include' });
        if (!d.sucesso) throw new Error(d.mensagem);
        const c = d.dados;

        document.getElementById('rh-id').value            = c.id;
        document.getElementById('rh-nome').value          = c.nome ?? '';
        document.getElementById('rh-cpf').value           = c.cpf  ?? '';
        document.getElementById('rh-rg').value            = c.rg   ?? '';
        document.getElementById('rh-data-nascimento').value = c.data_nascimento ?? '';
        _setSelect('rh-sexo', c.sexo);
        _setSelect('rh-estado-civil', c.estado_civil);
        document.getElementById('rh-cargo').value         = c.cargo ?? '';
        document.getElementById('rh-departamento').value  = c.departamento ?? '';
        _setSelect('rh-tipo-contrato', c.tipo_contrato);
        document.getElementById('rh-data-admissao').value = c.data_admissao ?? '';
        document.getElementById('rh-data-demissao').value = c.data_demissao ?? '';
        document.getElementById('rh-salario').value       = c.salario ?? '';
        document.getElementById('rh-telefone').value      = c.telefone ?? '';
        document.getElementById('rh-celular').value       = c.celular  ?? '';
        document.getElementById('rh-email').value         = c.email    ?? '';
        document.getElementById('rh-cep').value           = c.cep ?? '';
        document.getElementById('rh-logradouro').value    = c.logradouro ?? '';
        document.getElementById('rh-numero').value        = c.numero ?? '';
        document.getElementById('rh-complemento').value   = c.complemento ?? '';
        document.getElementById('rh-bairro').value        = c.bairro ?? '';
        document.getElementById('rh-cidade').value        = c.cidade ?? '';
        _setSelect('rh-estado', c.estado);
        document.getElementById('rh-banco').value         = c.banco ?? '';
        document.getElementById('rh-agencia').value       = c.agencia ?? '';
        document.getElementById('rh-conta').value         = c.conta ?? '';
        document.getElementById('rh-pix').value           = c.pix ?? '';
        document.getElementById('rh-observacoes').value   = c.observacoes ?? '';

        document.getElementById('rh-foto-preview').src = _rhFotoUrl(c.foto_path);
        _setFormStatus();

        document.getElementById('rh-form-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar Colaborador';
        document.getElementById('btnRhCancelar').style.display = '';
        document.getElementById('rh-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
        _toast(err.message, 'error');
    }
}

async function _excluirColaborador(id, nome) {
    if (!confirm(`Deseja remover o colaborador "${nome}"?`)) return;
    try {
        const d = await _fetchJson(`../api/api_rh_colaboradores.php?acao=excluir`, {
            method: 'DELETE', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) _carregarColaboradores();
    } catch (err) { _toast(err.message, 'error'); }
}

function _validarDataDemissao() {
    const admissao = document.getElementById('rh-data-admissao').value;
    const demissao = document.getElementById('rh-data-demissao').value;
    const erro     = document.getElementById('rh-demissao-erro');
    const aviso    = document.getElementById('rh-demissao-aviso');
    _state.demissaoConfirmada = false;
    aviso.style.display = 'none';
    if (!demissao) { erro.style.display = 'none'; return; }
    if (admissao && demissao <= admissao) {
        erro.style.display = 'block';
        aviso.style.display = 'none';
    } else {
        erro.style.display = 'none';
    }
}

async function _salvarColaborador(e) {
    e.preventDefault();
    _setFormStatus();
    const id   = document.getElementById('rh-id').value;
    const acao = id ? 'atualizar' : 'criar';

    // ── Validação de datas ──────────────────────────────────────────────────
    const admissao = document.getElementById('rh-data-admissao').value;
    const demissao = document.getElementById('rh-data-demissao').value;
    const avisoEl  = document.getElementById('rh-demissao-aviso');
    const erroEl   = document.getElementById('rh-demissao-erro');
    if (demissao) {
        if (admissao && demissao <= admissao) {
            erroEl.style.display = 'block';
            _setFormStatus('A data de demissão deve ser posterior à data de admissão.', 'error');
            return;
        }
        if (!_state.demissaoConfirmada) {
            avisoEl.style.display = 'block';
            avisoEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
    }
    _state.demissaoConfirmada = false;

    const fd   = new FormData(e.target);

    // Adicionar campos manualmente (inputs sem name)
    const campos = ['nome','cpf','rg','data_nascimento','sexo','estado_civil','cargo','departamento',
                    'tipo_contrato','data_admissao','data_demissao','salario','telefone','celular','email',
                    'cep','logradouro','numero','complemento','bairro','cidade','estado',
                    'banco','agencia','conta','pix','observacoes'];
    campos.forEach(c => fd.set(c, document.getElementById('rh-' + c)?.value ?? ''));
    if (id) fd.set('id', id);

    const fotoInput = document.getElementById('rh-foto-input');
    if (fotoInput?.files[0]) fd.set('foto', fotoInput.files[0]);

    const btn = document.getElementById('btnRhSalvar');
    btn.disabled = true;
    btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${id ? 'Salvando...' : 'Cadastrando...'}`;

    try {
        const url = `../api/api_rh_colaboradores.php?acao=${acao}${id ? '&id=' + id : ''}`;
        const d   = await _fetchJson(url, { method: 'POST', credentials: 'include', body: fd });
        if (!d.sucesso) throw new Error(d.mensagem || 'N\u00e3o foi poss\u00edvel salvar o colaborador.');
        _toast(d.mensagem, 'success');
        _setFormStatus(d.mensagem || 'Colaborador salvo com sucesso.', 'success');
        _limparFormColab({ preserveStatus: true });
        _carregarColaboradores();
        _popularSelects();
    } catch (err) {
        const mensagem = err.message || 'Ocorreu um erro ao salvar o colaborador.';
        _setFormStatus(mensagem, 'error');
        _toast(mensagem, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Salvar Colaborador';
    }
}

function _limparFormColab(options = {}) {
    document.getElementById('formColaborador').reset();
    document.getElementById('rh-id').value = '';
    document.getElementById('rh-foto-preview').src = RH_DEFAULT_AVATAR;
    document.getElementById('rh-foto-input').value = '';
    document.getElementById('rh-form-titulo').innerHTML = '<i class="fas fa-plus-circle"></i> Novo Colaborador';
    _state.demissaoConfirmada = false;
    const avisoEl = document.getElementById('rh-demissao-aviso');
    const erroEl  = document.getElementById('rh-demissao-erro');
    if (avisoEl) avisoEl.style.display = 'none';
    if (erroEl)  erroEl.style.display  = 'none';
    document.getElementById('btnRhCancelar').style.display = 'none';
    if (!options.preserveStatus) _setFormStatus();
}

async function _buscarCep() {
    const cep = document.getElementById('rh-cep').value.replace(/\D/g,'');
    if (cep.length !== 8) return;
    try {
        const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        const d = await r.json();
        if (d.erro) return;
        document.getElementById('rh-logradouro').value = d.logradouro ?? '';
        document.getElementById('rh-bairro').value     = d.bairro     ?? '';
        document.getElementById('rh-cidade').value     = d.localidade ?? '';
        _setSelect('rh-estado', d.uf);
    } catch {}
}

// ────────────────────────────────────────────────────────────────────────────
// ABA: REGISTRO DE PONTO
// ────────────────────────────────────────────────────────────────────────────
function _setupPonto() {
    const hoje = new Date();
    document.getElementById('ponto-mes').value = String(hoje.getMonth() + 1);
    document.getElementById('ponto-ano').value = String(hoje.getFullYear());

    // Alternar campos ao mudar tipo de período
    document.querySelectorAll('input[name="ponto-tipo-periodo"]').forEach(radio => {
        radio.addEventListener('change', () => {
            const personalizado = document.getElementById('ponto-tipo-personalizado').checked;
            document.getElementById('ponto-campo-mes').style.display          = personalizado ? 'none' : '';
            document.getElementById('ponto-campo-ano').style.display          = personalizado ? 'none' : '';
            document.getElementById('ponto-campo-data-inicio').style.display  = personalizado ? '' : 'none';
            document.getElementById('ponto-campo-data-fim').style.display     = personalizado ? '' : 'none';
            document.getElementById('ponto-aviso-personalizado').style.display = personalizado ? '' : 'none';
            // Pré-preencher datas com o mês atual se vazio
            if (personalizado) {
                const y = hoje.getFullYear(), m = String(hoje.getMonth()+1).padStart(2,'0');
                if (!document.getElementById('ponto-data-inicio').value)
                    document.getElementById('ponto-data-inicio').value = `${y}-${m}-01`;
                if (!document.getElementById('ponto-data-fim').value) {
                    const ultimo = new Date(y, hoje.getMonth()+1, 0).getDate();
                    document.getElementById('ponto-data-fim').value = `${y}-${m}-${String(ultimo).padStart(2,'0')}`;
                }
            }
        });
    });

    document.getElementById('btnPontoAbrir')?.addEventListener('click', _abrirPeriodo);
    document.getElementById('btnPontoCriar')?.addEventListener('click', _criarPeriodo);
    document.getElementById('btnPontoVoltar')?.addEventListener('click', _fecharFolha);
    document.getElementById('btnPontoFechar')?.addEventListener('click', _fecharPeriodo);
    document.getElementById('btnPontoReabrir')?.addEventListener('click', _reabrirPeriodo);
    document.getElementById('btnPontoRecalcular')?.addEventListener('click', _recalcularPeriodo);
}

// Retorna os parâmetros de período do ponto (mes/ano ou data_inicio/data_fim)
function _getPontoPeriodoParams() {
    const personalizado = document.getElementById('ponto-tipo-personalizado').checked;
    if (personalizado) {
        const inicio = document.getElementById('ponto-data-inicio').value;
        const fim    = document.getElementById('ponto-data-fim').value;
        if (!inicio || !fim) {
            _toast('Período personalizado: informe a Data Início e a Data Fim', 'error');
            document.getElementById('ponto-aviso-personalizado').style.display = '';
            return null;
        }
        if (inicio > fim) {
            _toast('A Data Início não pode ser maior que a Data Fim', 'error');
            return null;
        }
        return { tipo: 'personalizado', data_inicio: inicio, data_fim: fim };
    }
    return {
        tipo: 'mes',
        mes:  document.getElementById('ponto-mes').value,
        ano:  document.getElementById('ponto-ano').value
    };
}

function _abrirPontoColab(id, nome) {
    // Muda para aba ponto e pré-seleciona colaborador
    document.querySelectorAll('.page-recursos-humanos .tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.page-recursos-humanos .tab-content').forEach(t => t.classList.remove('active'));
    document.querySelector('.page-recursos-humanos [data-tab="ponto"]')?.classList.add('active');
    document.getElementById('tab-ponto')?.classList.add('active');
    _setSelect('ponto-colaborador-id', String(id));
    document.getElementById('tab-ponto').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function _abrirPeriodo() {
    const colab_id = document.getElementById('ponto-colaborador-id').value;
    if (!colab_id) return _toast('Selecione um colaborador', 'error');

    const params = _getPontoPeriodoParams();
    if (!params) return; // validação já exibiu o erro

    try {
        if (params.tipo === 'personalizado') {
            // ── Período personalizado ─────────────────────────────────────────────
            // Verifica se o intervalo cruza múltiplos meses
            const dtInicio = new Date(params.data_inicio + 'T00:00:00');
            const dtFim    = new Date(params.data_fim    + 'T00:00:00');
            const mesmoMes = dtInicio.getFullYear() === dtFim.getFullYear() &&
                             dtInicio.getMonth()    === dtFim.getMonth();

            console.log('[RH-Ponto] Período personalizado:', params.data_inicio, '–', params.data_fim,
                        '| Mesmo mês:', mesmoMes);

            // Salva o filtro no estado (usado em _carregarLancamentos e _exibirFolha)
            _state.filtroPersonalizado = { data_inicio: params.data_inicio, data_fim: params.data_fim };

            if (!mesmoMes) {
                // Filtro cruza múltiplos meses: usa endpoint por colaborador + intervalo de datas
                // Não precisa de periodo_id — usa um objeto virtual de período
                const r2 = await fetch(
                    `../api/api_rh_ponto.php?acao=listar_lancamentos_por_colaborador` +
                    `&colaborador_id=${colab_id}` +
                    `&data_inicio=${encodeURIComponent(params.data_inicio)}` +
                    `&data_fim=${encodeURIComponent(params.data_fim)}`,
                    { credentials: 'include' }
                );
                const d2 = await r2.json();
                if (!d2.sucesso) throw new Error(d2.mensagem);

                if (!d2.dados || d2.dados.length === 0) {
                    return _toast('Nenhum lançamento encontrado no intervalo informado. Verifique se os períodos mensais foram criados.', 'info');
                }

                // Monta período virtual para exibir a folha
                const colaboradorNome = document.getElementById('ponto-colaborador-id')
                    .options[document.getElementById('ponto-colaborador-id').selectedIndex]?.text ?? '';
                _state.periodoAtual = {
                    id: null, // sem periodo_id único (multi-mês)
                    colaborador_id: parseInt(colab_id),
                    colaborador_nome: colaboradorNome,
                    mes: dtInicio.getMonth() + 1,
                    ano: dtInicio.getFullYear(),
                    status: 'aberto',
                    total_horas_trabalhadas_min: 0,
                    total_horas_extras_min: 0,
                    total_atraso_min: 0,
                    total_faltas: 0,
                    total_folgas: 0,
                };

                // Calcula totais a partir dos lançamentos retornados
                d2.dados.forEach(l => {
                    _state.periodoAtual.total_horas_trabalhadas_min += parseInt(l.horas_trabalhadas_min) || 0;
                    _state.periodoAtual.total_horas_extras_min     += parseInt(l.horas_extras_min)     || 0;
                    _state.periodoAtual.total_atraso_min           += parseInt(l.atraso_min)           || 0;
                    if (l.tipo_dia === 'falta')  _state.periodoAtual.total_faltas++;
                    if (l.tipo_dia === 'folga')  _state.periodoAtual.total_folgas++;
                });

                // Exibe a folha com os lançamentos já carregados
                document.getElementById('ponto-seletor-card').style.display = 'none';
                document.getElementById('ponto-folha-wrap').style.display   = '';

                const fmtDate = (iso) => { const [y,m,d] = iso.split('-'); return `${d}/${m}/${y}`; };
                document.getElementById('ponto-header-nome').textContent = colaboradorNome;
                document.getElementById('ponto-header-meta').innerHTML   =
                    `${_esc(fmtDate(params.data_inicio))} – ${_esc(fmtDate(params.data_fim))} &nbsp;|&nbsp; <span class="status-aberto">Multi-mês</span>`;
                document.getElementById('btnPontoFechar').style.display  = 'none'; // não aplica a multi-mês
                document.getElementById('btnPontoReabrir').style.display = 'none';

                _atualizarTotais(_state.periodoAtual);
                _state.lancamentos = d2.dados;
                _renderLancamentos(true); // somente leitura para multi-mês
                return;
            }

            // Mesmo mês: busca o período mensal normalmente
            const r = await fetch(`../api/api_rh_ponto.php?acao=listar_periodos&colaborador_id=${colab_id}`, { credentials: 'include' });
            const d = await r.json();
            if (!d.sucesso) throw new Error(d.mensagem);

            const periodo = (d.dados ?? []).find(p => {
                const pInicio = new Date(p.ano, p.mes - 1, 1);
                const pFim    = new Date(p.ano, p.mes, 0);
                return dtInicio >= pInicio && dtInicio <= pFim;
            });
            if (!periodo) return _toast('Período não encontrado para o intervalo informado. Use o botão + para criar.', 'info');

            _state.periodoAtual = periodo;
            _exibirFolha(periodo);

        } else {
            // ── Período mensal ────────────────────────────────────────────────────
            _state.filtroPersonalizado = null;
            const r = await fetch(`../api/api_rh_ponto.php?acao=listar_periodos&colaborador_id=${colab_id}`, { credentials: 'include' });
            const d = await r.json();
            if (!d.sucesso) throw new Error(d.mensagem);

            const periodo = (d.dados ?? []).find(p => p.mes == params.mes && p.ano == params.ano);
            if (!periodo) return _toast('Período não encontrado. Use o botão + para criar.', 'info');

            _state.periodoAtual = periodo;
            _exibirFolha(periodo);
        }
    } catch (err) { _toast(err.message, 'error'); }
}

async function _criarPeriodo() {
    const colab_id = document.getElementById('ponto-colaborador-id').value;
    if (!colab_id) return _toast('Selecione um colaborador', 'error');

    const params = _getPontoPeriodoParams();
    if (!params) return;

    let mes, ano;
    if (params.tipo === 'personalizado') {
        // Deriva mês/ano a partir da data de início
        const d = new Date(params.data_inicio + 'T00:00:00');
        mes = d.getMonth() + 1;
        ano = d.getFullYear();
    } else {
        mes = parseInt(params.mes);
        ano = parseInt(params.ano);
    }

    try {
        const r = await fetch(`../api/api_rh_ponto.php?acao=criar_periodo`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ colaborador_id: parseInt(colab_id), mes, ano }),
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) _abrirPeriodo();
    } catch (err) { _toast(err.message, 'error'); }
}

async function _exibirFolha(periodo) {
    document.getElementById('ponto-seletor-card').style.display = 'none';
    const folha = document.getElementById('ponto-folha-wrap');
    folha.style.display = '';

    const meses = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    document.getElementById('ponto-header-nome').textContent = periodo.colaborador_nome ?? '—';

    // Exibe intervalo personalizado no header quando filtro personalizado está ativo
    let periodoLabel;
    if (_state.filtroPersonalizado?.data_inicio && _state.filtroPersonalizado?.data_fim) {
        const fmtDate = (iso) => {
            const [y, m, d] = iso.split('-');
            return `${d}/${m}/${y}`;
        };
        periodoLabel = `${fmtDate(_state.filtroPersonalizado.data_inicio)} – ${fmtDate(_state.filtroPersonalizado.data_fim)}`;
        console.log('[RH-Ponto] Exibindo folha com período personalizado:', periodoLabel);
    } else {
        periodoLabel = `${meses[periodo.mes]}/${periodo.ano}`;
    }
    const statusSeguro = ['aberto', 'fechado'].includes(periodo.status) ? periodo.status : 'desconhecido';
    document.getElementById('ponto-header-meta').innerHTML  =
        `${_esc(periodoLabel)} &nbsp;|&nbsp; <span class="status-${statusSeguro}">${statusSeguro === 'fechado' ? 'Fechado' : statusSeguro === 'aberto' ? 'Em aberto' : 'Status desconhecido'}</span>`;

    document.getElementById('btnPontoFechar').style.display    = periodo.status === 'aberto'  ? '' : 'none';
    document.getElementById('btnPontoReabrir').style.display   = periodo.status === 'fechado' ? '' : 'none';
    document.getElementById('btnPontoRecalcular').style.display = '';

    _atualizarTotais(periodo);
    await _carregarLancamentos(periodo.id, periodo.status === 'fechado');
}

function _atualizarTotais(p) {
    document.getElementById('pt-trabalhadas').textContent = _minParaHoras(p.total_horas_trabalhadas_min);
    document.getElementById('pt-extras').textContent      = _minParaHoras(p.total_horas_extras_min);
    document.getElementById('pt-atraso').textContent      = _minParaHoras(p.total_atraso_min);
    document.getElementById('pt-faltas').textContent      = p.total_faltas  ?? 0;
    document.getElementById('pt-folgas').textContent      = p.total_folgas  ?? 0;
}

async function _carregarLancamentos(periodo_id, readonly = false) {
    try {
        // Monta URL: se houver filtro personalizado ativo, passa data_inicio e data_fim
        let url = `../api/api_rh_ponto.php?acao=listar_lancamentos&periodo_id=${periodo_id}`;
        if (_state.filtroPersonalizado?.data_inicio && _state.filtroPersonalizado?.data_fim) {
            url += `&data_inicio=${encodeURIComponent(_state.filtroPersonalizado.data_inicio)}`;
            url += `&data_fim=${encodeURIComponent(_state.filtroPersonalizado.data_fim)}`;
            console.log('[RH-Ponto] Filtro personalizado ativo:', _state.filtroPersonalizado);
        } else {
            console.log('[RH-Ponto] Filtro mensal — carregando período completo:', periodo_id);
        }
        const r = await fetch(url, { credentials: 'include' });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);
        _state.lancamentos = d.dados ?? [];
        console.log('[RH-Ponto] Lançamentos carregados:', _state.lancamentos.length);
        _renderLancamentos(readonly);
    } catch (err) { _toast(err.message, 'error'); }
}

function _renderLancamentos(readonly) {
    const tbody = document.getElementById('ponto-tbody');
    if (!_state.lancamentos.length) {
        tbody.innerHTML = '<tr><td colspan="12" style="text-align:center;padding:20px;color:var(--text-secondary,#64748b);">Nenhum lançamento</td></tr>';
        return;
    }

    const TIPOS = ['normal','folga','falta','feriado','meio_periodo','afastamento','horas_extras'];
    const diasPt = { Monday:'Segunda', Tuesday:'Terça', Wednesday:'Quarta', Thursday:'Quinta', Friday:'Sexta', Saturday:'Sábado', Sunday:'Domingo' };
    const inteiroSeguro = valor => { const n = Number(valor); return Number.isSafeInteger(n) && n >= 0 ? n : 0; };
    const horaSegura = valor => { const h = String(valor ?? ''); return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(h) ? h : ''; };
    const periodoId = inteiroSeguro(_state.periodoAtual?.id);
    const colaboradorId = inteiroSeguro(_state.periodoAtual?.colaborador_id);

    tbody.innerHTML = _state.lancamentos.map(l => {
        const id = inteiroSeguro(l.id);
        if (!id || !colaboradorId || (!periodoId && !readonly)) return '';
        const tipo = TIPOS.includes(l.tipo_dia) ? l.tipo_dia : 'normal';
        const cls = tipo === 'horas_extras' ? 'dia-horas-extras'
                  : ['folga','falta','feriado'].includes(tipo) ? `dia-${tipo}` : '';
        const dis  = readonly ? 'disabled' : '';
        const trabalhadas = inteiroSeguro(l.horas_trabalhadas_min);
        const extras = inteiroSeguro(l.horas_extras_min);
        const atraso = inteiroSeguro(l.atraso_min);
        const trab = trabalhadas > 0 ? `<span class="${extras > 0 ? 'horas-extra' : ''}">${_minParaHoras(trabalhadas)}</span>` : '—';
        const ext  = extras > 0 ? `<span class="horas-extra">${_minParaHoras(extras)}</span>` : '—';
        const atr  = atraso > 0 ? `<span class="horas-atraso">${_minParaHoras(atraso)}</span>` : '—';
        const diaSemana = String(l.dia_semana ?? '');
        const diaName = diasPt[diaSemana] ?? diaSemana;
        const dataFmt = _esc(l.data_fmt || '—');
        const he = horaSegura(l.he), has = horaSegura(l.has), har = horaSegura(l.har), hs = horaSegura(l.hs);
        const dsrBadge = (tipo === 'falta' && diaSemana === 'Sunday')
            ? '<span style="margin-left:4px;font-size:10px;background:#dc2626;color:#fff;padding:1px 4px;border-radius:3px;vertical-align:middle;" title="DSR perdido — houve falta injustificada nesta semana">DSR↓</span>'
            : (tipo === 'folga' && diaSemana === 'Sunday')
            ? '<span style="margin-left:4px;font-size:10px;background:#16a34a;color:#fff;padding:1px 4px;border-radius:3px;vertical-align:middle;" title="DSR ganho — semana completa">DSR✓</span>'
            : '';
        const badgeD1 = he && hs && hs < he ? ' <span class="badge-d1" title="Saída no dia seguinte">D+1</span>' : '';

        return `<tr class="${cls}" data-id="${id}" data-periodo="${periodoId}" data-colab="${colaboradorId}">
            <td>${dataFmt}</td>
            <td class="dia-semana">${_esc(diaName || '—')}${dsrBadge}</td>
            <td>
                <select onchange="window.ModuleRH.salvarLinhaPonto(this)" data-campo="tipo_dia" ${dis}>
                    ${TIPOS.map(t => `<option value="${t}" ${tipo===t?'selected':''}>${_esc(_tipoDia(t))}</option>`).join('')}
                </select>
            </td>
            <td><input type="time" value="${_esc(he)}" data-campo="hora_entrada" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}></td>
            <td><input type="time" value="${_esc(has)}" data-campo="hora_almoco_saida" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}></td>
            <td><input type="time" value="${_esc(har)}" data-campo="hora_almoco_retorno" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}></td>
            <td style="white-space:nowrap;"><input type="time" value="${_esc(hs)}" data-campo="hora_saida" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}>${badgeD1}</td>
            <td>${trab}</td>
            <td>${ext}</td>
            <td>${atr}</td>
            <td style="max-width:100px;"><input type="text" value="${_esc(l.observacoes||'')}" placeholder="Obs" data-campo="observacoes" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis} style="width:100%;border:1px solid var(--border-color,#e2e8f0);border-radius:5px;padding:4px 6px;font-size:12px;"></td>
            <td></td>
        </tr>`;
    }).join('');
}

async function _salvarLinhaPonto(el) {
    const tr         = el.closest('tr');
    const periodo_id = parseInt(tr.dataset.periodo);
    const colab_id   = parseInt(tr.dataset.colab);
    const data       = _state.lancamentos.find(l => l.id == tr.dataset.id)?.data;
    if (!data) return;

    const campos = {};
    tr.querySelectorAll('[data-campo]').forEach(inp => { campos[inp.dataset.campo] = inp.value; });

    try {
        const r = await fetch('../api/api_rh_ponto.php?acao=salvar_lancamento', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ periodo_id, colaborador_id: colab_id, data, ...campos }),
        });
        const d = await r.json();
        if (!d.sucesso) { _toast(d.mensagem, 'error'); return; }
        // Atualizar totais sem re-renderizar tudo
        const calc = d.dados;
        const trabEl = tr.querySelector('td:nth-child(8)');
        const extEl  = tr.querySelector('td:nth-child(9)');
        const atrEl  = tr.querySelector('td:nth-child(10)');
        if (trabEl) trabEl.innerHTML = calc.trabalhadas > 0 ? `<span class="${calc.extras>0?'horas-extra':''}">${_minParaHoras(calc.trabalhadas)}</span>` : '—';
        if (extEl)  extEl.innerHTML  = calc.extras > 0  ? `<span class="horas-extra">${_minParaHoras(calc.extras)}</span>` : '—';
        if (atrEl)  atrEl.innerHTML  = calc.atraso > 0  ? `<span class="horas-atraso">${_minParaHoras(calc.atraso)}</span>` : '—';
        // Recarregar totalizadores do período
        _recarregarTotaisPeriodo(periodo_id);
    } catch (err) { _toast(err.message, 'error'); }
}

async function _recarregarTotaisPeriodo(periodo_id) {
    try {
        const r = await fetch(`../api/api_rh_ponto.php?acao=obter_periodo&id=${periodo_id}`, { credentials: 'include' });
        const d = await r.json();
        if (d.sucesso) { _state.periodoAtual = d.dados; _atualizarTotais(d.dados); }
    } catch {}
}

async function _fecharPeriodo() {
    if (!_state.periodoAtual) return;
    if (!confirm('Fechar este período? Não será mais possível lançar horas.')) return;
    try {
        const r = await fetch(`../api/api_rh_ponto.php?acao=fechar_periodo&id=${_state.periodoAtual.id}`, { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: '{}' });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) { _state.periodoAtual.status = 'fechado'; _exibirFolha(_state.periodoAtual); }
    } catch (err) { _toast(err.message, 'error'); }
}

async function _reabrirPeriodo() {
    if (!_state.periodoAtual) return;
    try {
        const r = await fetch(`../api/api_rh_ponto.php?acao=reabrir_periodo&id=${_state.periodoAtual.id}`, { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: '{}' });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) { _state.periodoAtual.status = 'aberto'; _exibirFolha(_state.periodoAtual); }
    } catch (err) { _toast(err.message, 'error'); }
}

async function _recalcularPeriodo() {
    if (!_state.periodoAtual) return;
    const btn = document.getElementById('btnPontoRecalcular');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recalculando…'; }
    try {
        const r = await fetch(
            `../api/api_rh_ponto.php?acao=recalcular_lancamentos&periodo_id=${_state.periodoAtual.id}`,
            { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: '{}' }
        );
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) {
            await _recarregarTotaisPeriodo(_state.periodoAtual.id);
            await _carregarLancamentos(_state.periodoAtual.id, _state.periodoAtual.status === 'fechado');
        }
    } catch (err) { _toast(err.message, 'error'); }
    finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync-alt"></i> Recalcular'; }
    }
}

function _fecharFolha() {
    _state.periodoAtual = null;
    document.getElementById('ponto-folha-wrap').style.display = 'none';
    document.getElementById('ponto-seletor-card').style.display = '';
}

// ────────────────────────────────────────────────────────────────────────────
// ABA: ESCALA
// ────────────────────────────────────────────────────────────────────────────
function _setupEscala() {
    document.getElementById('btnEscalaCarregar')?.addEventListener('click', _carregarEscalas);
    document.getElementById('btnEscalaNova')?.addEventListener('click', () => {
        if (_state.escalaTotal > 0) {
            _toast('Este colaborador já possui uma escala cadastrada. Edite a escala existente.', 'error');
            return;
        }
        _limparFormEscala();
        document.getElementById('escala-form-card').style.display = '';
        document.getElementById('escala-form-card').scrollIntoView({ behavior: 'smooth' });
    });
    document.getElementById('btnEscalaCancelar')?.addEventListener('click', () => {
        document.getElementById('escala-form-card').style.display = 'none';
    });
    document.getElementById('formEscala')?.addEventListener('submit', _salvarEscala);

    // Dias clicáveis (grid principal de dias de trabalho)
    document.querySelectorAll('.page-recursos-humanos #escala-dias-grid .dia-tag').forEach(tag => {
        tag.addEventListener('click', () => {
            tag.classList.toggle('ativo');
            _state.escalaDias = Array.from(document.querySelectorAll('.page-recursos-humanos #escala-dias-grid .dia-tag.ativo')).map(t => t.dataset.dia);
        });
    });

    // Inicializa bloco de escala alternada
    _setupEscalaAlternada();
}

async function _carregarEscalas() {
    const colab_id = document.getElementById('escala-colaborador-id').value;
    if (!colab_id) return _toast('Selecione um colaborador', 'error');

    const wrap = document.getElementById('escala-lista-wrap');
    const list = document.getElementById('escala-lista');
    wrap.style.display = '';
    list.innerHTML = '<div class="loading-msg"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

    try {
        const r = await fetch(`../api/api_rh_escala.php?acao=listar&colaborador_id=${colab_id}`, { credentials: 'include' });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);
        const escalas = d.dados ?? [];
        _state.escalaTotal = escalas.length;
        _atualizarBtnNovaEscala();
        _renderEscalas(escalas);
    } catch (err) { list.innerHTML = `<p style="color:#dc2626;padding:16px;">${_esc(err.message)}</p>`; }
}

function _atualizarBtnNovaEscala() {
    const btn = document.getElementById('btnEscalaNova');
    if (!btn) return;
    const temEscala = _state.escalaTotal > 0;
    btn.style.display = '';
    btn.disabled = temEscala;
    btn.title = temEscala ? 'Este colaborador já possui uma escala cadastrada. Edite a existente.' : 'Nova Escala';
    btn.style.opacity = temEscala ? '0.45' : '';
    btn.style.cursor = temEscala ? 'not-allowed' : '';
}

function _renderEscalas(list) {
    const container = document.getElementById('escala-lista');
    if (!list.length) { container.innerHTML = '<p style="padding:16px;color:var(--text-secondary,#64748b);">Nenhuma escala cadastrada.</p>'; return; }

    const diasPermitidos = ['seg','ter','qua','qui','sex','sab','dom'];
    const diasLabel = { seg:'Seg', ter:'Ter', qua:'Qua', qui:'Qui', sex:'Sex', sab:'Sáb', dom:'Dom' };
    const tiposPermitidos = ['livre','controle_jornada','alternada','jornada_44h','jornada_40h','jornada_36h','escala_manual'];
    const tipoLabels = {
        livre:'Livre', controle_jornada:'Controle de Jornada', alternada:'Escala Alternada',
        jornada_44h:'44h/sem — 220h/mês', jornada_40h:'40h/sem — 200h/mês', jornada_36h:'36h/sem — 180h/mês',
        escala_manual:'Escala Manual',
    };
    const parseDias = valor => {
        try {
            const parsed = typeof valor === 'string' ? JSON.parse(valor || '[]') : valor;
            return Array.isArray(parsed) ? parsed.filter(d => diasPermitidos.includes(d)) : [];
        } catch { return []; }
    };
    const numero = valor => { const n = Number(valor); return Number.isSafeInteger(n) && n >= 0 ? n : 0; };
    const hora = valor => { const h = String(valor ?? '').slice(0, 5); return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(h) ? _esc(h) : '—'; };

    container.innerHTML = list.map(e => {
        const id = Number.parseInt(e.id, 10);
        if (!Number.isSafeInteger(id) || id <= 0) return '';
        const tipo = tiposPermitidos.includes(e.tipo) ? e.tipo : '';
        const dias  = parseDias(e.dias_trabalho);
        const tipoLabel = tipoLabels[tipo] ?? 'Não classificado';
        const isAlt = tipo === 'alternada' || Number(e.alternada_ativa) === 1;

        // Bloco de semanas A e B para escala alternada
        let altHtml = '';
        if (isAlt) {
            const semA = parseDias(e.alternada_semana_a);
            const semB = parseDias(e.alternada_semana_b);
            altHtml = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px;">
                <div>
                    <div style="font-size:11px;font-weight:600;color:#0369a1;margin-bottom:4px;">Semana A</div>
                    <div class="dias-semana-grid" style="pointer-events:none;">
                        ${diasPermitidos.map(d => `<span class="dia-tag ${semA.includes(d)?'ativo':''}">${_esc(diasLabel[d])}</span>`).join('')}
                    </div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#7c3aed;margin-bottom:4px;">Semana B</div>
                    <div class="dias-semana-grid" style="pointer-events:none;">
                        ${diasPermitidos.map(d => `<span class="dia-tag ${semB.includes(d)?'ativo':''}">${_esc(diasLabel[d])}</span>`).join('')}
                    </div>
                </div>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;">
                <i class="fas fa-calendar-day"></i> Início Semana A: <strong>${_esc(e.alternada_dia_inicio || '—')}</strong> &nbsp;|
                Folga alternada: <strong>${_esc(e.alternada_tipo_folga || 'folga')}</strong>
            </div>`;
        }

        const preset = _CLT_JORNADAS[tipo] ?? null;
        const isJorn = !!preset;
        // Carga diária: para jornadas padrão mostra "7h20min", para outras arredonda
        const cargaMin = numero(e.carga_horaria_diaria_min ?? 480);
        const cargaLabel = isJorn
            ? `${Math.floor(cargaMin/60)}h${cargaMin%60>0?String(cargaMin%60).padStart(2,'0')+'min':''}/${numero(e.carga_horaria_mensal_min) ? Math.round(numero(e.carga_horaria_mensal_min)/60)+'h/mês' : preset.cargaMensalH+'h/mês'}`
            : `${Math.floor(cargaMin/60)}h carga`;
        // DSR: dias que NÃO estão em dias_trabalho (exceto para alternada)
        const dsrLabel = !isAlt && isJorn
            ? `DSR: ${diasPermitidos.filter(d=>!dias.includes(d)).map(d=>diasLabel[d]).join(', ')||'—'}`
            : '';

        return `
        <div class="escala-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                <div>
                    <div style="font-weight:700;font-size:14px;">${_esc(e.nome_escala || 'Escala')}</div>
                    <span class="escala-tipo escala-tipo-${tipo}">${_esc(tipoLabel)}</span>
                    ${isJorn ? `<span style="margin-left:6px;font-size:10px;font-weight:700;padding:2px 6px;background:#fde68a;color:#78350f;border-radius:4px;">CLT</span>` : ''}
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="action-btn" type="button" onclick="window.ModuleRH.editarEscala(${id})"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" type="button" onclick="window.ModuleRH.excluirEscala(${id})"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            ${!isAlt ? `
            <div style="margin:6px 0 4px;font-size:12px;color:var(--text-secondary,#64748b);">Dias de trabalho:</div>
            <div class="dias-semana-grid" style="pointer-events:none;">
                ${diasPermitidos.map(d => `<span class="dia-tag ${dias.includes(d)?'ativo':''}" title="${!dias.includes(d)&&isJorn?'DSR – Descanso Semanal Remunerado':''}">${_esc(diasLabel[d])}</span>`).join('')}
            </div>
            ${dsrLabel ? `<div style="font-size:11px;color:#92400e;margin-top:4px;"><i class="fas fa-moon"></i> ${dsrLabel} — remunerado, sem débito de horas</div>` : ''}` : altHtml}
            <div class="escala-horarios" style="margin-top:8px;">
                <span><i class="fas fa-sign-in-alt"></i> ${hora(e.hora_entrada)}</span>
                <span><i class="fas fa-utensils"></i> ${hora(e.hora_almoco_saida)} – ${hora(e.hora_almoco_retorno)}</span>
                <span><i class="fas fa-sign-out-alt"></i> ${hora(e.hora_saida)}</span>
                <span><i class="fas fa-clock"></i> ${_esc(cargaLabel)} / ${Math.min(1440, numero(e.tolerancia_minutos ?? 10))}min tolerância</span>
            </div>
        </div>`;
    }).join('');
}

async function _editarEscala(id) {
    try {
        const r = await fetch(`../api/api_rh_escala.php?acao=obter&id=${id}`, { credentials: 'include' });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);
        const e = d.dados;
        document.getElementById('escala-id').value            = e.id;
        document.getElementById('escala-nome').value          = e.nome_escala ?? '';
        _setSelect('escala-tipo', e.tipo);
        // Para jornadas CLT pré-definidas, exibe o valor exato do preset (ex: 7.333 para 44h)
        document.getElementById('escala-carga-h').value       = _CLT_JORNADAS[e.tipo]
            ? Math.round(_CLT_JORNADAS[e.tipo].cargaH * 1000) / 1000
            : Math.round((e.carga_horaria_diaria_min??480)/60*2)/2;
        document.getElementById('escala-tolerancia').value    = e.tolerancia_minutos ?? 10;
        document.getElementById('escala-entrada').value       = (e.hora_entrada??'08:00:00').slice(0,5);
        document.getElementById('escala-almoco-saida').value  = (e.hora_almoco_saida??'12:00:00').slice(0,5);
        document.getElementById('escala-almoco-retorno').value = (e.hora_almoco_retorno??'13:00:00').slice(0,5);
        document.getElementById('escala-saida').value         = (e.hora_saida??'17:00:00').slice(0,5);
        document.getElementById('escala-intervalo').value     = e.intervalo_almoco_min ?? 60;

        const dias = JSON.parse(e.dias_trabalho || '[]');
        document.querySelectorAll('.page-recursos-humanos #escala-dias-grid .dia-tag').forEach(t => {
            t.classList.toggle('ativo', dias.includes(t.dataset.dia));
        });
        _state.escalaDias = dias;

        // Preenche campos de escala alternada (se aplicável)
        const tipoSel = document.getElementById('escala-tipo');
        if (tipoSel) tipoSel.value = e.tipo ?? 'livre';
        // Dispara o toggle do bloco alternado
        tipoSel?.dispatchEvent(new Event('change'));

        if (e.tipo === 'alternada' || e.alternada_ativa) {
            const altInicio = document.getElementById('escala-alt-inicio');
            if (altInicio && e.alternada_dia_inicio) altInicio.value = e.alternada_dia_inicio;

            const altTipoFolga = document.getElementById('escala-alt-tipo-folga');
            if (altTipoFolga && e.alternada_tipo_folga) altTipoFolga.value = e.alternada_tipo_folga;

            const semA = typeof e.alternada_semana_a === 'string' ? JSON.parse(e.alternada_semana_a || '[]') : (e.alternada_semana_a ?? []);
            const semB = typeof e.alternada_semana_b === 'string' ? JSON.parse(e.alternada_semana_b || '[]') : (e.alternada_semana_b ?? []);
            _setDiasAlternados('escala-alt-semana-a', semA);
            _setDiasAlternados('escala-alt-semana-b', semB);
            // Atualiza carga mensal e preview com os dados carregados
            setTimeout(() => { _atualizarCargaMensal(); _atualizarPreviewAlternada(); }, 50);
        }

        // Preenche campos v2 (regime 12x36, descanso, carga mensal)
        const chk12x36 = document.getElementById('escala-regime-12x36');
        if (chk12x36) {
            chk12x36.checked = e.regime_12x36 == 1;
            chk12x36.dispatchEvent(new Event('change'));
        }
        const inpDescanso = document.getElementById('escala-descanso-h');
        if (inpDescanso && e.descanso_interjornada_min) {
            inpDescanso.value = Math.round((e.descanso_interjornada_min / 60) * 2) / 2;
        }
        const inpMensal = document.getElementById('escala-carga-mensal-h');
        if (inpMensal && e.carga_horaria_mensal_min) {
            inpMensal.value = Math.round((e.carga_horaria_mensal_min / 60) * 2) / 2;
        }

        const chkBH = document.getElementById('escala-banco-horas-ativo');
        if (chkBH) chkBH.checked = !!parseInt(e.banco_horas_ativo ?? 0);
        const politicaExtra = document.getElementById('escala-politica-extra');
        if (politicaExtra) politicaExtra.value = e.politica_extra_excedente || 'CREDITAR_TUDO';
        const limiteExtra = document.getElementById('escala-limite-extra-diario');
        if (limiteExtra) limiteExtra.value = e.limite_extra_diario_min ?? 120;
        const limiteBH = document.getElementById('escala-limite-saldo-negativo');
        if (limiteBH) limiteBH.value = e.limite_saldo_negativo_min ?? '';

        // Escala manual: popula a tabela por dia da semana
        if (e.tipo === 'escala_manual') {
            let semana = {};
            try { semana = JSON.parse(e.escala_manual_semana || '{}'); } catch {}
            setTimeout(() => _renderEscalaManualRows(semana), 50);
        }

        document.getElementById('escala-form-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar Escala';
        document.getElementById('escala-form-card').style.display = '';
        document.getElementById('escala-form-card').scrollIntoView({ behavior: 'smooth' });
    } catch (err) { _toast(err.message, 'error'); }
}

async function _excluirEscala(id) {
    if (!confirm('Remover esta escala?')) return;
    try {
        const r = await fetch('../api/api_rh_escala.php?acao=excluir', {
            method: 'DELETE', credentials: 'include',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) _carregarEscalas();
    } catch (err) { _toast(err.message, 'error'); }
}

async function _salvarEscala(e) {
    e.preventDefault();
    if (_state.escalaSalvando) return;
    const limiteNegativoRaw = document.getElementById('escala-limite-saldo-negativo')?.value ?? '';
    if (limiteNegativoRaw !== '' && (!/^\d+$/.test(limiteNegativoRaw) || !Number.isSafeInteger(Number(limiteNegativoRaw)) || Number(limiteNegativoRaw) > 2147483647)) {
        return _toast('Informe o limite negativo como minutos inteiros entre 0 e 2.147.483.647, ou deixe vazio.', 'error');
    }
    const limiteExtraRaw = document.getElementById('escala-limite-extra-diario')?.value ?? '120';
    const politicaExtra = document.getElementById('escala-politica-extra')?.value ?? 'CREDITAR_TUDO';
    if (!['CREDITAR_TUDO', 'LIMITAR_E_SEPARAR'].includes(politicaExtra) || !/^\d+$/.test(limiteExtraRaw) ||
        !Number.isSafeInteger(Number(limiteExtraRaw)) || Number(limiteExtraRaw) < 1 || Number(limiteExtraRaw) > 2147483647) {
        return _toast('Política ou limite diário de extras inválido.', 'error');
    }
    _state.escalaSalvando = true;
    const btnSubmit = document.querySelector('#formEscala [type="submit"]');
    if (btnSubmit) { btnSubmit.disabled = true; btnSubmit.textContent = 'Salvando…'; }

    const id   = document.getElementById('escala-id').value;
    const acao = id ? 'atualizar' : 'criar';
    const carga_h = parseFloat(document.getElementById('escala-carga-h').value) || 8;
    const tipo    = document.getElementById('escala-tipo').value;
    const isAlt   = tipo === 'alternada';
    const isManual = tipo === 'escala_manual';

    // Novos campos de jornada
    const descanso_h  = parseFloat(document.getElementById('escala-descanso-h')?.value || 0);
    const mensal_h    = parseFloat(document.getElementById('escala-carga-mensal-h')?.value || 0);
    const is12x36     = document.getElementById('escala-regime-12x36')?.checked ?? false;

    const payload = {
        colaborador_id             : parseInt(document.getElementById('escala-colaborador-id').value),
        nome_escala                : document.getElementById('escala-nome').value,
        tipo,
        carga_horaria_diaria_min   : Math.round(carga_h * 60),
        dias_trabalho              : _state.escalaDias,
        hora_entrada               : document.getElementById('escala-entrada').value + ':00',
        hora_almoco_saida          : document.getElementById('escala-almoco-saida').value + ':00',
        hora_almoco_retorno        : document.getElementById('escala-almoco-retorno').value + ':00',
        hora_saida                 : document.getElementById('escala-saida').value + ':00',
        tolerancia_minutos         : parseInt(document.getElementById('escala-tolerancia').value),
        intervalo_almoco_min       : parseInt(document.getElementById('escala-intervalo').value),
        // Campos v2 — regras de jornada
        regime_12x36               : is12x36 ? 1 : 0,
        descanso_interjornada_min  : Math.round(descanso_h * 60),
        // Para jornadas CLT padrão: mensal fixo pelo preset (PHP também garante)
        carga_horaria_mensal_min   : _CLT_JORNADAS[tipo]
            ? _CLT_JORNADAS[tipo].cargaMensalH * 60
            : Math.round(mensal_h * 60),
        // Banco de horas (controle_jornada e jornadas CLT padrão)
        banco_horas_ativo          : (document.getElementById('escala-banco-horas-ativo')?.checked && (tipo === 'controle_jornada' || !!_CLT_JORNADAS[tipo] || isManual)) ? 1 : 0,
        limite_saldo_negativo_min : limiteNegativoRaw === ''
            ? null
            : Number.parseInt(limiteNegativoRaw, 10),
        politica_extra_excedente  : politicaExtra,
        limite_extra_diario_min   : Number.parseInt(limiteExtraRaw, 10),
    };

    // Campos de escala manual
    if (isManual) {
        const semana = _escalaManualGetSemana();
        const temAtivo = Object.values(semana).some(c => c.ativo);
        if (!temAtivo) { _toast('Ative pelo menos um dia de trabalho na escala manual', 'error'); return; }
        payload.escala_manual_semana = JSON.stringify(semana);
    }

    // Campos de escala alternada
    if (isAlt) {
        const altInicio = document.getElementById('escala-alt-inicio')?.value;
        if (!altInicio) { _toast('Informe a data de início da Semana A', 'error'); return; }
        payload.alternada_dia_inicio  = altInicio;
        payload.alternada_tipo_folga  = document.getElementById('escala-alt-tipo-folga')?.value ?? 'folga';
        payload.alternada_semana_a    = _getDiasAlternados('escala-alt-semana-a');
        payload.alternada_semana_b    = _getDiasAlternados('escala-alt-semana-b');
        if (!payload.alternada_semana_a.length || !payload.alternada_semana_b.length) {
            _toast('Selecione pelo menos um dia em cada semana (A e B)', 'error'); return;
        }
    }

    if (id) payload.id = parseInt(id);

    try {
        const url = `../api/api_rh_escala.php?acao=${acao}${id ? '&id=' + id : ''}`;
        const r   = await fetch(url, { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
        const d   = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) { _limparFormEscala(); _carregarEscalas(); }
    } catch (err) { _toast(err.message, 'error'); }
    finally {
        _state.escalaSalvando = false;
        if (btnSubmit) { btnSubmit.disabled = false; btnSubmit.textContent = 'Salvar Escala'; }
    }
}

function _limparFormEscala() {
    document.getElementById('formEscala').reset();
    document.getElementById('escala-id').value = '';
    document.querySelectorAll('.page-recursos-humanos #escala-dias-grid .dia-tag').forEach(t => {
        t.classList.toggle('ativo', ['seg','ter','qua','qui','sex'].includes(t.dataset.dia));
    });
    _state.escalaDias = ['seg','ter','qua','qui','sex'];
    document.getElementById('escala-form-titulo').innerHTML = '<i class="fas fa-plus-circle"></i> Nova Escala';
    document.getElementById('escala-form-card').style.display = 'none';
}

// ────────────────────────────────────────────────────────────────────────────
// ABA: RELATÓRIOS
// ────────────────────────────────────────────────────────────────────────────
function _setupRelatorios() {
    const hoje = new Date();
    document.getElementById('rel-mes').value = String(hoje.getMonth() + 1);
    document.getElementById('rel-ano').value = String(hoje.getFullYear());

    // Alternar campos ao mudar tipo de período nos relatórios
    document.querySelectorAll('input[name="rel-tipo-periodo"]').forEach(radio => {
        radio.addEventListener('change', () => {
            const personalizado = document.getElementById('rel-tipo-personalizado').checked;
            document.getElementById('rel-campo-mes').style.display          = personalizado ? 'none' : '';
            document.getElementById('rel-campo-ano').style.display          = personalizado ? 'none' : '';
            document.getElementById('rel-campo-data-inicio').style.display  = personalizado ? '' : 'none';
            document.getElementById('rel-campo-data-fim').style.display     = personalizado ? '' : 'none';
            document.getElementById('rel-aviso-personalizado').style.display = personalizado ? '' : 'none';
            if (personalizado) {
                const y = hoje.getFullYear(), m = String(hoje.getMonth()+1).padStart(2,'0');
                if (!document.getElementById('rel-data-inicio').value)
                    document.getElementById('rel-data-inicio').value = `${y}-${m}-01`;
                if (!document.getElementById('rel-data-fim').value) {
                    const ultimo = new Date(y, hoje.getMonth()+1, 0).getDate();
                    document.getElementById('rel-data-fim').value = `${y}-${m}-${String(ultimo).padStart(2,'0')}`;
                }
            }
        });
    });
    _setupBHPainel();
    _setupBHReprocessar();
}

function _setupBHPainel() {
    const input = document.getElementById('bh-reg-minutos');
    const tipo = document.getElementById('bh-reg-tipo');
    input?.addEventListener('input', _atualizarBHPrevia);
    tipo?.addEventListener('change', _atualizarBHPrevia);
    document.getElementById('bh-negativo-cancelar')?.addEventListener('click', _fecharBHModal);
    document.getElementById('bh-negativo-confirmar')?.addEventListener('click', () => {
        if (!_bhPendingRequest || _bhSubmitting) return;
        const justificativa = document.getElementById('bh-negativo-justificativa')?.value?.trim() ?? '';
        const marcado = document.getElementById('bh-negativo-confirmar-checkbox')?.checked === true;
        if (justificativa.length < 10 || justificativa.length > 500) {
            _toast('A justificativa deve ter entre 10 e 500 caracteres.', 'error');
            return;
        }
        if (!marcado) {
            _toast('Marque a confirmação do lançamento com saldo negativo.', 'error');
            return;
        }
        _enviarBH(_bhPendingRequest, true, justificativa);
    });
    document.getElementById('bh-negativo-modal')?.addEventListener('click', event => {
        if (event.target?.id === 'bh-negativo-modal') _fecharBHModal();
    });
    document.addEventListener('keydown', _bhEscapeListener);
    _atualizarBHPrevia();
}

function _bhUsuarioAdminAtual() {
    if (window.PermissoesModulos?.isAdmin?.()) return true;
    if (typeof window.verificarPermissao === 'function') {
        try { return window.verificarPermissao('admin') === true; } catch { return false; }
    }
    return _bhServerIsAdmin === true;
}

async function _bhCarregarPermissaoServidor() {
    if (_bhPermissionRequest) return _bhPermissionRequest;
    _bhPermissionRequest = (async () => {
        try {
            const response = await fetch('../api/api_rh_banco_horas.php?acao=permissao_reprocessar', { credentials: 'include', cache: 'no-store' });
            const result = await response.json();
            _bhServerCanEdit = response.ok && result?.sucesso ? result.dados?.pode_editar === true : false;
            _bhServerIsAdmin = response.ok && result?.sucesso ? result.dados?.pode_admin === true : false;
        } catch { _bhServerCanEdit = false; _bhServerIsAdmin = false; }
        _bhPermissionRequest = null;
        _atualizarBtnBHReprocessar();
    })();
    return _bhPermissionRequest;
}

function _setupBHReprocessar() {
    const button = document.getElementById('btn-bh-reprocessar');
    button?.addEventListener('click', _iniciarReprocessamentoBH);
    document.getElementById('bh-reprocessar-aplicar')?.addEventListener('click', _aplicarReprocessamentoBH);
    document.getElementById('bh-reprocessar-cancelar')?.addEventListener('click', _fecharReprocessamentoBH);
    document.getElementById('bh-reprocessar-incluir-fechados')?.addEventListener('change', _bhRepreviewFechados);
    document.getElementById('bh-reprocessar-modal')?.addEventListener('click', event => {
        if (event.target?.id === 'bh-reprocessar-modal' && !_bhReprocessApplying) _fecharReprocessamentoBH();
    });
    document.getElementById('bh-reprocessar-so-divergencias')?.addEventListener('change', _renderBHReprocessTabela);
    ['bh-reprocessar-motivo','bh-reprocessar-revisei'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', _atualizarEstadoAplicarBH);
        document.getElementById(id)?.addEventListener('change', _atualizarEstadoAplicarBH);
    });
    ['rel-mes','rel-ano','rel-data-inicio','rel-data-fim','rel-departamento','rel-colaborador-id'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', _atualizarBtnBHReprocessar);
        document.getElementById(id)?.addEventListener('input', _atualizarBtnBHReprocessar);
    });
    document.querySelectorAll('input[name="rel-tipo-periodo"]').forEach(el => el.addEventListener('change', _atualizarBtnBHReprocessar));
    document.addEventListener('keydown', _bhReprocessEscapeListener);
    const fechado = document.getElementById('bh-reprocessar-fechados-wrap');
    if (fechado) fechado.style.display = _bhUsuarioAdminAtual() ? '' : 'none';
    _atualizarBtnBHReprocessar();
}

function _atualizarBtnBHReprocessar() {
    const btn = document.getElementById('btn-bh-reprocessar');
    if (!btn) return;
    const perms = window.PermissoesModulos;
    const isAdmin = _bhUsuarioAdminAtual();
    const fechado = document.getElementById('bh-reprocessar-fechados-wrap');
    if (fechado) fechado.style.display = isAdmin ? '' : 'none';
    const autorizado = isAdmin || _bhServerCanEdit === true || !!perms?.temPermissao?.('recursos_humanos', 'pode_editar');
    if (_bhServerCanEdit === null) _bhCarregarPermissaoServidor();
    const colab = document.getElementById('rel-colaborador-id')?.value || '';
    const dept = document.getElementById('rel-departamento')?.value || '';
    const personalizado = document.getElementById('rel-tipo-personalizado')?.checked === true;
    const inicio = document.getElementById('rel-data-inicio')?.value || '';
    const fim = document.getElementById('rel-data-fim')?.value || '';
    const mes = Number(document.getElementById('rel-mes')?.value || 0);
    const ano = Number(document.getElementById('rel-ano')?.value || 0);
    const dataValida = personalizado ? /^\d{4}-\d{2}-\d{2}$/.test(inicio) && /^\d{4}-\d{2}-\d{2}$/.test(fim) && inicio <= fim : mes >= 1 && mes <= 12 && ano >= 2000 && ano <= 2099;
    btn.style.display = autorizado ? '' : 'none';
    btn.disabled = !autorizado || !dataValida || (!colab && !dept) || _bhReprocessApplying;
    btn.title = !colab && !dept ? 'Selecione um colaborador ou departamento para habilitar' : 'Reprocessar o banco de horas do período com a regra atual';
}

function _bhReprocessEscapeListener(event) {
    if (event.key === 'Escape' && document.getElementById('bh-reprocessar-modal')?.style.display !== 'none' && !_bhReprocessApplying) _fecharReprocessamentoBH();
}

async function _bhRepreviewFechados() {
    if (!_bhReprocessPreview || _bhReprocessApplying) return;
    const filters = _bhReprocessFiltros(Number(_bhReprocessPreview.lote?.offset || 0));
    if (!filters) return;
    const progress = document.getElementById('bh-reprocessar-progresso');
    if (progress) progress.textContent = 'Atualizando a prévia para a opção de períodos fechados…';
    try {
        const preview = await _bhReprocessRequest('simular', filters);
        if (_bhReprocessProgress) _bhReprocessProgress.motivo = document.getElementById('bh-reprocessar-motivo')?.value || '';
        _mostrarPreviewBH(preview);
    } catch (error) {
        _toast(error.message, 'error');
    }
}

function _bhReprocessFiltros(offset = 0) {
    const periodo = _getRelPeriodoParams();
    if (!periodo) return null;
    const colab = document.getElementById('rel-colaborador-id')?.value || '';
    const dept = document.getElementById('rel-departamento')?.value || '';
    if (!colab && !dept) { _toast('Selecione um colaborador ou departamento.', 'error'); return null; }
    return {
        ...(periodo.tipo === 'personalizado'
            ? { data_inicio: periodo.data_inicio, data_fim: periodo.data_fim }
            : { mes: Number(periodo.mes), ano: Number(periodo.ano) }),
        ...(colab ? { colaborador_id: Number(colab) } : { departamento: dept }),
        offset,
        incluir_fechados: document.getElementById('bh-reprocessar-incluir-fechados')?.checked === true,
    };
}

async function _bhReprocessRequest(modo, payload) {
    const response = await fetch('../api/api_rh_banco_horas.php?acao=reprocessar', {
        method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, modo }),
    });
    const result = await response.json();
    if (!response.ok || !result?.sucesso) {
        const error = new Error(result?.mensagem || 'Não foi possível reprocessar o Banco de Horas.');
        error.status = response.status;
        error.codigo = result?.codigo;
        throw error;
    }
    return result.dados || {};
}

function _bhSignedMinutes(value) {
    const n = Number(value) || 0;
    return `${n > 0 ? '+' : ''}${_bhFmt(n)}`;
}

function _bhReprocessFilterLabel(preview) {
    const f = preview?.filtro || {};
    const periodo = f.data_inicio && f.data_fim ? `${f.data_inicio} a ${f.data_fim}` : `${_nomeMes(String(f.mes))}/${f.ano}`;
    const target = f.colaborador_id
        ? (preview.colaboradores?.[0]?.nome || `Colaborador #${f.colaborador_id}`)
        : `Departamento ${f.departamento || ''}`;
    return `${target} · ${periodo}`;
}

function _mostrarPreviewBH(preview) {
    _bhReprocessPreview = preview;
    const modal = document.getElementById('bh-reprocessar-modal');
    if (!modal) return;
    const lote = preview.lote || {};
    const start = Number(lote.offset || 0);
    const done = Math.min(start + Number(lote.limite || 20), Number(lote.total_colaboradores || 0));
    const progress = document.getElementById('bh-reprocessar-progresso');
    if (progress) {
        const total = Number(lote.total_colaboradores || 0);
        progress.textContent = total > 20 || start > 0 ? `Lote ${Math.floor(start / 20) + 1} · colaboradores ${start + 1}–${done} de ${total}` : `${total} colaborador(es) no escopo`;
    }
    const subtitle = document.getElementById('bh-reprocessar-subtitulo');
    if (subtitle) subtitle.textContent = `${_bhReprocessFilterLabel(preview)}. Esta prévia não alterou os dados.`;
    const totals = preview.totais || {};
    const kpis = [
        ['Extras no espelho', _bhFmt(totals.extra_espelho)],
        ['Abono de extras', `−${_bhFmt(totals.extra_abonado)}`],
        ['Crédito atual → esperado', `${_bhFmt(totals.credito_atual)} → ${_bhFmt(totals.credito_esperado)}`],
        ['Débito atual → esperado', `${_bhFmt(totals.debito_atual)} → ${_bhFmt(totals.debito_esperado)}`],
        ['Excedente separado', `${_bhFmt(totals.excedente_atual)} → ${_bhFmt(totals.excedente_esperado)}`],
        ['Saldo atual → após reprocessar', `${_bhFmt(totals.saldo_atual)} → ${_bhFmt(totals.saldo_apos_reprocessar)}`],
    ];
    const kpiEl = document.getElementById('bh-reprocessar-kpis');
    if (kpiEl) kpiEl.innerHTML = kpis.map(([label, value]) => `<div class="bh-reprocessar-kpi"><span>${_esc(label)}</span><strong>${_esc(value)}</strong></div>`).join('');
    const warnings = [];
    const alertCount = (preview.lancamentos || []).reduce((sum, day) => sum + (day.alertas?.length || 0), 0);
    if (alertCount) warnings.push(`${alertCount} dia(s) acima do limite diário configurado; nenhum minuto será descartado por esse alerta.`);
    const closedCount = (preview.lancamentos || []).filter(day => day.status === 'PERIODO_FECHADO').length;
    const noScaleCount = (preview.lancamentos || []).filter(day => day.status === 'SEM_ESCALA_BH').length;
    if (closedCount) warnings.push(`${closedCount} dia(s) em período fechado serão ignorados por padrão.`);
    if (noScaleCount) warnings.push(`${noScaleCount} dia(s) sem escala com Banco de Horas ativo não podem ser regerados.`);
    if (preview.ignorados?.length) warnings.push(`${preview.ignorados.length} item(ns) ignorados ou sem período/escala, detalhados na tabela quando aplicável.`);
    const alertEl = document.getElementById('bh-reprocessar-alertas');
    if (alertEl) alertEl.textContent = warnings.join(' ');
    const includeWrap = document.getElementById('bh-reprocessar-fechados-wrap');
    if (includeWrap) includeWrap.style.display = _bhUsuarioAdminAtual() ? '' : 'none';
    const includeClosed = document.getElementById('bh-reprocessar-incluir-fechados');
    if (includeClosed) includeClosed.checked = preview.filtro?.incluir_fechados === true;
    const onlyDiff = document.getElementById('bh-reprocessar-so-divergencias');
    if (onlyDiff) onlyDiff.checked = true;
    const reviewed = document.getElementById('bh-reprocessar-revisei');
    if (reviewed) reviewed.checked = false;
    const reason = document.getElementById('bh-reprocessar-motivo');
    if (reason && !_bhReprocessProgress?.motivo) reason.value = '';
    _renderBHReprocessTabela();
    _atualizarEstadoAplicarBH();
    modal.style.display = 'flex';
}

function _renderBHReprocessTabela() {
    const host = document.getElementById('bh-reprocessar-tabela');
    if (!host || !_bhReprocessPreview) return;
    const onlyDiff = document.getElementById('bh-reprocessar-so-divergencias')?.checked === true;
    const rows = _bhReprocessPreview.lancamentos || [];
    const visible = rows.filter(row => !onlyDiff || row.status !== 'OK');
    const dateFmt = value => {
        const d = new Date(`${value}T00:00:00`);
        return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('pt-BR');
    };
    if (!visible.length) {
        host.innerHTML = '<p style="padding:12px;color:#64748b;">Nenhuma divergência nesta prévia.</p>';
        return;
    }
    host.innerHTML = `<table><thead><tr><th>Data / Colaborador</th><th>Tipo</th><th>Batidas</th><th>Carga</th><th>Trabalhado</th><th>Extra</th><th>Atraso</th><th>Abonos</th><th>Crédito atual → esperado</th><th>Débito atual → esperado</th><th>Excedente</th><th>Saldo Δ</th><th>Status</th></tr></thead><tbody>${visible.map(row => {
        const status = String(row.status || 'DIVERGENTE');
        const cls = status === 'OK' ? '' : (['PERIODO_FECHADO','SEM_ESCALA_BH'].includes(status) ? 'is-ignored' : 'is-divergent');
        const type = row.tipo_dia || 'normal';
        const delta = Number(row.diferenca) || 0;
        const deltaClass = delta > 0 ? 'bh-diff-positive' : (delta < 0 ? 'bh-diff-negative' : '');
        const warnings = (row.alertas || []).length ? ` · ${row.alertas.join(', ')}` : '';
        const batidas = Object.values(row.batidas || {}).map(value => value || '—').join(' · ');
        const abonos = [row.abonos?.extras && row.abonos.extras !== 'nenhum' && `Extras ${_bhFmt(row.abonos.extras_min)}`, row.abonos?.atraso && row.abonos.atraso !== 'nenhum' && `Atraso ${_bhFmt(row.abonos.atraso_min)}`, row.abonos?.falta ? 'Falta' : ''].filter(Boolean).join(' · ') || '—';
        return `<tr class="${cls}">
            <td>${_esc(dateFmt(row.data))}<br><small>${_esc(row.colaborador_nome || '')}</small></td>
            <td>${_esc(type)}</td><td>${_esc(batidas)}</td><td>${_esc(_bhFmt(row.carga_prevista))}</td>
            <td>${_esc(_bhFmt(row.trabalhado))}</td><td>${_esc(_bhFmt(row.extra_espelho))}</td><td>${_esc(_bhFmt(row.atraso_espelho))}</td><td>${_esc(abonos)}</td>
            <td>${_esc(_bhFmt(row.credito_atual))} → ${_esc(_bhFmt(row.credito_esperado))}</td>
            <td>${_esc(_bhFmt(row.debito_atual))} → ${_esc(_bhFmt(row.debito_esperado))}</td>
            <td>${_esc(_bhFmt(row.excedente_atual))} → ${_esc(_bhFmt(row.excedente))}</td>
            <td class="${deltaClass}">${_esc(_bhSignedMinutes(delta))}</td>
            <td class="bh-status bh-status-${_esc(status)}" title="${_esc(`${row.regra_aplicada || ''}${warnings}`)}">${_esc(status)}${row.impacto_dsr_fora_periodo ? ' · DSR semanal' : ''}${warnings ? ' ⚠' : ''}</td>
        </tr>`;
    }).join('')}</tbody></table>`;
}

function _atualizarEstadoAplicarBH() {
    const btn = document.getElementById('bh-reprocessar-aplicar');
    if (!btn) return;
    const motivo = document.getElementById('bh-reprocessar-motivo')?.value?.trim() || '';
    const revisado = document.getElementById('bh-reprocessar-revisei')?.checked === true;
    const divergencias = Number(_bhReprocessPreview?.totais?.dias_divergentes || 0);
    const total = Number(_bhReprocessPreview?.lote?.total_colaboradores || 0);
    btn.disabled = _bhReprocessApplying || !_bhReprocessPreview || !revisado || motivo.length < 10 || motivo.length > 500 || (divergencias === 0 && total === 0);
    btn.innerHTML = _bhReprocessApplying ? '<i class="fas fa-spinner fa-spin"></i> Processando…' : (Number(_bhReprocessPreview?.lote?.offset || 0) > 0 ? '<i class="fas fa-check"></i> Aplicar próximo lote' : '<i class="fas fa-check"></i> Aplicar lote');
}

async function _iniciarReprocessamentoBH() {
    if (_bhReprocessApplying) return;
    const filtros = _bhReprocessFiltros(0);
    if (!filtros) return;
    const btn = document.getElementById('btn-bh-reprocessar');
    if (btn) { btn.disabled = true; btn.dataset.loading = '1'; }
    const progress = document.getElementById('bh-reprocessar-progresso');
    if (progress) progress.textContent = 'Calculando prévia segura…';
    try {
        const preview = await _bhReprocessRequest('simular', filtros);
        _bhReprocessProgress = { filtro: filtros, motivo: '', dias: 0, delta: 0, processados: 0, total: Number(preview.lote?.total_colaboradores || 0) };
        _mostrarPreviewBH(preview);
    } catch (error) {
        _toast(error.message, error.status === 403 ? 'error' : 'error');
    } finally {
        if (btn) { delete btn.dataset.loading; _atualizarBtnBHReprocessar(); }
    }
}

async function _aplicarReprocessamentoBH() {
    if (_bhReprocessApplying || !_bhReprocessPreview || !_bhReprocessProgress) return;
    const motivo = document.getElementById('bh-reprocessar-motivo')?.value?.trim() || '';
    if (motivo.length < 10 || motivo.length > 500 || !document.getElementById('bh-reprocessar-revisei')?.checked) {
        _toast('Revise a prévia, confirme o checkbox e informe o motivo (10–500 caracteres).', 'error');
        return;
    }
    _bhReprocessProgress.motivo = motivo;
    const payload = {
        ..._bhReprocessPreview.filtro,
        previsualizacao_id: _bhReprocessPreview.previsualizacao_id,
        hash_previa: _bhReprocessPreview.hash_previa,
        hash_entrada: _bhReprocessPreview.hash_entrada,
        revisei_diferencas: true,
        motivo,
    };
    _bhReprocessApplying = true;
    const cancel = document.getElementById('bh-reprocessar-cancelar');
    if (cancel) cancel.disabled = true;
    _atualizarEstadoAplicarBH();
    const progress = document.getElementById('bh-reprocessar-progresso');
    if (progress) progress.textContent = `Aplicando colaboradores ${Number(_bhReprocessPreview.lote?.offset || 0) + 1}–${Math.min(Number(_bhReprocessPreview.lote?.offset || 0) + 20, _bhReprocessProgress.total)} de ${_bhReprocessProgress.total}…`;
    try {
        const result = await _bhReprocessRequest('aplicar', payload);
        if (result.idempotente) {
            _toast('Este lote já havia sido concluído; nenhum lançamento duplicado foi criado.', 'success');
            _fecharReprocessamentoBH();
            await _gerarRelatorio('banco_horas');
            return;
        }
        _bhReprocessProgress.dias += Number(result.dias_alterados || 0);
        _bhReprocessProgress.delta += Number(result.totais_depois?.saldo_apos_reprocessar || 0) - Number(result.totais_antes?.saldo_atual || 0);
        _bhReprocessProgress.processados += Number(result.lote?.processados || 0);
        const nextOffset = result.lote?.offset_proximo;
        if (nextOffset !== null && nextOffset !== undefined) {
            const nextFilter = { ..._bhReprocessProgress.filtro, offset: Number(nextOffset) };
            const nextPreview = await _bhReprocessRequest('simular', nextFilter);
            _bhReprocessProgress.filtro = nextFilter;
            _bhReprocessPreview = nextPreview;
            _mostrarPreviewBH(nextPreview);
            const msg = document.getElementById('bh-reprocessar-progresso');
            if (msg) msg.textContent = `Lote anterior aplicado. Próximo: colaboradores ${Number(nextPreview.lote.offset) + 1}–${Math.min(Number(nextPreview.lote.offset) + 20, _bhReprocessProgress.total)} de ${_bhReprocessProgress.total}. Revise antes de aplicar.`;
            const reason = document.getElementById('bh-reprocessar-motivo');
            if (reason) reason.value = motivo;
            _atualizarEstadoAplicarBH();
            return;
        }
        const dias = _bhReprocessProgress.dias;
        const delta = _bhSignedMinutes(_bhReprocessProgress.delta);
        _fecharReprocessamentoBH();
        _toast(`Banco reprocessado: ${dias} dias ajustados, ${delta}.`, 'success');
        await _gerarRelatorio('banco_horas');
    } catch (error) {
        if (error.status === 409 || error.codigo === 'ESPELHO_ALTERADO') {
            const alert = document.getElementById('bh-reprocessar-alertas');
            if (alert) alert.textContent = `${error.message} Gere uma nova prévia e revise novamente.`;
            if (_bhReprocessProgress) {
                const filters = _bhReprocessFiltros(Number(_bhReprocessPreview?.lote?.offset || 0));
                if (filters) {
                    try { _mostrarPreviewBH(await _bhReprocessRequest('simular', filters)); }
                    catch (refreshError) { _toast(refreshError.message, 'error'); }
                }
            }
        } else {
            _toast(error.message, 'error');
        }
    } finally {
        _bhReprocessApplying = false;
        if (cancel) cancel.disabled = false;
        _atualizarEstadoAplicarBH();
        _atualizarBtnBHReprocessar();
    }
}

function _fecharReprocessamentoBH() {
    const modal = document.getElementById('bh-reprocessar-modal');
    if (modal) modal.style.display = 'none';
    if (_bhReprocessApplying) return;
    _bhReprocessPreview = null;
    _bhReprocessProgress = null;
    const reason = document.getElementById('bh-reprocessar-motivo');
    const reviewed = document.getElementById('bh-reprocessar-revisei');
    const include = document.getElementById('bh-reprocessar-incluir-fechados');
    if (reason) reason.value = '';
    if (reviewed) reviewed.checked = false;
    if (include) include.checked = false;
}

function _bhEscapeListener(event) {
    if (event.key === 'Escape' && document.getElementById('bh-negativo-modal')?.style.display !== 'none') {
        _fecharBHModal();
    }
}

function _bhParseDuracao(valor) {
    const texto = String(valor ?? '').trim();
    if (!texto) return null;
    if (/^\d+:\d{1,2}$/.test(texto)) {
        const [horas, minutos] = texto.split(':').map(Number);
        if (!Number.isSafeInteger(horas) || !Number.isSafeInteger(minutos) || horas < 0 || minutos < 0 || minutos > 59) return null;
        const total = horas * 60 + minutos;
        return total > 0 && total <= 2147483647 && Number.isSafeInteger(total) ? total : null;
    }
    if (!/^\d+$/.test(texto)) return null;
    const total = Number(texto);
    return Number.isSafeInteger(total) && total > 0 && total <= 2147483647 ? total : null;
}

function _bhFmt(minutos) {
    const valor = Number.parseInt(minutos ?? 0, 10) || 0;
    const abs = Math.abs(valor);
    return `${valor < 0 ? '-' : ''}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`;
}

function _atualizarBHPrevia() {
    const preview = document.getElementById('bh-reg-preview');
    const btn = document.getElementById('btn-bh-registrar');
    const avisoPagamento = document.getElementById('bh-reg-pagamento-aviso');
    if (!preview) return;

    const minutos = _bhParseDuracao(document.getElementById('bh-reg-minutos')?.value);
    const tipo = document.getElementById('bh-reg-tipo')?.value;
    const saldo = Number(_bhSaldoGlobal) || 0;
    const pagamentoExcede = tipo === 'pagamento' && minutos !== null && minutos > saldo;
    if (btn) btn.disabled = !!pagamentoExcede || _bhSubmitting;
    if (avisoPagamento) avisoPagamento.style.display = pagamentoExcede ? '' : 'none';

    if (minutos === null) {
        preview.style.display = 'none';
        preview.textContent = '';
        return;
    }
    const projetado = saldo - minutos;
    preview.style.display = '';
    preview.className = `bh-reg-preview ${projetado < 0 ? 'is-negative' : 'is-positive'}`;
    preview.innerHTML = `Saldo atual <strong>${_esc(_bhFmt(saldo))}</strong> → após ${tipo === 'pagamento' ? 'pagamento' : 'abatimento'} <strong>${_esc(_bhFmt(projetado))}</strong>`;
}

function _abrirBHModal(dados, payload) {
    _bhPendingRequest = payload;
    const resumo = document.getElementById('bh-negativo-resumo');
    const justificativa = document.getElementById('bh-negativo-justificativa');
    const confirmar = document.getElementById('bh-negativo-confirmar-checkbox');
    if (resumo) resumo.innerHTML = `Saldo atual <strong>${_esc(dados.saldo_atual_fmt || _bhFmt(dados.saldo_atual))}</strong><br>` +
        `Abatimento <strong>${_esc(dados.minutos_fmt || _bhFmt(dados.minutos))}</strong><br>` +
        `Saldo projetado <strong class="negativo">${_esc(dados.saldo_projetado_fmt || _bhFmt(dados.saldo_projetado))}</strong><br>` +
        `Excedente sobre saldo positivo: <strong>${_esc(dados.excedente_fmt || _bhFmt(dados.excedente))}</strong>`;
    const aviso = document.getElementById('bh-negativo-aviso');
    const divida = Math.abs(Number(dados.saldo_projetado) || 0);
    if (aviso) aviso.innerHTML = `O colaborador ficará devendo <strong>${_esc(_bhFmt(divida))}</strong>, que será compensado por futuros créditos.`;
    if (confirmar) confirmar.checked = false;
    const modal = document.getElementById('bh-negativo-modal');
    if (modal) modal.style.display = 'flex';
    justificativa?.focus();
}

function _fecharBHModal() {
    const modal = document.getElementById('bh-negativo-modal');
    if (modal) modal.style.display = 'none';
    _bhPendingRequest = null;
    const just = document.getElementById('bh-negativo-justificativa');
    const check = document.getElementById('bh-negativo-confirmar-checkbox');
    if (just) just.value = '';
    if (check) check.checked = false;
}

// Retorna parâmetros de período para relatórios (mes/ano ou data_inicio/data_fim)
function _getRelPeriodoParams() {
    const personalizado = document.getElementById('rel-tipo-personalizado').checked;
    if (personalizado) {
        const inicio = document.getElementById('rel-data-inicio').value;
        const fim    = document.getElementById('rel-data-fim').value;
        if (!inicio || !fim) {
            _toast('Período personalizado: informe a Data Início e a Data Fim', 'error');
            document.getElementById('rel-aviso-personalizado').style.display = '';
            return null;
        }
        if (inicio > fim) {
            _toast('A Data Início não pode ser maior que a Data Fim', 'error');
            return null;
        }
        return { tipo: 'personalizado', data_inicio: inicio, data_fim: fim,
                 label: `${inicio} a ${fim}` };
    }
    const mes = document.getElementById('rel-mes').value;
    const ano = document.getElementById('rel-ano').value;
    return { tipo: 'mes', mes, ano, label: `${_nomeMes(mes)}/${ano}` };
}

function _buildRelUrl(acao, params, extra = '') {
    if (params.tipo === 'personalizado') {
        return `../api/api_rh_relatorios.php?acao=${acao}&data_inicio=${params.data_inicio}&data_fim=${params.data_fim}${extra}`;
    }
    return `../api/api_rh_relatorios.php?acao=${acao}&mes=${params.mes}&ano=${params.ano}${extra}`;
}

// Guarda o último tipo de relatório gerado para PDF/CSV
let _ultimoRelTipo = null;
let _ultimoRelDados = null;
let _ultimoRelTitulo = null;
let _ultimoRelFiltros = null;

async function _gerarRelatorio(tipo) {
    const params    = _getRelPeriodoParams();
    if (!params) return;
    const dept      = document.getElementById('rel-departamento').value;
    const colab_id  = document.getElementById('rel-colaborador-id').value;

    const wrap   = document.getElementById('relatorio-resultado');
    const titulo = document.getElementById('relatorio-titulo');
    const body   = document.getElementById('relatorio-conteudo');
    if (tipo === 'banco_horas' && !colab_id && !dept) {
        wrap.style.display = '';
        body.innerHTML = '<p style="color:#b91c1c;padding:16px;">Selecione um colaborador ou departamento para o Banco de Horas.</p>';
        _toast('Selecione um colaborador ou departamento para o banco de horas', 'error');
        return;
    }
    if (tipo === 'espelho_ponto' && !colab_id) {
        wrap.style.display = '';
        body.innerHTML = '<p style="color:#b91c1c;padding:16px;">Selecione um colaborador para o espelho de ponto.</p>';
        _toast('Selecione um colaborador para o espelho', 'error');
        return;
    }
    wrap.style.display = '';
    body.innerHTML = '<div class="loading-msg"><i class="fas fa-spinner fa-spin"></i> Gerando relatório...</div>';

    let url, tituloText;
    const deptParam = `&departamento=${encodeURIComponent(dept)}`;

    switch (tipo) {
        case 'totais_horas':
            url = _buildRelUrl('totais_horas', params, deptParam);
            tituloText = `Totais de Horas — ${params.label}`;
            break;
        case 'espelho_ponto':
            url = _buildRelUrl('espelho_ponto', params, `&colaborador_id=${colab_id}`);
            tituloText = `Espelho de Ponto — ${params.label}`;
            break;
        case 'faltas':
            url = _buildRelUrl('faltas', params, deptParam);
            tituloText = `Faltas e Afastamentos — ${params.label}`;
            break;
        case 'horas_extras':
            url = _buildRelUrl('horas_extras', params, deptParam);
            tituloText = `Horas Extras — ${params.label}`;
            break;
        case 'atrasos':
            url = _buildRelUrl('atrasos', params, deptParam);
            tituloText = `Atrasos — ${params.label}`;
            break;
        case 'banco_horas':
            {
            let inicio, fim;
            if (params.tipo === 'personalizado') {
                inicio = params.data_inicio; fim = params.data_fim;
            } else {
                const mes = String(params.mes).padStart(2, '0');
                inicio = `${params.ano}-${mes}-01`;
                const fimDia = new Date(Number(params.ano), Number(params.mes), 0).getDate();
                fim = `${params.ano}-${mes}-${String(fimDia).padStart(2, '0')}`;
            }
            const alvo = colab_id ? `&colaborador_id=${encodeURIComponent(colab_id)}` : `&departamento=${encodeURIComponent(dept)}`;
            url = `../api/api_rh_relatorios.php?acao=banco_horas${alvo}&data_inicio=${encodeURIComponent(inicio)}&data_fim=${encodeURIComponent(fim)}`;
            tituloText = `Banco de Horas — ${params.label}`;
            const p = document.getElementById('bh-registrar-painel'); if (p) { p.style.display='none'; if (colab_id) p.dataset.colaboradorId = colab_id; }
            if (colab_id) { const dtInp = document.getElementById('bh-reg-data'); if (dtInp && !dtInp.value) dtInp.value = new Date().toLocaleDateString('sv-SE'); }
            break;
            }
        case 'aniversariantes':
            // Aniversariantes: usa mês (personalizado usa mês da data_inicio)
            const mesAniv = params.tipo === 'personalizado'
                ? new Date(params.data_inicio + 'T00:00:00').getMonth() + 1
                : params.mes;
            url = `../api/api_rh_relatorios.php?acao=aniversariantes&mes=${mesAniv}`;
            tituloText = `Aniversariantes de ${_nomeMes(String(mesAniv))}`;
            break;
        default: return;
    }

    titulo.textContent = tituloText;
    _ultimoRelTipo   = tipo;
    _ultimoRelTitulo = tituloText;
    _ultimoRelDados  = null;
    _ultimoRelFiltros = { periodo: { ...params }, departamento: String(dept || ''), colaboradorId: String(colab_id || '') };

    try {
        const r = await fetch(url, { credentials: 'include' });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);
        _ultimoRelDados = d.dados;
        const painelBH = document.getElementById('bh-registrar-painel');
        if (painelBH && tipo === 'banco_horas') painelBH.style.display = d.dados?.modo === 'ledger' ? '' : 'none';
        else if (painelBH) painelBH.style.display = 'none';
        if (tipo === 'banco_horas' && d.dados?.modo === 'ledger') {
            _bhSaldoGlobal = Number(d.dados.saldo_global) || 0;
            _atualizarBHPrevia();
        }
        body.innerHTML = _renderRelatorio(tipo, d.dados);
        if (tipo === 'banco_horas' && ['ledger','departamento'].includes(d.dados?.modo)) {
            await _bhAnexarStatusEHistorico(body, colab_id, params, colab_id ? '' : dept);
        }
        wrap.scrollIntoView({ behavior: 'smooth' });
    } catch (err) {
        body.innerHTML = `<p style="color:#dc2626;padding:16px;"><i class="fas fa-exclamation-triangle"></i> ${_esc(err.message)}</p>`;
    }
}

async function _bhAnexarStatusEHistorico(host, colaboradorId, periodo, departamento = '') {
    const base = '../api/api_rh_banco_horas.php';
    try {
        const query = new URLSearchParams({ acao: 'mapear' });
        if (colaboradorId) query.set('colaborador_id', String(colaboradorId));
        else query.set('departamento', String(departamento));
        if (periodo.tipo === 'personalizado') {
            query.set('data_inicio', periodo.data_inicio); query.set('data_fim', periodo.data_fim);
        } else {
            query.set('mes', String(periodo.mes)); query.set('ano', String(periodo.ano));
        }
        const response = await fetch(`${base}?${query.toString()}`, { credentials: 'include' });
        const result = await response.json();
        if (response.ok && result?.sucesso) {
            const map = result.dados;
            const count = Number(map?.totais?.dias_divergentes || 0);
            const lote = map?.lote || {};
            const parcial = lote.offset_proximo !== null && lote.offset_proximo !== undefined;
            if (count > 0 || parcial) {
                const canEdit = _bhUsuarioAdminAtual() || window.PermissoesModulos?.temPermissao?.('recursos_humanos', 'pode_editar');
                const badge = document.createElement(canEdit ? 'button' : 'div');
                badge.className = 'bh-selo-desatualizado';
                badge.type = canEdit ? 'button' : undefined;
                badge.textContent = count > 0
                    ? `Banco desatualizado · ${count} dia(s) divergente(s)${parcial ? ' no lote verificado' : ''}`
                    : `Verificação parcial · ${Math.min(Number(lote.limite) || 20, Number(lote.total_colaboradores) || 0)} de ${Number(lote.total_colaboradores) || 0} colaboradores verificados`;
                badge.setAttribute('aria-label', badge.textContent);
                if (canEdit) badge.addEventListener('click', _iniciarReprocessamentoBH);
                host.prepend(badge);
            }
        }
    } catch (error) {
        console.warn('[RH Banco de Horas] Não foi possível atualizar selo de divergência.', error);
    }
    try {
        const query = new URLSearchParams({ acao: 'historico', limite: '10' });
        if (colaboradorId) query.set('colaborador_id', String(colaboradorId));
        else if (departamento) query.set('departamento', String(departamento));
        const response = await fetch(`${base}?${query.toString()}`, { credentials: 'include' });
        const result = await response.json();
        if (!response.ok || !result?.sucesso || !Array.isArray(result.dados) || !result.dados.length) return;
        const history = result.dados;
        const section = document.createElement('section');
        section.className = 'bh-reprocess-historico';
        section.innerHTML = `<details><summary>Histórico de reprocessamentos (${history.length})</summary>
            <div class="table-container"><table class="data-table"><thead><tr><th>Quando</th><th>Período</th><th>Usuário</th><th>Motivo</th><th>Saldo antes → depois</th><th>Fechados</th><th>Status</th></tr></thead>
            <tbody>${history.map(item => {
                const before = item.totais_antes || {};
                const after = item.totais_depois || {};
                return `<tr><td>${_esc(item.concluido_em || item.iniciado_em || '—')}</td>
                    <td>${_esc(item.data_inicio)} a ${_esc(item.data_fim)}</td><td>${_esc(item.usuario_nome || item.usuario_id || '—')}</td>
                    <td>${_esc(item.motivo || '—')}</td><td>${_esc(_bhFmt(before.saldo_atual))} → ${_esc(_bhFmt(after.saldo_apos_reprocessar ?? after.saldo_atual))}</td>
                    <td>${Number(item.incluir_fechados) === 1 ? 'Sim (admin)' : 'Não'}</td>
                    <td>${_esc(item.status || '—')}</td></tr>`;
            }).join('')}</tbody></table></div></details>`;
        host.appendChild(section);
    } catch (error) {
        console.warn('[RH Banco de Horas] Não foi possível carregar o histórico.', error);
    }
}

function _renderRelatorio(tipo, dados) {
    if (tipo === 'totais_horas') {
        if (!dados?.length) return '<p style="padding:16px;color:var(--text-secondary,#64748b);">Nenhum dado encontrado.</p>';
        return `<div class="table-container"><table class="data-table">
            <thead><tr><th>Nome</th><th>Cargo</th><th>Depto</th><th>Contrato</th><th>Trabalhado</th><th>Extra</th><th>Atraso</th><th>Faltas</th><th>Folgas</th><th>Status</th></tr></thead>
            <tbody>${dados.map(r => `<tr>
                <td>${_esc(r.nome)}</td><td>${_esc(r.cargo||'—')}</td><td>${_esc(r.departamento||'—')}</td>
                <td>${_esc(r.tipo_contrato?.toUpperCase()||'—')}</td>
                <td>${_esc(r.total_horas_trabalhadas_fmt||'—')}</td>
                <td style="color:#16a34a;">${_esc(r.total_horas_extras_fmt||'—')}</td>
                <td style="color:#dc2626;">${_esc(r.total_atraso_fmt||'—')}</td>
                <td>${_esc(r.total_faltas??'—')}</td><td>${_esc(r.total_folgas??'—')}</td>
                <td>${r.periodo_status ? `<span class="status-${_esc(String(r.periodo_status).replace(/[^a-zA-Z0-9_-]/g,''))}">${_esc(r.periodo_status)}</span>` : '—'}</td>
            </tr>`).join('')}</tbody></table></div>`;
    }

    if (tipo === 'espelho_ponto') {
        const diasPt = {Monday:'Segunda',Tuesday:'Terça',Wednesday:'Quarta',Thursday:'Quinta',Friday:'Sexta',Saturday:'Sábado',Sunday:'Domingo'};
        const { cabecalho: c, lancamentos: l } = dados;
        if (!c) return '<p style="padding:16px;">Nenhum dado encontrado.</p>';
        const info = `<div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:14px 18px;margin-bottom:14px;border:1px solid var(--border-color,#e2e8f0);">
            <strong>${_esc(c.nome)}</strong> · ${_esc(c.cargo||'—')} · ${_esc(c.departamento||'—')}
            <div style="margin-top:6px;font-size:12px;color:var(--text-secondary,#64748b);display:flex;gap:16px;flex-wrap:wrap;">
                <span>Trabalhado: <strong>${_esc(c.total_horas_trabalhadas_fmt||'—')}</strong></span>
                <span>Extra: <strong style="color:#16a34a;">${_esc(c.total_horas_extras_fmt||'—')}</strong></span>
                <span>Atraso: <strong style="color:#dc2626;">${_esc(c.total_atraso_fmt||'—')}</strong></span>
                <span>Faltas: <strong>${_esc(c.total_faltas||0)}</strong></span>
                <span>Folgas: <strong>${_esc(c.total_folgas||0)}</strong></span>
            </div></div>`;
        if (!l?.length) return info + '<p style="padding:16px;">Nenhum lançamento.</p>';
        return info + `<div class="table-container"><table class="data-table">
            <thead><tr><th>Data</th><th>Dia</th><th>Tipo</th><th>Entrada</th><th>Saída Alm.</th><th>Ret. Alm.</th><th>Saída</th><th>Trabalhado</th><th>Extra</th><th>Atraso</th><th>Obs</th></tr></thead>
            <tbody>${l.map(r => {
                const diaPt = diasPt[r.dia_semana] || r.dia_semana || '—';
                const rowCls = r.tipo_dia==='folga' ? 'style="background:#eff6ff;"'
                             : r.tipo_dia==='falta'  ? 'style="background:#fff1f2;"'
                             : r.tipo_dia==='feriado' ? 'style="background:#fef9c3;"' : '';
                return `<tr ${rowCls}>
                <td>${_esc(r.data_fmt)}</td><td>${_esc(diaPt)}</td><td>${_esc(_tipoDia(r.tipo_dia))}</td>
                <td>${_esc(r.he||'—')}</td><td>${_esc(r.has||'—')}</td><td>${_esc(r.har||'—')}</td><td>${_esc(r.hs||'—')}</td>
                <td>${_esc(r.horas_trab_fmt||'—')}</td>
                <td style="color:#16a34a;font-weight:600;">${r.horas_extra_fmt && r.horas_extra_fmt!=='00:00' ? _esc(r.horas_extra_fmt) : '—'}${r.alerta_extra_limite ? ` <span title="Extra acima do limite diário informativo de ${_esc(_bhFmt(r.limite_extra_diario_min))}; nenhum minuto foi descartado." aria-label="Extra acima do limite diário" style="color:#b45309;cursor:help;">⚠</span>` : ''}</td>
                <td style="color:#dc2626;font-weight:600;">${_esc(r.atraso_fmt && r.atraso_fmt!=='00:00' ? r.atraso_fmt : '—')}</td>
                <td>${_esc(r.observacoes||'')}</td>
            </tr>`;}).join('')}</tbody></table></div>`;
    }

    if (tipo === 'banco_horas') {
        if (dados?.modo === 'departamento') {
            const rows = dados.colaboradores || [];
            const totals = dados.totais || {};
            const cards = [
                ['Crédito do período', totals.credito_periodo_fmt],
                ['Débito do período', totals.debito_periodo_fmt],
                ['Excedente separado', totals.excedente_periodo_fmt],
                ['Saldo global agregado', totals.saldo_global_fmt],
            ];
            const resumoDept = `<div class="bh-reprocessar-kpis">${cards.map(([label, value]) => `<div class="bh-reprocessar-kpi"><span>${_esc(label)}</span><strong>${_esc(value || '00:00')}</strong></div>`).join('')}</div>`;
            if (!rows.length) return resumoDept + '<p style="padding:16px;color:#64748b;">Nenhum colaborador ativo com Banco de Horas habilitado neste departamento.</p>';
            return resumoDept + `<div class="table-container"><table class="data-table"><thead><tr><th>Colaborador</th><th>Departamento</th><th>Crédito período</th><th>Débito período</th><th>Excedente</th><th>Saldo período</th><th>Saldo global</th></tr></thead><tbody>${rows.map(row => `<tr>
                <td>${_esc(row.nome || '')}</td><td>${_esc(row.departamento || '')}</td>
                <td style="color:#16a34a;">+${_esc(row.credito_periodo_fmt || '00:00')}</td>
                <td style="color:#dc2626;">-${_esc(row.debito_periodo_fmt || '00:00')}</td>
                <td>${_esc(row.excedente_periodo_fmt || '00:00')}</td>
                <td style="color:${Number(row.saldo_periodo) < 0 ? '#dc2626' : '#16a34a'};">${_esc(row.saldo_periodo_fmt || '00:00')}</td>
                <td style="color:${Number(row.saldo_global) < 0 ? '#dc2626' : '#16a34a'};">${_esc(row.saldo_global_fmt || '00:00')}</td>
            </tr>`).join('')}</tbody></table></div>`;
        }
        // Modo ledger (novo — rh_banco_horas disponível)
        if (dados.modo === 'ledger') {
            const { linhas, saldo_global_fmt, saldo_global, total_credito_fmt, total_debito_fmt } = dados;
            const saldoCor = saldo_global >= 0 ? '#16a34a' : '#dc2626';
            const resumo = `<div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 16px;min-width:140px;">
                    <div style="font-size:11px;color:#166534;font-weight:600;">CRÉDITO TOTAL</div>
                    <div style="font-size:18px;font-weight:700;color:#16a34a;">+${_esc(total_credito_fmt)}</div>
                </div>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 16px;min-width:140px;">
                    <div style="font-size:11px;color:#991b1b;font-weight:600;">DÉBITO TOTAL</div>
                    <div style="font-size:18px;font-weight:700;color:#dc2626;">-${_esc(total_debito_fmt)}</div>
                </div>
                <div style="background:#f8faff;border:2px solid ${saldoCor};border-radius:8px;padding:10px 16px;min-width:140px;">
                    <div style="font-size:11px;color:#334155;font-weight:600;">SALDO ATUAL</div>
                    <div style="font-size:18px;font-weight:700;color:${saldoCor};">${saldo_global < 0 ? `Saldo devedor ${_esc(saldo_global_fmt)}` : _esc(saldo_global_fmt)}</div>
                </div>
            </div>`;

            if (!linhas?.length) return resumo + `<p style="padding:8px 0;color:#64748b;">Nenhum lançamento no período selecionado.<br>
                <small>Ative o banco de horas na escala do colaborador e recalcule os lançamentos de ponto.</small></p>`;

            const typeLabel = { credito:'Crédito Extra', debito:'Débito', abatimento:'Abatimento', pagamento:'Pagamento', excedente:'Excedente — decisão RH' };
            const typeColor = { credito:'#16a34a', debito:'#dc2626', abatimento:'#7c3aed', pagamento:'#0369a1', excedente:'#64748b' };

            return resumo + `<div class="table-container"><table class="data-table">
                <thead><tr><th>Data</th><th>Tipo</th><th>Minutos</th><th>Descrição</th><th>Saldo Corrente</th><th>Usuário</th></tr></thead>
                <tbody>${linhas.map(l => {
                    const conhecido = Object.prototype.hasOwnProperty.call(typeLabel, l.tipo);
                    const cor = conhecido ? typeColor[l.tipo] : '#64748b';
                    const label = conhecido ? typeLabel[l.tipo] : 'Não classificado';
                    const tipoTitle = conhecido ? '' : ' title="Tipo histórico desconhecido; confira a origem do lançamento"';
                    const confirmado = Number(l.saldo_negativo_confirmado) === 1;
                    const auditoria = confirmado
                        ? `Lançado com saldo negativo confirmado por ${l.usuario_confirmacao || l.usuario || 'Usuário não identificado'} — ${l.justificativa_saldo_negativo || 'justificativa não informada'}`
                        : '';
                    const calculoTitle = ['credito', 'excedente'].includes(l.tipo) && l.minutos_apurados !== null
                        ? `Apurado ${_bhFmt(l.minutos_apurados)} · Regra ${l.regra_aplicada || '—'} · v${l.versao_calculo || '—'}`
                        : '';
                    return `<tr>
                    <td>${_esc(l.data_fmt)}</td>
                    <td><span class="${conhecido ? '' : 'bh-tipo-desconhecido'}"${tipoTitle} ${calculoTitle ? `title="${_esc(calculoTitle)}"` : ''} style="color:${cor};font-weight:600;">${_esc(label)}</span></td>
                    <td style="font-family:monospace;color:${cor};">${_esc(l.sinal)}${_esc(l.minutos_fmt)}</td>
                    <td>${_esc(l.descricao)}</td>
                    <td style="font-family:monospace;font-weight:600;color:${l.saldo_corrente>=0?'#16a34a':'#dc2626'};">${_esc(l.saldo_corrente_fmt)}</td>
                    <td style="font-size:11px;color:#64748b;">${_esc(l.usuario||'Sistema')}${confirmado ? ` <span title="${_esc(auditoria)}" aria-label="${_esc(auditoria)}" style="color:#d97706;cursor:help;">⚠</span>` : ''}</td>
                </tr>`;}).join('')}</tbody>
            </table></div>`;
        }

        // Modo agregado (fallback — rh_banco_horas não existe ainda)
        const { meses, total_acumulado_fmt } = dados;
        if (!meses?.length) return '<p style="padding:16px;">Nenhum dado.</p>';
        return `<div class="table-container"><table class="data-table">
            <thead><tr><th>Mês/Ano</th><th>Trabalhado</th><th>Extras</th><th>Atraso</th><th>Saldo</th><th>Acumulado</th></tr></thead>
            <tbody>${meses.map(m => `<tr>
                <td>${_esc(_nomeMes(m.mes))}/${_esc(m.ano)}</td>
                <td>${_esc(_minParaHoras(m.total_horas_trabalhadas_min))}</td>
                <td style="color:#16a34a;">${_esc(_minParaHoras(m.total_horas_extras_min))}</td>
                <td style="color:#dc2626;">${_esc(_minParaHoras(m.total_atraso_min))}</td>
                <td style="color:${Number(m.saldo_min)>=0?'#16a34a':'#dc2626'};">${_esc(m.saldo_fmt)}</td>
                <td style="color:${Number(m.acumulado_min)>=0?'#16a34a':'#dc2626'};font-weight:600;">${_esc(m.acumulado_fmt)}</td>
            </tr>`).join('')}
            <tr style="font-weight:700;border-top:2px solid var(--border-color,#e2e8f0);">
                <td colspan="5" style="text-align:right;">Total acumulado:</td>
                <td style="color:${String(total_acumulado_fmt ?? '').startsWith('-')?'#dc2626':'#16a34a'};">${_esc(total_acumulado_fmt)}</td>
            </tr></tbody></table></div>`;
    }

    if (tipo === 'aniversariantes') {
        if (!dados?.length) return '<p style="padding:16px;">Nenhum aniversariante.</p>';
        return `<div class="table-container"><table class="data-table">
            <thead><tr><th>Data</th><th>Nome</th><th>Cargo</th><th>Departamento</th></tr></thead>
            <tbody>${dados.map(r => `<tr>
                <td>🎂 ${_esc(r.aniversario)}</td><td>${_esc(r.nome)}</td>
                <td>${_esc(r.cargo||'—')}</td><td>${_esc(r.departamento||'—')}</td>
            </tr>`).join('')}</tbody></table></div>`;
    }

    // Tabela genérica para faltas, extras, atrasos
    if (!dados?.length) return '<p style="padding:16px;color:var(--text-secondary,#64748b);">Nenhum dado encontrado.</p>';

    const keys = Object.keys(dados[0]);
    return `<div class="table-container"><table class="data-table">
        <thead><tr>${keys.map(k => `<th>${_esc(k)}</th>`).join('')}</tr></thead>
        <tbody>${dados.map(r => `<tr>${keys.map(k => `<td>${_esc(r[k]??'—')}</td>`).join('')}</tr>`).join('')}</tbody>
        </table></div>`;
}

function _gerarRelatorioPDF() {
    if (!_ultimoRelTipo || !_ultimoRelDados) {
        return _toast('Gere um relatório primeiro antes de exportar para PDF', 'info');
    }
    const filtros = _ultimoRelFiltros;
    const params = filtros?.periodo;
    if (!params) return _toast('Gere novamente o relatório antes de exportar para PDF.', 'info');
    const dept = filtros.departamento;
    const colab_id = filtros.colaboradorId;

    let url = `../api/api_rh_relatorio_pdf.php?tipo=${_ultimoRelTipo}`;
    if (params.tipo === 'personalizado') {
        url += `&data_inicio=${encodeURIComponent(params.data_inicio)}&data_fim=${encodeURIComponent(params.data_fim)}`;
    } else {
        url += `&mes=${encodeURIComponent(params.mes)}&ano=${encodeURIComponent(params.ano)}`;
    }
    if (dept)     url += `&departamento=${encodeURIComponent(dept)}`;
    if (colab_id) url += `&colaborador_id=${encodeURIComponent(colab_id)}`;

    window.open(url, '_blank');
}

function _exportarRelatorioCSV() {
    if (!_ultimoRelTipo || !_ultimoRelDados) {
        return _toast('Gere um relatório primeiro antes de exportar CSV', 'info');
    }
    const dados = _ultimoRelDados;
    const titulo = _ultimoRelTitulo || 'relatorio_rh';
    let csv = '';

    if (_ultimoRelTipo === 'totais_horas') {
        csv = 'Nome;Cargo;Departamento;Contrato;Trabalhado;Extra;Atraso;Faltas;Folgas;Status\n';
        csv += (Array.isArray(dados) ? dados : []).map(r =>
            `"${r.nome||''}";"${r.cargo||''}";"${r.departamento||''}";"${r.tipo_contrato||''}";"${r.total_horas_trabalhadas_fmt||''}";"${r.total_horas_extras_fmt||''}";"${r.total_atraso_fmt||''}";${r.total_faltas||0};${r.total_folgas||0};"${r.periodo_status||''}"`
        ).join('\n');
    } else if (_ultimoRelTipo === 'espelho_ponto') {
        const l = dados?.lancamentos || [];
        csv = 'Data;Tipo;Entrada;Saída Almoço;Retorno;Saída;Trabalhado;Extra;Atraso;Obs\n';
        csv += l.map(r =>
            `${r.data};"${r.tipo_dia||''}";${r.hora_entrada||''};${r.hora_almoco_saida||''};${r.hora_almoco_retorno||''};${r.hora_saida||''};"${r.horas_trabalhadas_fmt||''}";"${r.horas_extras_fmt||''}";"${r.atraso_fmt||''}";"${(r.observacoes||'').replace(/"/g,"'")}"` 
        ).join('\n');
    } else if (['faltas','horas_extras','atrasos'].includes(_ultimoRelTipo)) {
        csv = 'Nome;Cargo;Departamento;Data;Tipo;Horas;Obs\n';
        csv += (Array.isArray(dados) ? dados : []).map(r =>
            `"${r.nome||''}";"${r.cargo||''}";"${r.departamento||''}";${r.data||''};"${r.tipo_dia||''}";"${r.horas_fmt||r.horas_extras_fmt||r.atraso_fmt||''}";"${(r.observacoes||'').replace(/"/g,"'")}"` 
        ).join('\n');
    } else if (_ultimoRelTipo === 'banco_horas' && dados?.modo === 'ledger') {
        const cell = value => `"${String(value ?? '').replace(/"/g, '""').replace(/[\r\n]+/g, ' ')}"`;
        const labels = { credito:'Crédito Extra', debito:'Débito', abatimento:'Abatimento', pagamento:'Pagamento', excedente:'Excedente — decisão RH' };
        csv = 'Data;Tipo;Minutos;Descrição;Saldo corrente;Usuário;Regra;Auditoria saldo negativo\n';
        csv += (dados.linhas || []).map(row => [row.data_fmt, labels[row.tipo] || 'Não classificado', `${row.sinal || ''}${row.minutos_fmt || ''}`, row.descricao, row.saldo_corrente_fmt, row.usuario, row.regra_aplicada || '', Number(row.saldo_negativo_confirmado) === 1 ? row.justificativa_saldo_negativo : '']
            .map(cell).join(';')).join('\n');
    } else if (_ultimoRelTipo === 'banco_horas' && dados?.modo === 'departamento') {
        csv = 'Colaborador;Departamento;Crédito período;Débito período;Excedente;Saldo período;Saldo global\n';
        csv += (dados.colaboradores || []).map(r => [r.nome,r.departamento,r.credito_periodo_fmt,r.debito_periodo_fmt,r.excedente_periodo_fmt,r.saldo_periodo_fmt,r.saldo_global_fmt]
            .map(v => `"${String(v ?? '').replace(/"/g, "'")}"`).join(';')).join('\n');
    } else {
        csv = Object.keys(dados?.[0] || dados?.lancamentos?.[0] || {}).join(';') + '\n';
        const arr = Array.isArray(dados) ? dados : (dados?.lancamentos || []);
        csv += arr.map(r => Object.values(r).map(v => `"${String(v||'').replace(/"/g,"'")}"`).join(';')).join('\n');
    }

    const bom  = '\uFEFF';
    const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href  = URL.createObjectURL(blob);
    link.download = `${titulo.replace(/[^a-z0-9]/gi,'_').toLowerCase()}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

// ────────────────────────────────────────────────────────────────────────────
// SELECTS GLOBAIS
// ────────────────────────────────────────────────────────────────────────────
async function _popularSelects() {
    try {
        const d = await _fetchJson('../api/api_rh_colaboradores.php?acao=listar&ativo=1', { credentials: 'include' });
        if (!d.sucesso) return;
        const opts = (Array.isArray(d.dados) ? d.dados : []).map(c => {
            const id = Number.parseInt(c.id, 10);
            return Number.isSafeInteger(id) && id > 0 ? `<option value="${id}">${_esc(c.nome)}</option>` : '';
        }).join('');
        ['ponto-colaborador-id','escala-colaborador-id','rel-colaborador-id','abono-colaborador-id'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            const first = sel.options[0].outerHTML;
            sel.innerHTML = first + opts;
        });
    } catch {}

    try {
        const d = await _fetchJson('../api/api_rh_colaboradores.php?acao=departamentos', { credentials: 'include' });
        if (!d.sucesso) return;
        const sel = document.getElementById('rel-departamento');
        if (sel) sel.innerHTML = '<option value="">Todos</option>' + d.dados.map(dp => `<option value="${_esc(dp)}">${_esc(dp)}</option>`).join('');
    } catch {}
}

// ────────────────────────────────────────────────────────────────────────────
// UTILITÁRIOS
// ────────────────────────────────────────────────────────────────────────────
function _esc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _rhFotoUrl(valor) {
    const caminho = String(valor ?? '').trim();
    if (!caminho) return RH_DEFAULT_AVATAR;
    try {
        const url = new URL(caminho, window.location.href);
        return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin
            ? url.href
            : RH_DEFAULT_AVATAR;
    } catch { return RH_DEFAULT_AVATAR; }
}

function _setSelect(id, val) {
    const sel = document.getElementById(id);
    if (sel && val != null) sel.value = val;
}

function _minParaHoras(min) {
    if (!min || min <= 0) return '00:00';
    const h = Math.floor(min / 60);
    const m = min % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}

function _nomeMes(m) {
    return ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'][parseInt(m)] ?? '';
}

function _tipoDia(t) {
    const map = {
        normal       : 'Normal',
        folga        : 'Folga',
        falta        : 'Falta',
        feriado      : 'Feriado',
        meio_periodo : 'Meio período',
        afastamento  : 'Afastamento',
        horas_extras : 'Horas Extras',   // ← novo tipo
    };
    return map[t] ?? t;
}

// ────────────────────────────────────────────────────────────────────────────
// ESCALA MANUAL — helpers de UI
// ────────────────────────────────────────────────────────────────────────────

const _DIAS_SEMANA_EM = ['seg','ter','qua','qui','sex','sab','dom'];
const _DIAS_LABEL_EM  = {seg:'Segunda',ter:'Terça',qua:'Quarta',qui:'Quinta',sex:'Sexta',sab:'Sábado',dom:'Domingo'};

function _renderEscalaManualRows(semana = {}) {
    const tbody = document.getElementById('escala-manual-tbody');
    if (!tbody) return;
    const defAtivo   = { ativo:true,  hora_entrada:'07:00', hora_almoco_saida:'12:00', hora_almoco_retorno:'13:00', hora_saida:'16:00', intervalo_min:60 };
    const defInativo = { ativo:false, hora_entrada:'', hora_almoco_saida:'', hora_almoco_retorno:'', hora_saida:'', intervalo_min:0 };

    tbody.innerHTML = _DIAS_SEMANA_EM.map(dia => {
        const def = ['sab','dom'].includes(dia) ? defInativo : defAtivo;
        const cfg = semana[dia] ?? def;
        const ativo = !!cfg.ativo;
        const dis   = ativo ? '' : 'disabled';
        const op    = ativo ? '1' : '0.55';
        const normalizarHora = valor => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(String(valor ?? '')) ? String(valor) : '';
        const horaEntrada = normalizarHora(cfg.hora_entrada);
        const horaAlmocoSaida = normalizarHora(cfg.hora_almoco_saida);
        const horaAlmocoRetorno = normalizarHora(cfg.hora_almoco_retorno);
        const horaSaida = normalizarHora(cfg.hora_saida);
        const intervaloMin = Math.max(0, Math.min(180, Number.parseInt(cfg.intervalo_min, 10) || 0));
        const cargaLabel = _emCargaCalc(horaEntrada, horaSaida, intervaloMin);

        return `<tr data-dia="${dia}" style="border-bottom:1px solid #e9d5ff;opacity:${op};transition:opacity .2s;">
            <td style="padding:7px 10px;font-weight:600;font-size:12px;">${_DIAS_LABEL_EM[dia]}</td>
            <td style="padding:7px 10px;text-align:center;">
                <input type="checkbox" class="em-ativo" data-dia="${dia}" ${ativo?'checked':''}
                       style="width:15px;height:15px;accent-color:#7c3aed;cursor:pointer;"
                       onchange="window._emToggleDia(this)">
            </td>
            <td style="padding:3px 5px;"><input type="time" class="em-entrada" data-dia="${dia}" value="${_esc(horaEntrada)}" ${dis} style="width:85px;font-size:12px;" oninput="window._emAtualizarCarga('${dia}')"></td>
            <td style="padding:3px 5px;"><input type="time" class="em-als" data-dia="${dia}" value="${_esc(horaAlmocoSaida)}" ${dis} style="width:85px;font-size:12px;"></td>
            <td style="padding:3px 5px;"><input type="time" class="em-alr" data-dia="${dia}" value="${_esc(horaAlmocoRetorno)}" ${dis} style="width:85px;font-size:12px;"></td>
            <td style="padding:3px 5px;"><input type="time" class="em-saida" data-dia="${dia}" value="${_esc(horaSaida)}" ${dis} style="width:85px;font-size:12px;" oninput="window._emAtualizarCarga('${dia}')"></td>
            <td style="padding:3px 5px;"><input type="number" class="em-int" data-dia="${dia}" value="${intervaloMin}" min="0" max="180" ${dis} style="width:55px;font-size:12px;" oninput="window._emAtualizarCarga('${dia}')"></td>
            <td style="padding:7px 10px;text-align:right;font-weight:700;color:#6d28d9;font-size:12px;" id="em-carga-${dia}">${cargaLabel}</td>
        </tr>`;
    }).join('');
}

function _emCargaCalc(entrada, saida, intervalo) {
    if (!entrada || !saida) return '—';
    const [hh,hm] = entrada.split(':').map(Number);
    const [sh,sm] = saida.split(':').map(Number);
    const c = (sh*60+sm) - (hh*60+hm) - (parseInt(intervalo)||0);
    return c > 0 ? _minParaHoras(c) : '—';
}

window._emToggleDia = function(chk) {
    const dia  = chk.dataset.dia;
    const tr   = chk.closest('tr');
    const ativo= chk.checked;
    tr.style.opacity = ativo ? '1' : '0.55';
    tr.querySelectorAll('input[type=time], input[type=number]').forEach(inp => { inp.disabled = !ativo; });
    window._emAtualizarCarga(dia);
};

window._emAtualizarCarga = function(dia) {
    const tbody = document.getElementById('escala-manual-tbody');
    if (!tbody) return;
    const entrada   = tbody.querySelector(`.em-entrada[data-dia="${dia}"]`)?.value;
    const saida     = tbody.querySelector(`.em-saida[data-dia="${dia}"]`)?.value;
    const intervalo = tbody.querySelector(`.em-int[data-dia="${dia}"]`)?.value;
    const el = document.getElementById(`em-carga-${dia}`);
    if (el) el.textContent = _emCargaCalc(entrada, saida, intervalo);
};

function _escalaManualGetSemana() {
    const tbody = document.getElementById('escala-manual-tbody');
    if (!tbody) return {};
    const result = {};
    _DIAS_SEMANA_EM.forEach(dia => {
        result[dia] = {
            ativo              : !!(tbody.querySelector(`.em-ativo[data-dia="${dia}"]`)?.checked),
            hora_entrada       : tbody.querySelector(`.em-entrada[data-dia="${dia}"]`)?.value || null,
            hora_almoco_saida  : tbody.querySelector(`.em-als[data-dia="${dia}"]`)?.value || null,
            hora_almoco_retorno: tbody.querySelector(`.em-alr[data-dia="${dia}"]`)?.value || null,
            hora_saida         : tbody.querySelector(`.em-saida[data-dia="${dia}"]`)?.value || null,
            intervalo_min      : parseInt(tbody.querySelector(`.em-int[data-dia="${dia}"]`)?.value ?? 0),
        };
    });
    return result;
}

// ESCALA ALTERNADA — helpers de UI
// ────────────────────────────────────────────────────────────────────────────

/**
 * Mostra/oculta o bloco de configuração de escala alternada conforme o tipo
 * selecionado no formulário de escala.
 */
// Presets CLT para as jornadas padrão
const _CLT_JORNADAS = {
    jornada_44h: {
        label        : 'Jornada 44h/semana — CLT Art. 58',
        corpo        : '▸ 220 horas mensais (divisor oficial MTE)<br>▸ 7h20min/dia × 6 dias (Seg–Sáb)<br>▸ DSR: Domingo (pago, sem débito de horas)<br>▸ Extras após 7h20min de trabalho efetivo<br>▸ Máx. 2h extra/dia — CLT Art. 59',
        cargaH       : 7 + 1/3,          // 440 min = 7h20min
        cargaMensalH : 220,
        dias         : ['seg','ter','qua','qui','sex','sab'],
        entrada      : '08:00', almocoSaida: '12:00', almocoRetorno: '13:00', saida: '15:20',
        intervalo    : 60,
        descansoH    : 11,
    },
    jornada_40h: {
        label        : 'Jornada 40h/semana — cargos administrativos',
        corpo        : '▸ 200 horas mensais (divisor 200)<br>▸ 8h/dia × 5 dias (Seg–Sex)<br>▸ DSR: Sábado e Domingo (pagos, sem débito)<br>▸ Extras após 8h de trabalho efetivo<br>▸ Máx. 2h extra/dia — CLT Art. 59',
        cargaH       : 8,                 // 480 min
        cargaMensalH : 200,
        dias         : ['seg','ter','qua','qui','sex'],
        entrada      : '08:00', almocoSaida: '12:00', almocoRetorno: '13:00', saida: '17:00',
        intervalo    : 60,
        descansoH    : 11,
    },
    jornada_36h: {
        label        : 'Jornada 36h/semana — turno de 6h (CLT Art. 224)',
        corpo        : '▸ 180 horas mensais (divisor 180)<br>▸ 6h/dia × 6 dias (Seg–Sáb)<br>▸ DSR: Domingo (pago, sem débito de horas)<br>▸ Sem intervalo obrigatório ≤ 6h (CLT Art. 71)<br>▸ Extras após 6h de trabalho efetivo',
        cargaH       : 6,                 // 360 min
        cargaMensalH : 180,
        dias         : ['seg','ter','qua','qui','sex','sab'],
        entrada      : '08:00', almocoSaida: '08:00', almocoRetorno: '08:00', saida: '14:00',
        intervalo    : 0,
        descansoH    : 11,
    },
};

function _setupEscalaAlternada() {
    const sel        = document.getElementById('escala-tipo');
    const blocoAlt   = document.getElementById('escala-alternada-bloco');
    const blocoJorn  = document.getElementById('escala-jornada-bloco');
    const blocoBH    = document.getElementById('escala-banco-horas-bloco');
    const blocoCLT   = document.getElementById('escala-clt-info-bloco');
    const wrap12x36  = document.getElementById('escala-12x36-wrap');
    const wrapMensal = document.getElementById('escala-carga-mensal-wrap');
    const chk12x36   = document.getElementById('escala-regime-12x36');
    const inpCargaH  = document.getElementById('escala-carga-h');
    const inpDescanso= document.getElementById('escala-descanso-h');
    const inpMensal  = document.getElementById('escala-carga-mensal-h');
    const info12x36  = document.getElementById('escala-12x36-info');
    if (!sel) return;

    const blocoManual = document.getElementById('escala-manual-bloco');

    // ── Atualiza visibilidade dos blocos conforme o tipo selecionado ──────────
    const toggle = (autoFill = false) => {
        const tipo      = sel.value;
        const isAlt     = tipo === 'alternada';
        const isCtrl    = tipo === 'controle_jornada';
        const isLivre   = tipo === 'livre';
        const isManual  = tipo === 'escala_manual';
        const preset    = _CLT_JORNADAS[tipo] ?? null;
        const isJornada = !!preset;

        // Bloco de escala alternada (Semana A / B)
        if (blocoAlt) blocoAlt.style.display = isAlt ? '' : 'none';

        // Bloco de escala manual (tabela por dia da semana)
        if (blocoManual) {
            blocoManual.style.display = isManual ? '' : 'none';
            if (isManual) {
                const tbodyM = document.getElementById('escala-manual-tbody');
                // Renderiza linhas padrão se a tabela ainda estiver vazia
                // (nova escala ou troca de tipo). Edição carrega via _editarEscala.
                if (tbodyM && !tbodyM.children.length) _renderEscalaManualRows({});
            }
        }

        // Campos globais de horário e dias: oculta para escala_manual (cada dia tem o seu)
        const diasSection   = document.getElementById('escala-dias-section');
        const horariosGrid  = document.getElementById('escala-horarios-grid');
        const cargaHWrap    = document.getElementById('escala-carga-h-wrap');
        if (diasSection)  diasSection.style.display  = isManual ? 'none' : '';
        if (horariosGrid) horariosGrid.style.display  = isManual ? 'none' : '';
        if (cargaHWrap)   cargaHWrap.style.display    = isManual ? 'none' : '';

        // Bloco de jornada (descanso, 12x36, carga mensal)
        if (blocoJorn) blocoJorn.style.display = (isAlt || isCtrl || isJornada) ? '' : 'none';

        // Banco de horas disponível para controle_jornada, jornadas CLT e escala_manual
        if (blocoBH) {
            const bhDisp = isCtrl || isJornada || isManual;
            blocoBH.style.display = bhDisp ? '' : 'none';
            if (!bhDisp) { const chkBH = document.getElementById('escala-banco-horas-ativo'); if (chkBH) chkBH.checked = false; }
        }

        // Info CLT — só para jornadas padrão
        if (blocoCLT) {
            blocoCLT.style.display = isJornada ? '' : 'none';
            if (isJornada && preset) {
                document.getElementById('escala-clt-info-titulo').textContent = preset.label;
                document.getElementById('escala-clt-info-corpo').innerHTML    = preset.corpo;
            }
        }

        // Checkbox 12x36 só aparece para escala alternada
        if (wrap12x36) wrap12x36.style.display = isAlt ? '' : 'none';

        // Carga mensal só aparece para alternada
        if (wrapMensal) wrapMensal.style.display = isAlt ? '' : 'none';

        // Auto-fill para jornadas CLT pré-definidas (só ao trocar o tipo no form em branco)
        if (isJornada && preset && autoFill) {
            // Carga diária (armazena o valor exato — PHP converte para minutos)
            if (inpCargaH) inpCargaH.value = Math.round(preset.cargaH * 1000) / 1000;
            // Dias de trabalho
            document.querySelectorAll('.page-recursos-humanos #escala-dias-grid .dia-tag').forEach(t => {
                t.classList.toggle('ativo', preset.dias.includes(t.dataset.dia));
            });
            _state.escalaDias = [...preset.dias];
            // Horários
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
            set('escala-entrada',       preset.entrada);
            set('escala-almoco-saida',  preset.almocoSaida);
            set('escala-almoco-retorno',preset.almocoRetorno);
            set('escala-saida',         preset.saida);
            set('escala-intervalo',     preset.intervalo);
            if (inpDescanso) inpDescanso.value = preset.descansoH;
        }

        // Regras automáticas de descanso para os outros tipos (sem auto-fill de dias)
        if (isAlt) {
            const is12x36 = document.getElementById('escala-regime-12x36')?.checked;
            if (inpDescanso && !inpDescanso.value) inpDescanso.value = is12x36 ? 36 : '';
        } else if (isCtrl) {
            if (inpDescanso && !inpDescanso.value) inpDescanso.value = 11;
        } else if (isLivre) {
            if (inpDescanso) inpDescanso.value = '';
        }

        // Atualiza carga mensal e preview automaticamente
        _atualizarCargaMensal();
        if (isAlt) _atualizarPreviewAlternada();
    };

    sel.addEventListener('change', () => {
        // Ao trocar de tipo: limpa o tbody manual para forçar re-renderização com defaults
        const tbody = document.getElementById('escala-manual-tbody');
        if (tbody) tbody.innerHTML = '';
        // autoFill=true apenas quando é uma escala nova (id vazio = sem ID guardado)
        const isNova = !document.getElementById('escala-id')?.value;
        toggle(isNova);
    });
    toggle(false);

    // ── Checkbox 12x36 ────────────────────────────────────────────────────────
    chk12x36?.addEventListener('change', () => {
        const is12x36 = chk12x36.checked;
        if (is12x36) {
            if (inpDescanso) inpDescanso.value = 36;
        } else {
            if (inpDescanso) inpDescanso.value = '';
        }
        if (info12x36) info12x36.style.display = is12x36 ? '' : 'none';
        _atualizarCargaMensal();
    });

    // ── Atualiza carga mensal e preview ao mudar carga diária ────────────────
    inpCargaH?.addEventListener('input', () => {
        _atualizarCargaMensal();
        _atualizarPreviewAlternada();
    });

    // ── Data de início da Semana A → atualiza preview ─────────────────────────
    document.getElementById('escala-alt-inicio')?.addEventListener('change', _atualizarPreviewAlternada);

    // ── Clique nos dias da Semana A e B → recalcula mensal + preview ──────────
    ['escala-alt-semana-a', 'escala-alt-semana-b'].forEach(gridId => {
        document.getElementById(gridId)?.querySelectorAll('.dia-tag').forEach(tag => {
            tag.addEventListener('click', () => {
                tag.classList.toggle('ativo');
                _atualizarCargaMensal();
                _atualizarPreviewAlternada();
            });
        });
    });
}

/**
 * Atualiza automaticamente a carga mensal estimada para escala alternada.
 * Fórmula: (dias_semA + dias_semB) × 2.143 ciclos/mês × carga_diária
 * (30 dias / 14 dias por ciclo de 2 semanas = 2.143 ciclos)
 */
function _atualizarCargaMensal() {
    const tipo      = document.getElementById('escala-tipo')?.value;
    const inpCargaH = document.getElementById('escala-carga-h');
    const inpMensal = document.getElementById('escala-carga-mensal-h');
    // Jornadas CLT: mensal é fixo (não aparece no form, PHP define)
    if (_CLT_JORNADAS[tipo]) return;
    // Escala manual: sem campo de carga mensal no form (não calculado aqui)
    if (tipo === 'escala_manual') return;
    if (tipo !== 'alternada' || !inpCargaH || !inpMensal) return;

    const cargaDiaria = parseFloat(inpCargaH.value) || 0;
    if (!cargaDiaria) { inpMensal.value = ''; return; }

    const diasA = _getDiasAlternados('escala-alt-semana-a').length;
    const diasB = _getDiasAlternados('escala-alt-semana-b').length;
    const diasPorCiclo = diasA + diasB;

    let totalMensal;
    if (diasPorCiclo > 0) {
        totalMensal = Math.round(diasPorCiclo * 2.143 * cargaDiaria * 2) / 2;
    } else {
        totalMensal = Math.round(cargaDiaria * 15 * 2) / 2; // fallback
    }
    inpMensal.value = totalMensal;
}

/**
 * Gera o preview de 30 dias da escala alternada mostrando dias de trabalho e folga.
 * Semana A e Semana B alternam a cada 7 dias a partir de alternada_dia_inicio.
 */
function _atualizarPreviewAlternada() {
    const previewGrid  = document.getElementById('escala-alt-preview-grid');
    const previewStats = document.getElementById('escala-alt-preview-stats');
    if (!previewGrid) return;

    const altInicio = document.getElementById('escala-alt-inicio')?.value;
    const diasA     = _getDiasAlternados('escala-alt-semana-a');
    const diasB     = _getDiasAlternados('escala-alt-semana-b');
    const cargaH    = parseFloat(document.getElementById('escala-carga-h')?.value) || 0;

    if (!altInicio || (!diasA.length && !diasB.length)) {
        previewGrid.innerHTML  = '<span style="font-size:11px;color:#94a3b8;">Defina a data de início e os dias das semanas A e B para ver a prévia.</span>';
        if (previewStats) previewStats.textContent = '';
        return;
    }

    // Mapeamento JS getDay() → sigla do sistema
    const JS_TO_DIA = {0:'dom',1:'seg',2:'ter',3:'qua',4:'qui',5:'sex',6:'sab'};
    const DIA_LABEL = {seg:'Seg',ter:'Ter',qua:'Qua',qui:'Qui',sex:'Sex',sab:'Sáb',dom:'Dom'};
    const MONTH_BR  = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

    // Data de início da Semana A (parse sem problemas de timezone)
    const [ano, mes, dia] = altInicio.split('-').map(Number);
    const inicio = new Date(ano, mes - 1, dia);
    // Início da semana ISO que contém a data de início (segunda-feira)
    const diaSemana0 = inicio.getDay(); // 0=dom, 1=seg...
    const offsetParaSeg = (diaSemana0 === 0) ? -6 : 1 - diaSemana0;
    const inicioSemanaA = new Date(inicio);
    inicioSemanaA.setDate(inicioSemanaA.getDate() + offsetParaSeg);

    let totalTrabalho = 0;
    let htmlDias = '';

    for (let i = 0; i < 30; i++) {
        const d = new Date(inicio);
        d.setDate(d.getDate() + i);

        // Dias desde o início da semana A (em semanas completas)
        const diffMs   = d - inicioSemanaA;
        const semanas  = Math.floor(diffMs / (7 * 24 * 3600 * 1000));
        const isSemanaA = (semanas % 2 === 0);
        const diasAtivos = isSemanaA ? diasA : diasB;

        const diaSig  = JS_TO_DIA[d.getDay()];
        const isTrabalho = diasAtivos.includes(diaSig);

        const dd     = String(d.getDate()).padStart(2, '0');
        const mmAbr  = MONTH_BR[d.getMonth()];
        const semTag = isSemanaA ? 'sem-a' : 'sem-b';

        if (isTrabalho) totalTrabalho++;

        htmlDias += `<div class="escala-alt-prev-dia ${isTrabalho ? 'trabalho ' + semTag : 'folga'}"
            title="${DIA_LABEL[diaSig]} ${dd}/${mmAbr} — ${isSemanaA ? 'Semana A' : 'Semana B'} — ${isTrabalho ? 'Trabalho' : 'Folga'}">
            <span class="prev-dd">${dd}</span>
            <span class="prev-sig">${DIA_LABEL[diaSig].slice(0,1)}</span>
        </div>`;
    }

    previewGrid.innerHTML = htmlDias;

    if (previewStats) {
        const totalHoras   = totalTrabalho * cargaH;
        const diasFolga    = 30 - totalTrabalho;
        previewStats.innerHTML =
            `<strong>${totalTrabalho}</strong> dias de trabalho &nbsp;|&nbsp; `+
            `<strong>${diasFolga}</strong> dias de folga &nbsp;|&nbsp; `+
            (cargaH > 0 ? `<strong>${totalHoras.toFixed(1)}h</strong> estimadas no período` : '');
    }
}

/**
 * Lê os dias marcados em um grid de dias alternados.
 * @param {string} gridId
 * @returns {string[]}
 */
function _getDiasAlternados(gridId) {
    return Array.from(document.getElementById(gridId)?.querySelectorAll('.dia-tag.ativo') ?? [])
        .map(t => t.dataset.dia);
}

/**
 * Preenche os dias dos grids de semana A e B a partir de arrays.
 */
function _setDiasAlternados(gridId, dias) {
    document.getElementById(gridId)?.querySelectorAll('.dia-tag').forEach(t => {
        t.classList.toggle('ativo', (dias ?? []).includes(t.dataset.dia));
    });
}

// ────────────────────────────────────────────────────────────────────────────
// ────────────────────────────────────────────────────────────────────────────
// BANCO DE HORAS — Registro manual de abatimento / pagamento
// ────────────────────────────────────────────────────────────────────────────
async function _registrarBH() {
    if (_bhSubmitting) return;
    const painel = document.getElementById('bh-registrar-painel');
    const colabId = painel?.dataset?.colaboradorId || document.getElementById('rel-colaborador-id')?.value;
    const tipo = document.getElementById('bh-reg-tipo')?.value;
    const minutos = _bhParseDuracao(document.getElementById('bh-reg-minutos')?.value);
    const data = document.getElementById('bh-reg-data')?.value;
    const descricao = document.getElementById('bh-reg-descricao')?.value?.trim();

    if (!colabId) return _toast('Colaborador não identificado. Gere o relatório de banco de horas primeiro.', 'error');
    if (!minutos) return _toast('Informe uma duração válida em minutos ou HH:MM.', 'error');
    if (!descricao) return _toast('Informe uma descrição.', 'error');
    if (tipo === 'pagamento' && minutos > _bhSaldoGlobal) {
        _atualizarBHPrevia();
        return _toast(`Pagamento maior que o saldo disponível (${_bhFmt(_bhSaldoGlobal)}). Para compensar com folga, use Abatimento.`, 'error');
    }

    const assinaturaPayload = JSON.stringify({ colaborador_id: Number.parseInt(colabId, 10), tipo, minutos, data, descricao });
    if (!_bhRequestKey || _bhRequestSignature !== assinaturaPayload) {
        _bhRequestKey = globalThis.crypto?.randomUUID?.() || `bh-${Date.now()}-${Math.random().toString(36).slice(2, 14)}`;
        _bhRequestSignature = assinaturaPayload;
    }
    const payload = {
        colaborador_id: Number.parseInt(colabId, 10),
        tipo,
        minutos,
        data,
        descricao,
        idempotency_key: _bhRequestKey,
    };
    await _enviarBH(payload, false, '');
}

async function _enviarBH(payload, confirmarSaldoNegativo, justificativa) {
    if (_bhSubmitting) return;
    _bhSubmitting = true;
    const btn = document.getElementById('btn-bh-registrar');
    const btnConfirmar = document.getElementById('bh-negativo-confirmar');
    const original = btn?.innerHTML ?? '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando…'; }
    if (btnConfirmar) btnConfirmar.disabled = true;

    const body = {
        ...payload,
        confirmar_saldo_negativo: !!confirmarSaldoNegativo,
        justificativa_saldo_negativo: confirmarSaldoNegativo ? justificativa : '',
    };
    try {
        const response = await fetch('../api/api_rh_banco_horas.php?acao=registrar', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const result = await response.json();
        if (response.status === 409 && result.codigo === 'CONFIRMAR_SALDO_NEGATIVO') {
            _abrirBHModal(result.dados || {}, payload);
            return;
        }
        if (response.status === 409 && result.codigo === 'IDEMPOTENCY_CONFLICT') {
            _bhRequestKey = null;
            _bhRequestSignature = null;
        }
        if (!response.ok || !result.sucesso) {
            throw new Error(result.mensagem || `Erro HTTP ${response.status}`);
        }

        _toast(result.mensagem || 'Lançamento registrado.', 'success');
        _bhRequestKey = null;
        _bhRequestSignature = null;
        _fecharBHModal();
        const inputMin = document.getElementById('bh-reg-minutos');
        const inputDesc = document.getElementById('bh-reg-descricao');
        if (inputMin) inputMin.value = '';
        if (inputDesc) inputDesc.value = '';
        _atualizarBHPrevia();
        await _gerarRelatorio('banco_horas');
    } catch (err) {
        _toast(err.message || 'Falha ao registrar lançamento.', 'error');
    } finally {
        _bhSubmitting = false;
        if (btn) { btn.innerHTML = original || '<i class="fas fa-plus"></i> Registrar'; btn.disabled = false; }
        if (btnConfirmar) btnConfirmar.disabled = false;
        _atualizarBHPrevia();
    }
}

// ────────────────────────────────────────────────────────────────────────────
// ABONO
// ────────────────────────────────────────────────────────────────────────────
function _setupAbono() {
    const now = new Date();
    const mesEl = document.getElementById('abono-mes');
    const anoEl = document.getElementById('abono-ano');
    if (mesEl) mesEl.value = String(now.getMonth() + 1);
    if (anoEl) anoEl.value = String(now.getFullYear());

    document.getElementById('btnAbonoCarregar')?.addEventListener('click', _carregarAbono);
}

async function _carregarAbono() {
    const colabId = document.getElementById('abono-colaborador-id')?.value;
    const mes     = document.getElementById('abono-mes')?.value;
    const ano     = document.getElementById('abono-ano')?.value;

    if (!colabId) return _toast('Selecione um colaborador.', 'error');
    if (!mes || !ano) return _toast('Informe mês e ano.', 'error');

    const container = document.getElementById('abono-lista');
    container.innerHTML = '<div style="text-align:center;padding:30px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Carregando lançamentos...</div>';

    try {
        const d = await _fetchJson(
            `../api/api_rh_abono.php?acao=listar&colaborador_id=${encodeURIComponent(colabId)}&mes=${encodeURIComponent(mes)}&ano=${encodeURIComponent(ano)}`,
            { credentials: 'include' }
        );
        if (!d.sucesso) throw new Error(d.mensagem);
        _renderAbono(d.dados ?? []);
    } catch (err) {
        container.innerHTML = `<div style="padding:20px;color:#dc2626;"><i class="fas fa-exclamation-triangle"></i> ${_esc(err.message)}</div>`;
    }
}

function _renderAbono(lista) {
    const container = document.getElementById('abono-lista');

    if (!lista.length) {
        container.innerHTML = `
          <div style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>
            Nenhum lançamento encontrado no período.
          </div>`;
        return;
    }

    const _mh = m => {
        m = parseInt(m || 0);
        if (!m || m <= 0) return '—';
        return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
    };

    const tiposLabel = { normal:'Normal', folga:'Folga', falta:'Falta', feriado:'Feriado',
                         meio_periodo:'Meio Per.', afastamento:'Afastamento', horas_extras:'H.Extras' };

    const rows = lista.map(l => {
        const temExtras = parseInt(l.horas_extras_min || 0) > 0;
        const temAtraso = parseInt(l.atraso_min || 0) > 0;
        const temFalta  = l.tipo_dia === 'falta';
        const temAbono  = temExtras || temAtraso || temFalta;
        const id        = Number.parseInt(l.id, 10);
        if (!Number.isSafeInteger(id) || id <= 0) return '';
        const extrasMin  = Math.max(0, Number.parseInt(l.horas_extras_min, 10) || 0);
        const atrasoMin  = Math.max(0, Number.parseInt(l.atraso_min, 10) || 0);
        const extrasAbonoMin = Math.max(0, Number.parseInt(l.abono_extras_min, 10) || 0);
        const atrasoAbonoMin = Math.max(0, Number.parseInt(l.abono_atraso_min, 10) || 0);

        const jaAbonado = l.abono_justificativa ? true : false;
        const badgeAbonado = jaAbonado
            ? `<span style="background:#dcfce7;color:#16a34a;font-size:9px;font-weight:700;
                            padding:2px 5px;border-radius:10px;vertical-align:middle;margin-left:4px;">ABONADO</span>`
            : '';

        const rowBg = l.tipo_dia === 'falta'   ? 'background:#fff1f2;' :
                      l.tipo_dia === 'folga'   ? 'background:#eff6ff;' :
                      l.tipo_dia === 'feriado' ? 'background:#fef9c3;' : '';

        // ── Abono Extras ─────────────────────────────────────────────────────
        let extrasCtrl = '<span style="color:#cbd5e1;">—</span>';
        if (temExtras) {
            extrasCtrl = `
              <div style="display:flex;flex-direction:column;gap:4px;">
                <select class="abono-extras-sel" data-id="${id}"
                  onchange="window.AbonoPage.toggleExtrasMin(this)"
                  style="padding:4px 6px;border:1px solid #e2e8f0;border-radius:5px;font-size:11px;">
                  <option value="nenhum" ${l.abono_extras === 'nenhum' ? 'selected' : ''}>Não abonar</option>
                  <option value="total"  ${l.abono_extras === 'total'  ? 'selected' : ''}>Total (${_mh(extrasMin)})</option>
                  <option value="parcial"${l.abono_extras === 'parcial'? 'selected' : ''}>Parcial</option>
                </select>
                <input type="number" class="abono-extras-min-inp" data-id="${id}"
                  placeholder="minutos" min="1" max="${extrasMin}"
                  value="${extrasAbonoMin || ''}"
                  style="display:${l.abono_extras === 'parcial' ? 'block' : 'none'};
                         width:80px;padding:3px 6px;border:1px solid #e2e8f0;border-radius:5px;font-size:11px;">
              </div>`;
        }

        // ── Abono Atraso ─────────────────────────────────────────────────────
        let atrasoCtrl = '<span style="color:#cbd5e1;">—</span>';
        if (temAtraso) {
            atrasoCtrl = `
              <div style="display:flex;flex-direction:column;gap:4px;">
                <select class="abono-atraso-sel" data-id="${id}"
                  onchange="window.AbonoPage.toggleAtrasoMin(this)"
                  style="padding:4px 6px;border:1px solid #e2e8f0;border-radius:5px;font-size:11px;">
                  <option value="nenhum" ${l.abono_atraso === 'nenhum' ? 'selected' : ''}>Não abonar</option>
                  <option value="total"  ${l.abono_atraso === 'total'  ? 'selected' : ''}>Total (${_mh(atrasoMin)})</option>
                  <option value="parcial"${l.abono_atraso === 'parcial'? 'selected' : ''}>Parcial</option>
                </select>
                <input type="number" class="abono-atraso-min-inp" data-id="${id}"
                  placeholder="minutos" min="1" max="${atrasoMin}"
                  value="${atrasoAbonoMin || ''}"
                  style="display:${l.abono_atraso === 'parcial' ? 'block' : 'none'};
                         width:80px;padding:3px 6px;border:1px solid #e2e8f0;border-radius:5px;font-size:11px;">
              </div>`;
        }

        // ── Abono Falta ──────────────────────────────────────────────────────
        let faltaCtrl = '<span style="color:#cbd5e1;">—</span>';
        if (temFalta) {
            faltaCtrl = `
              <label style="cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;">
                <input type="checkbox" class="abono-falta-chk" data-id="${id}"
                       ${l.abono_falta ? 'checked' : ''}>
                Abonar falta
              </label>`;
        }

        // ── Justificativa ─────────────────────────────────────────────────────
        const justifCell = temAbono ? `
          <textarea class="abono-justif" data-id="${id}" rows="2"
            placeholder="Justificativa obrigatória ao abonar..."
            style="width:100%;min-width:140px;font-size:11px;border:1px solid #e2e8f0;
                   border-radius:5px;padding:5px;resize:vertical;">${_esc(l.abono_justificativa || '')}</textarea>`
            : '<span style="color:#cbd5e1;">—</span>';

        // ── Botão salvar ──────────────────────────────────────────────────────
        const btnSalvar = temAbono
            ? `<button type="button" onclick="window.AbonoPage.salvar(${id})"
                 style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border:none;
                        padding:5px 11px;border-radius:5px;font-size:11px;cursor:pointer;
                        white-space:nowrap;font-weight:600;">
                 <i class="fas fa-save"></i> Salvar
               </button>`
            : '';

        return `
          <tr style="${rowBg}" data-lancamento-id="${id}">
            <td style="white-space:nowrap;font-size:12px;">${_esc(l.d)}${badgeAbonado}</td>
            <td style="font-size:12px;">${_esc(l.dia_pt)}</td>
            <td style="font-size:12px;">${_esc(tiposLabel[l.tipo_dia] ?? l.tipo_dia)}</td>
            <td style="font-size:12px;text-align:center;">${_esc(l.he || '—')}</td>
            <td style="font-size:12px;text-align:center;">${_esc(l.hs || '—')}</td>
            <td style="font-size:12px;text-align:center;color:#16a34a;font-weight:600;">${_mh(l.horas_extras_min)}</td>
            <td style="font-size:12px;text-align:center;color:#dc2626;font-weight:600;">${_mh(l.atraso_min)}</td>
            <td>${extrasCtrl}</td>
            <td>${atrasoCtrl}</td>
            <td>${faltaCtrl}</td>
            <td>${justifCell}</td>
            <td style="white-space:nowrap;">${btnSalvar}</td>
          </tr>`;
    }).join('');

    container.innerHTML = `
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:900px;">
          <thead>
            <tr style="background:linear-gradient(90deg,#1e3a8a,#2563eb);color:#fff;font-size:11px;">
              <th style="padding:8px 10px;text-align:left;">Data</th>
              <th style="padding:8px 10px;text-align:left;">Dia</th>
              <th style="padding:8px 10px;text-align:left;">Tipo</th>
              <th style="padding:8px 10px;text-align:center;">Entrada</th>
              <th style="padding:8px 10px;text-align:center;">Saída</th>
              <th style="padding:8px 10px;text-align:center;">Extras</th>
              <th style="padding:8px 10px;text-align:center;">Atraso</th>
              <th style="padding:8px 10px;text-align:left;">Abono Extras</th>
              <th style="padding:8px 10px;text-align:left;">Abono Atraso</th>
              <th style="padding:8px 10px;text-align:left;">Abono Falta</th>
              <th style="padding:8px 10px;text-align:left;min-width:150px;">Justificativa</th>
              <th style="padding:8px 10px;text-align:center;">Ação</th>
            </tr>
          </thead>
          <tbody>
            ${rows}
          </tbody>
        </table>
      </div>
      <p style="font-size:11px;color:#94a3b8;margin-top:10px;padding:0 4px;">
        <i class="fas fa-info-circle"></i>
        Apenas dias com horas extras, atraso ou falta podem receber abono. Justificativa obrigatória.
      </p>`;
}

// Namespace global para handlers inline do template
window.AbonoPage = {
    toggleExtrasMin(sel) {
        const inp = document.querySelector(`.abono-extras-min-inp[data-id="${sel.dataset.id}"]`);
        if (inp) inp.style.display = sel.value === 'parcial' ? 'block' : 'none';
    },

    toggleAtrasoMin(sel) {
        const inp = document.querySelector(`.abono-atraso-min-inp[data-id="${sel.dataset.id}"]`);
        if (inp) inp.style.display = sel.value === 'parcial' ? 'block' : 'none';
    },

    async salvar(lancamentoId) {
        const id = String(lancamentoId);

        const extrasSel = document.querySelector(`.abono-extras-sel[data-id="${id}"]`);
        const extrasMin = document.querySelector(`.abono-extras-min-inp[data-id="${id}"]`);
        const atrasoSel = document.querySelector(`.abono-atraso-sel[data-id="${id}"]`);
        const atrasoMin = document.querySelector(`.abono-atraso-min-inp[data-id="${id}"]`);
        const faltaChk  = document.querySelector(`.abono-falta-chk[data-id="${id}"]`);
        const justif    = document.querySelector(`.abono-justif[data-id="${id}"]`);

        const body = {
            lancamento_id    : lancamentoId,
            abono_extras     : extrasSel?.value ?? 'nenhum',
            abono_extras_min : extrasMin ? (parseInt(extrasMin.value) || 0) : 0,
            abono_falta      : faltaChk?.checked ? 1 : 0,
            abono_atraso     : atrasoSel?.value ?? 'nenhum',
            abono_atraso_min : atrasoMin ? (parseInt(atrasoMin.value) || 0) : 0,
            abono_justificativa: justif?.value.trim() ?? '',
        };

        const temAbono = body.abono_extras !== 'nenhum' || body.abono_falta || body.abono_atraso !== 'nenhum';
        if (temAbono && !body.abono_justificativa) {
            _toast('Justificativa é obrigatória ao conceder abono.', 'error');
            if (justif) { justif.style.borderColor = '#dc2626'; justif.focus(); }
            return;
        }
        if (justif) justif.style.borderColor = '';

        try {
            const d = await _fetchJson('../api/api_rh_abono.php?acao=salvar', {
                method     : 'POST',
                credentials: 'include',
                headers    : { 'Content-Type': 'application/json' },
                body       : JSON.stringify(body),
            });
            if (!d.sucesso) throw new Error(d.mensagem);
            _toast(d.mensagem, 'success');
            await _carregarAbono();
        } catch (err) {
            _toast(err.message, 'error');
        }
    },
};
