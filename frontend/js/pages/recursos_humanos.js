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
    };
}

export function destroy() {
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
        wrap.innerHTML = `<p style="color:#dc2626;padding:16px;"><i class="fas fa-exclamation-triangle"></i> ${err.message}</p>`;
    }
}

function _renderColaboradores(list) {
    const wrap = document.getElementById('rh-lista-colaboradores');
    if (!list.length) { wrap.innerHTML = '<p style="padding:16px;color:var(--text-secondary,#64748b);">Nenhum colaborador encontrado.</p>'; return; }

    wrap.innerHTML = list.map(c => `
        <div class="rh-colab-card">
            <img class="rh-colab-avatar"
                 src="${c.foto_path ? c.foto_path : RH_DEFAULT_AVATAR}"
                 alt="${_esc(c.nome)}"
                 onerror="this.onerror=null;this.src=window.ModuleRHDefaultAvatar||''">
            <div class="rh-colab-info">
                <div class="rh-colab-nome">${_esc(c.nome)}</div>
                <div class="rh-colab-sub">${_esc(c.cargo||'—')} · ${_esc(c.departamento||'—')} · CPF: ${_esc(c.cpf||'—')}</div>
            </div>
            <span class="rh-badge ${c.ativo == 1 ? 'rh-badge-ativo' : 'rh-badge-inativo'}">${c.ativo == 1 ? 'Ativo' : 'Inativo'}</span>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <button class="action-btn" title="Registrar ponto" onclick='window.ModuleRH.abrirPontoColab(${c.id}, "${_escAttr(c.nome)}")'>
                    <i class="fas fa-clock"></i>
                </button>
                <button class="action-btn" title="Editar" onclick="window.ModuleRH.editarColaborador(${c.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="action-btn" title="Excluir" onclick='window.ModuleRH.excluirColaborador(${c.id}, "${_escAttr(c.nome)}")'>
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `).join('');
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

        document.getElementById('rh-foto-preview').src = c.foto_path || RH_DEFAULT_AVATAR;
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
                    `${fmtDate(params.data_inicio)} – ${fmtDate(params.data_fim)} &nbsp;|&nbsp; <span class="status-aberto">Multi-mês</span>`;
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
    document.getElementById('ponto-header-meta').innerHTML  =
        `${periodoLabel} &nbsp;|&nbsp; <span class="status-${periodo.status}">${periodo.status === 'fechado' ? 'Fechado' : 'Em aberto'}</span>`;

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

    tbody.innerHTML = _state.lancamentos.map(l => {
        // horas_extras recebe classe visual diferenciada (verde claro)
        const cls = l.tipo_dia === 'horas_extras' ? 'dia-horas-extras'
                  : ['folga','falta','feriado'].includes(l.tipo_dia) ? `dia-${l.tipo_dia}` : '';
        const dis  = readonly ? 'disabled' : '';
        const trab = l.horas_trabalhadas_min > 0 ? `<span class="${l.horas_extras_min > 0 ? 'horas-extra' : ''}">${_minParaHoras(l.horas_trabalhadas_min)}</span>` : '—';
        const ext  = l.horas_extras_min > 0 ? `<span class="horas-extra">${_minParaHoras(l.horas_extras_min)}</span>` : '—';
        const atr  = l.atraso_min > 0 ? `<span class="horas-atraso">${_minParaHoras(l.atraso_min)}</span>` : '—';
        const diaName = diasPt[l.dia_semana] ?? l.dia_semana ?? '';
        // Indicador de DSR perdido: domingo marcado como falta (pela regra CLT)
        const dsrBadge = (l.tipo_dia === 'falta' && l.dia_semana === 'Sunday')
            ? '<span style="margin-left:4px;font-size:10px;background:#dc2626;color:#fff;padding:1px 4px;border-radius:3px;vertical-align:middle;" title="DSR perdido — houve falta injustificada nesta semana">DSR↓</span>'
            : (l.tipo_dia === 'folga' && l.dia_semana === 'Sunday')
            ? '<span style="margin-left:4px;font-size:10px;background:#16a34a;color:#fff;padding:1px 4px;border-radius:3px;vertical-align:middle;" title="DSR ganho — semana completa">DSR✓</span>'
            : '';
        // Indicador de turno que cruza meia-noite (12x36: saída < entrada = dia seguinte)
        const cruzaMeiaNoite = l.he && l.hs && l.hs < l.he;
        const badgeD1 = cruzaMeiaNoite ? ' <span class="badge-d1" title="Saída no dia seguinte">D+1</span>' : '';

        return `<tr class="${cls}" data-id="${l.id}" data-periodo="${_state.periodoAtual.id}" data-colab="${_state.periodoAtual.colaborador_id}">
            <td>${l.data_fmt}</td>
            <td class="dia-semana">${diaName}${dsrBadge}</td>
            <td>
                <select onchange="window.ModuleRH.salvarLinhaPonto(this)" data-campo="tipo_dia" ${dis}>
                    ${TIPOS.map(t => `<option value="${t}" ${l.tipo_dia===t?'selected':''}>${_tipoDia(t)}</option>`).join('')}
                </select>
            </td>
            <td><input type="time" value="${l.he||''}" data-campo="hora_entrada" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}></td>
            <td><input type="time" value="${l.has||''}" data-campo="hora_almoco_saida" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}></td>
            <td><input type="time" value="${l.har||''}" data-campo="hora_almoco_retorno" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}></td>
            <td style="white-space:nowrap;"><input type="time" value="${l.hs||''}" data-campo="hora_saida" onchange="window.ModuleRH.salvarLinhaPonto(this)" ${dis}>${badgeD1}</td>
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
    } catch (err) { list.innerHTML = `<p style="color:#dc2626;padding:16px;">${err.message}</p>`; }
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

    const diasLabel = { seg:'Seg', ter:'Ter', qua:'Qua', qui:'Qui', sex:'Sex', sab:'Sáb', dom:'Dom' };
    const tipoLabels = {
        livre:'Livre', controle_jornada:'Controle de Jornada', alternada:'Escala Alternada',
        jornada_44h:'44h/sem — 220h/mês', jornada_40h:'40h/sem — 200h/mês', jornada_36h:'36h/sem — 180h/mês',
    };

    container.innerHTML = list.map(e => {
        const dias  = JSON.parse(e.dias_trabalho || '[]');
        const tipoLabel = tipoLabels[e.tipo] ?? e.tipo;
        const isAlt = e.tipo === 'alternada' || e.alternada_ativa;

        // Bloco de semanas A e B para escala alternada
        let altHtml = '';
        if (isAlt) {
            const semA = typeof e.alternada_semana_a === 'string' ? JSON.parse(e.alternada_semana_a || '[]') : (e.alternada_semana_a ?? []);
            const semB = typeof e.alternada_semana_b === 'string' ? JSON.parse(e.alternada_semana_b || '[]') : (e.alternada_semana_b ?? []);
            altHtml = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px;">
                <div>
                    <div style="font-size:11px;font-weight:600;color:#0369a1;margin-bottom:4px;">Semana A</div>
                    <div class="dias-semana-grid" style="pointer-events:none;">
                        ${['seg','ter','qua','qui','sex','sab','dom'].map(d => `<span class="dia-tag ${semA.includes(d)?'ativo':''}">${diasLabel[d]}</span>`).join('')}
                    </div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#7c3aed;margin-bottom:4px;">Semana B</div>
                    <div class="dias-semana-grid" style="pointer-events:none;">
                        ${['seg','ter','qua','qui','sex','sab','dom'].map(d => `<span class="dia-tag ${semB.includes(d)?'ativo':''}">${diasLabel[d]}</span>`).join('')}
                    </div>
                </div>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;">
                <i class="fas fa-calendar-day"></i> Início Semana A: <strong>${e.alternada_dia_inicio || '—'}</strong> &nbsp;|
                Folga alternada: <strong>${e.alternada_tipo_folga || 'folga'}</strong>
            </div>`;
        }

        const preset = _CLT_JORNADAS[e.tipo] ?? null;
        const isJorn = !!preset;
        // Carga diária: para jornadas padrão mostra "7h20min", para outras arredonda
        const cargaMin = parseInt(e.carga_horaria_diaria_min ?? 480);
        const cargaLabel = isJorn
            ? `${Math.floor(cargaMin/60)}h${cargaMin%60>0?String(cargaMin%60).padStart(2,'0')+'min':''}/${isJorn ? (e.carga_horaria_mensal_min ? Math.round(e.carga_horaria_mensal_min/60)+'h/mês' : preset.cargaMensalH+'h/mês') : ''}`
            : `${Math.floor(cargaMin/60)}h carga`;
        // DSR: dias que NÃO estão em dias_trabalho (exceto para alternada)
        const dsrLabel = !isAlt && isJorn
            ? `DSR: ${['seg','ter','qua','qui','sex','sab','dom'].filter(d=>!dias.includes(d)).map(d=>diasLabel[d]).join(', ')||'—'}`
            : '';

        return `
        <div class="escala-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                <div>
                    <div style="font-weight:700;font-size:14px;">${_esc(e.nome_escala)}</div>
                    <span class="escala-tipo escala-tipo-${e.tipo}">${tipoLabel}</span>
                    ${isJorn ? `<span style="margin-left:6px;font-size:10px;font-weight:700;padding:2px 6px;background:#fde68a;color:#78350f;border-radius:4px;">CLT</span>` : ''}
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="action-btn" onclick="window.ModuleRH.editarEscala(${e.id})"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" onclick="window.ModuleRH.excluirEscala(${e.id})"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            ${!isAlt ? `
            <div style="margin:6px 0 4px;font-size:12px;color:var(--text-secondary,#64748b);">Dias de trabalho:</div>
            <div class="dias-semana-grid" style="pointer-events:none;">
                ${['seg','ter','qua','qui','sex','sab','dom'].map(d => `<span class="dia-tag ${dias.includes(d)?'ativo':''}" title="${!dias.includes(d)&&isJorn?'DSR – Descanso Semanal Remunerado':''}">${diasLabel[d]}</span>`).join('')}
            </div>
            ${dsrLabel ? `<div style="font-size:11px;color:#92400e;margin-top:4px;"><i class="fas fa-moon"></i> ${dsrLabel} — remunerado, sem débito de horas</div>` : ''}` : altHtml}
            <div class="escala-horarios" style="margin-top:8px;">
                <span><i class="fas fa-sign-in-alt"></i> ${e.hora_entrada?.slice(0,5)||'—'}</span>
                <span><i class="fas fa-utensils"></i> ${e.hora_almoco_saida?.slice(0,5)||'—'} – ${e.hora_almoco_retorno?.slice(0,5)||'—'}</span>
                <span><i class="fas fa-sign-out-alt"></i> ${e.hora_saida?.slice(0,5)||'—'}</span>
                <span><i class="fas fa-clock"></i> ${cargaLabel} / ${e.tolerancia_minutos??10}min tolerância</span>
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

async function _gerarRelatorio(tipo) {
    const params    = _getRelPeriodoParams();
    if (!params) return;
    const dept      = document.getElementById('rel-departamento').value;
    const colab_id  = document.getElementById('rel-colaborador-id').value;

    const wrap   = document.getElementById('relatorio-resultado');
    const titulo = document.getElementById('relatorio-titulo');
    const body   = document.getElementById('relatorio-conteudo');
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
            if (!colab_id) { _toast('Selecione um colaborador para o espelho', 'error'); return; }
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
            if (!colab_id) { _toast('Selecione um colaborador para o banco de horas', 'error'); return; }
            if (params.tipo === 'personalizado') {
                url = `../api/api_rh_relatorios.php?acao=banco_horas&colaborador_id=${colab_id}&data_inicio=${params.data_inicio}&data_fim=${params.data_fim}`;
            } else {
                url = `../api/api_rh_relatorios.php?acao=banco_horas&colaborador_id=${colab_id}&ate_mes=${params.mes}&ate_ano=${params.ano}`;
            }
            tituloText = `Banco de Horas — ${params.label}`;
            // Mostra painel de registro de abatimento/pagamento
            { const p = document.getElementById('bh-registrar-painel'); if (p) { p.style.display=''; p.dataset.colaboradorId = colab_id; } }
            { const dtInp = document.getElementById('bh-reg-data'); if (dtInp && !dtInp.value) dtInp.value = new Date().toISOString().slice(0,10); }
            break;
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

    try {
        const r = await fetch(url, { credentials: 'include' });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);
        _ultimoRelDados = d.dados;
        body.innerHTML = _renderRelatorio(tipo, d.dados);
        wrap.scrollIntoView({ behavior: 'smooth' });
    } catch (err) {
        body.innerHTML = `<p style="color:#dc2626;padding:16px;"><i class="fas fa-exclamation-triangle"></i> ${err.message}</p>`;
    }
}

function _renderRelatorio(tipo, dados) {
    if (tipo === 'totais_horas') {
        if (!dados?.length) return '<p style="padding:16px;color:var(--text-secondary,#64748b);">Nenhum dado encontrado.</p>';
        return `<div class="table-container"><table class="data-table">
            <thead><tr><th>Nome</th><th>Cargo</th><th>Depto</th><th>Contrato</th><th>Trabalhado</th><th>Extra</th><th>Atraso</th><th>Faltas</th><th>Folgas</th><th>Status</th></tr></thead>
            <tbody>${dados.map(r => `<tr>
                <td>${_esc(r.nome)}</td><td>${_esc(r.cargo||'—')}</td><td>${_esc(r.departamento||'—')}</td>
                <td>${r.tipo_contrato?.toUpperCase()||'—'}</td>
                <td>${r.total_horas_trabalhadas_fmt||'—'}</td>
                <td style="color:#16a34a;">${r.total_horas_extras_fmt||'—'}</td>
                <td style="color:#dc2626;">${r.total_atraso_fmt||'—'}</td>
                <td>${r.total_faltas??'—'}</td><td>${r.total_folgas??'—'}</td>
                <td>${r.periodo_status ? `<span class="status-${r.periodo_status}">${r.periodo_status}</span>` : '—'}</td>
            </tr>`).join('')}</tbody></table></div>`;
    }

    if (tipo === 'espelho_ponto') {
        const diasPt = {Monday:'Segunda',Tuesday:'Terça',Wednesday:'Quarta',Thursday:'Quinta',Friday:'Sexta',Saturday:'Sábado',Sunday:'Domingo'};
        const { cabecalho: c, lancamentos: l } = dados;
        if (!c) return '<p style="padding:16px;">Nenhum dado encontrado.</p>';
        const info = `<div style="background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:14px 18px;margin-bottom:14px;border:1px solid var(--border-color,#e2e8f0);">
            <strong>${_esc(c.nome)}</strong> · ${_esc(c.cargo||'—')} · ${_esc(c.departamento||'—')}
            <div style="margin-top:6px;font-size:12px;color:var(--text-secondary,#64748b);display:flex;gap:16px;flex-wrap:wrap;">
                <span>Trabalhado: <strong>${c.total_horas_trabalhadas_fmt||'—'}</strong></span>
                <span>Extra: <strong style="color:#16a34a;">${c.total_horas_extras_fmt||'—'}</strong></span>
                <span>Atraso: <strong style="color:#dc2626;">${c.total_atraso_fmt||'—'}</strong></span>
                <span>Faltas: <strong>${c.total_faltas||0}</strong></span>
                <span>Folgas: <strong>${c.total_folgas||0}</strong></span>
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
                <td>${r.data_fmt}</td><td>${diaPt}</td><td>${_tipoDia(r.tipo_dia)}</td>
                <td>${r.he||'—'}</td><td>${r.has||'—'}</td><td>${r.har||'—'}</td><td>${r.hs||'—'}</td>
                <td>${r.horas_trab_fmt||'—'}</td>
                <td style="color:#16a34a;font-weight:600;">${r.horas_extra_fmt && r.horas_extra_fmt!=='00:00' ? r.horas_extra_fmt : '—'}</td>
                <td style="color:#dc2626;font-weight:600;">${r.atraso_fmt && r.atraso_fmt!=='00:00' ? r.atraso_fmt : '—'}</td>
                <td>${_esc(r.observacoes||'')}</td>
            </tr>`;}).join('')}</tbody></table></div>`;
    }

    if (tipo === 'banco_horas') {
        // Modo ledger (novo — rh_banco_horas disponível)
        if (dados.modo === 'ledger') {
            const { linhas, saldo_global_fmt, saldo_global, total_credito_fmt, total_debito_fmt } = dados;
            const saldoCor = saldo_global >= 0 ? '#16a34a' : '#dc2626';
            const resumo = `<div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 16px;min-width:140px;">
                    <div style="font-size:11px;color:#166534;font-weight:600;">CRÉDITO TOTAL</div>
                    <div style="font-size:18px;font-weight:700;color:#16a34a;">+${total_credito_fmt}</div>
                </div>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 16px;min-width:140px;">
                    <div style="font-size:11px;color:#991b1b;font-weight:600;">DÉBITO TOTAL</div>
                    <div style="font-size:18px;font-weight:700;color:#dc2626;">-${total_debito_fmt}</div>
                </div>
                <div style="background:#f8faff;border:2px solid ${saldoCor};border-radius:8px;padding:10px 16px;min-width:140px;">
                    <div style="font-size:11px;color:#334155;font-weight:600;">SALDO ATUAL</div>
                    <div style="font-size:18px;font-weight:700;color:${saldoCor};">${saldo_global_fmt}</div>
                </div>
            </div>`;

            if (!linhas?.length) return resumo + `<p style="padding:8px 0;color:#64748b;">Nenhum lançamento no período selecionado.<br>
                <small>Ative o banco de horas na escala do colaborador e recalcule os lançamentos de ponto.</small></p>`;

            const typeLabel = { credito:'Crédito Extra', debito:'Débito', abatimento:'Abatimento', pagamento:'Pagamento' };
            const typeColor = { credito:'#16a34a', debito:'#dc2626', abatimento:'#7c3aed', pagamento:'#0369a1' };

            return resumo + `<div class="table-container"><table class="data-table">
                <thead><tr><th>Data</th><th>Tipo</th><th>Minutos</th><th>Descrição</th><th>Saldo Corrente</th><th>Usuário</th></tr></thead>
                <tbody>${linhas.map(l => `<tr>
                    <td>${_esc(l.data_fmt)}</td>
                    <td><span style="color:${typeColor[l.tipo]||'#334155'};font-weight:600;">${typeLabel[l.tipo]||l.tipo}</span></td>
                    <td style="font-family:monospace;color:${typeColor[l.tipo]||'#334155'};">${l.sinal}${_esc(l.minutos_fmt)}</td>
                    <td>${_esc(l.descricao)}</td>
                    <td style="font-family:monospace;font-weight:600;color:${l.saldo_corrente>=0?'#16a34a':'#dc2626'};">${_esc(l.saldo_corrente_fmt)}</td>
                    <td style="font-size:11px;color:#64748b;">${_esc(l.usuario||'Sistema')}</td>
                </tr>`).join('')}</tbody>
            </table></div>`;
        }

        // Modo agregado (fallback — rh_banco_horas não existe ainda)
        const { meses, total_acumulado_fmt } = dados;
        if (!meses?.length) return '<p style="padding:16px;">Nenhum dado.</p>';
        return `<div class="table-container"><table class="data-table">
            <thead><tr><th>Mês/Ano</th><th>Trabalhado</th><th>Extras</th><th>Atraso</th><th>Saldo</th><th>Acumulado</th></tr></thead>
            <tbody>${meses.map(m => `<tr>
                <td>${_nomeMes(m.mes)}/${m.ano}</td>
                <td>${_minParaHoras(m.total_horas_trabalhadas_min)}</td>
                <td style="color:#16a34a;">${_minParaHoras(m.total_horas_extras_min)}</td>
                <td style="color:#dc2626;">${_minParaHoras(m.total_atraso_min)}</td>
                <td style="color:${m.saldo_min>=0?'#16a34a':'#dc2626'};">${m.saldo_fmt}</td>
                <td style="color:${m.acumulado_min>=0?'#16a34a':'#dc2626'};font-weight:600;">${m.acumulado_fmt}</td>
            </tr>`).join('')}
            <tr style="font-weight:700;border-top:2px solid var(--border-color,#e2e8f0);">
                <td colspan="5" style="text-align:right;">Total acumulado:</td>
                <td style="color:${total_acumulado_fmt?.startsWith('-')?'#dc2626':'#16a34a'};">${total_acumulado_fmt}</td>
            </tr></tbody></table></div>`;
    }

    if (tipo === 'aniversariantes') {
        if (!dados?.length) return '<p style="padding:16px;">Nenhum aniversariante.</p>';
        return `<div class="table-container"><table class="data-table">
            <thead><tr><th>Data</th><th>Nome</th><th>Cargo</th><th>Departamento</th></tr></thead>
            <tbody>${dados.map(r => `<tr>
                <td>🎂 ${r.aniversario}</td><td>${_esc(r.nome)}</td>
                <td>${_esc(r.cargo||'—')}</td><td>${_esc(r.departamento||'—')}</td>
            </tr>`).join('')}</tbody></table></div>`;
    }

    // Tabela genérica para faltas, extras, atrasos
    if (!dados?.length) return '<p style="padding:16px;color:var(--text-secondary,#64748b);">Nenhum dado encontrado.</p>';

    const keys = Object.keys(dados[0]);
    return `<div class="table-container"><table class="data-table">
        <thead><tr>${keys.map(k => `<th>${k}</th>`).join('')}</tr></thead>
        <tbody>${dados.map(r => `<tr>${keys.map(k => `<td>${r[k]??'—'}</td>`).join('')}</tr>`).join('')}</tbody>
        </table></div>`;
}

function _gerarRelatorioPDF() {
    if (!_ultimoRelTipo || !_ultimoRelDados) {
        return _toast('Gere um relatório primeiro antes de exportar para PDF', 'info');
    }
    const params   = _getRelPeriodoParams();
    if (!params) return;
    const dept     = document.getElementById('rel-departamento').value;
    const colab_id = document.getElementById('rel-colaborador-id').value;

    let url = `../api/api_rh_relatorio_pdf.php?tipo=${_ultimoRelTipo}`;
    if (params.tipo === 'personalizado') {
        url += `&data_inicio=${params.data_inicio}&data_fim=${params.data_fim}`;
    } else {
        url += `&mes=${params.mes}&ano=${params.ano}`;
    }
    if (dept)     url += `&departamento=${encodeURIComponent(dept)}`;
    if (colab_id) url += `&colaborador_id=${colab_id}`;

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
        const opts = d.dados.map(c => `<option value="${c.id}">${_esc(c.nome)}</option>`).join('');
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

function _escAttr(str) {
    return String(str ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/\r?\n/g, ' ');
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
        const cargaLabel = _emCargaCalc(cfg.hora_entrada, cfg.hora_saida, cfg.intervalo_min);

        return `<tr data-dia="${dia}" style="border-bottom:1px solid #e9d5ff;opacity:${op};transition:opacity .2s;">
            <td style="padding:7px 10px;font-weight:600;font-size:12px;">${_DIAS_LABEL_EM[dia]}</td>
            <td style="padding:7px 10px;text-align:center;">
                <input type="checkbox" class="em-ativo" data-dia="${dia}" ${ativo?'checked':''}
                       style="width:15px;height:15px;accent-color:#7c3aed;cursor:pointer;"
                       onchange="window._emToggleDia(this)">
            </td>
            <td style="padding:3px 5px;"><input type="time" class="em-entrada" data-dia="${dia}" value="${cfg.hora_entrada||''}" ${dis} style="width:85px;font-size:12px;" oninput="window._emAtualizarCarga('${dia}')"></td>
            <td style="padding:3px 5px;"><input type="time" class="em-als" data-dia="${dia}" value="${cfg.hora_almoco_saida||''}" ${dis} style="width:85px;font-size:12px;"></td>
            <td style="padding:3px 5px;"><input type="time" class="em-alr" data-dia="${dia}" value="${cfg.hora_almoco_retorno||''}" ${dis} style="width:85px;font-size:12px;"></td>
            <td style="padding:3px 5px;"><input type="time" class="em-saida" data-dia="${dia}" value="${cfg.hora_saida||''}" ${dis} style="width:85px;font-size:12px;" oninput="window._emAtualizarCarga('${dia}')"></td>
            <td style="padding:3px 5px;"><input type="number" class="em-int" data-dia="${dia}" value="${cfg.intervalo_min??0}" min="0" max="180" ${dis} style="width:55px;font-size:12px;" oninput="window._emAtualizarCarga('${dia}')"></td>
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
    const painel    = document.getElementById('bh-registrar-painel');
    const colab_id  = painel?.dataset?.colaboradorId || document.getElementById('rel-colaborador-id')?.value;
    const tipo      = document.getElementById('bh-reg-tipo')?.value;
    const minutos   = parseInt(document.getElementById('bh-reg-minutos')?.value || '0');
    const data      = document.getElementById('bh-reg-data')?.value;
    const descricao = document.getElementById('bh-reg-descricao')?.value?.trim();

    if (!colab_id)  return _toast('Colaborador não identificado. Gere o relatório de banco de horas primeiro.', 'error');
    if (!minutos || minutos <= 0) return _toast('Informe a quantidade de minutos.', 'error');
    if (!descricao) return _toast('Informe uma descrição.', 'error');

    try {
        const r = await fetch('../api/api_rh_banco_horas.php?acao=registrar', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ colaborador_id: parseInt(colab_id), tipo, minutos, data, descricao }),
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) {
            document.getElementById('bh-reg-minutos').value  = '';
            document.getElementById('bh-reg-descricao').value = '';
            // Recarrega o relatório de banco de horas para mostrar o novo lançamento
            _gerarRelatorio('banco_horas');
        }
    } catch (err) { _toast(err.message, 'error'); }
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
        const id        = String(l.id);

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
                  <option value="total"  ${l.abono_extras === 'total'  ? 'selected' : ''}>Total (${_mh(l.horas_extras_min)})</option>
                  <option value="parcial"${l.abono_extras === 'parcial'? 'selected' : ''}>Parcial</option>
                </select>
                <input type="number" class="abono-extras-min-inp" data-id="${id}"
                  placeholder="minutos" min="1" max="${parseInt(l.horas_extras_min || 0)}"
                  value="${l.abono_extras_min || ''}"
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
                  <option value="total"  ${l.abono_atraso === 'total'  ? 'selected' : ''}>Total (${_mh(l.atraso_min)})</option>
                  <option value="parcial"${l.abono_atraso === 'parcial'? 'selected' : ''}>Parcial</option>
                </select>
                <input type="number" class="abono-atraso-min-inp" data-id="${id}"
                  placeholder="minutos" min="1" max="${parseInt(l.atraso_min || 0)}"
                  value="${l.abono_atraso_min || ''}"
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
            ? `<button onclick="window.AbonoPage.salvar(${l.id})"
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
