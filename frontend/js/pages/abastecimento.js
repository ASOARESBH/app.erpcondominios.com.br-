/**
 * ============================================================
 * MÓDULO: ABASTECIMENTO
 * ============================================================
 * Gerencia veículos, lançamentos de combustível, recargas de
 * saldo e relatórios analíticos.
 *
 * Abas:
 *  - Veículos: cadastro e listagem
 *  - Lançamento: registrar abastecimento + histórico
 *  - Recarga: registrar recarga de saldo + histórico
 *  - Relatórios: filtros, KPIs e tabela detalhada
 *
 * @module abastecimento
 * @version 2.0.0
 */
'use strict';

// ============================================================
// CONSTANTES
// ============================================================
const API = window.location.origin + '/api/api_abastecimento.php';

// ============================================================
// ESTADO DO MÓDULO
// ============================================================
let _state = {
    veiculos         : [],
    abastecimentos   : [],
    recargas         : [],
    usuarios         : [],
    saldoAtual       : 0,
    valorMinimoAlerta: 0,
    relatorioData    : [],
    currentTab       : 'veiculos',
    // Flags anti-duplicidade: impedem duplo clique e retry de rede
    _enviandoAbast   : false,
    _enviandoRecarga : false,
};

// Referências para remoção de listeners no destroy
let _listeners = [];

// ============================================================
// LIFECYCLE
// ============================================================
export function init() {
    console.log('[Abastecimento] Inicializando módulo v2.0...');

    _setupTabs();
    _setupFormVeiculo();
    _setupFormAbastecimento();
    _setupFormRecarga();
    _setupRelatorio();
    _setupModal();
    _setupBuscaVeiculo();

    _setDataAtual('data_abastecimento');
    _setDataAtual('data_recarga');

    // Expor funções globais para onclick inline
    window.AbastecimentoPage = {
        verDetalhesVeiculo : verDetalhesVeiculo,
    };

    // Botão de sincronização de saldo
    _on(document.getElementById('btnSincronizarSaldo'), 'click', _sincronizarSaldo);

    // Carregar dados iniciais
    _carregarVeiculos();
    _carregarAbastecimentos();
    _carregarRecargas();
    _atualizarSaldo();

    console.log('[Abastecimento] Módulo pronto.');
}

export function destroy() {
    console.log('[Abastecimento] Destruindo módulo...');
    // Remover todos os listeners registrados
    _listeners.forEach(({ el, type, fn }) => el.removeEventListener(type, fn));
    _listeners = [];
    delete window.AbastecimentoPage;
    // Reset de estado
    _state = {
        veiculos: [], abastecimentos: [], recargas: [], usuarios: [],
        saldoAtual: 0, valorMinimoAlerta: 0, relatorioData: [], currentTab: 'veiculos',
        _enviandoAbast: false, _enviandoRecarga: false,
    };
}

// ============================================================
// HELPER: registrar listener com cleanup automático
// ============================================================
function _on(el, type, fn) {
    if (!el) return;
    el.addEventListener(type, fn);
    _listeners.push({ el, type, fn });
}

// ============================================================
// TABS
// ============================================================
function _setupTabs() {
    document.querySelectorAll('.page-abastecimento .tab-button').forEach(btn => {
        _on(btn, 'click', () => _switchTab(btn.dataset.tab));
    });
}

function _switchTab(tabName) {
    document.querySelectorAll('.page-abastecimento .tab-button').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabName);
    });
    document.querySelectorAll('.page-abastecimento .tab-content').forEach(content => {
        content.classList.toggle('active', content.id === `tab-${tabName}`);
    });
    _state.currentTab = tabName;
    console.log(`[Abastecimento] Tab ativa: ${tabName}`);

    // Ações ao trocar de aba
    if (tabName === 'lancamento') {
        _carregarVeiculosSelect();
        _carregarUsuariosSelect();
        _atualizarSaldo();
    } else if (tabName === 'recarga') {
        _atualizarSaldo();
    } else if (tabName === 'relatorio') {
        _carregarVeiculosSelectFiltro();
    }
}

// ============================================================
// FORMULÁRIO: VEÍCULO
// ============================================================
function _setupFormVeiculo() {
    const form = document.getElementById('formVeiculo');
    _on(form, 'submit', async (e) => {
        e.preventDefault();
        await _cadastrarVeiculo();
    });

    const btnLimpar = document.getElementById('btnLimparVeiculo');
    _on(btnLimpar, 'click', () => form.reset());
}

async function _cadastrarVeiculo() {
    const placa = (document.getElementById('placa').value || '').toUpperCase().trim();

    if (!_validarPlaca(placa)) {
        _toast('Placa inválida! Use ABC-1234 (antigo) ou ABC1D23 (Mercosul)', 'error');
        return;
    }

    const payload = {
        action     : 'cadastrar_veiculo',
        placa      : placa,
        modelo     : (document.getElementById('modelo').value || '').trim(),
        ano        : document.getElementById('ano').value,
        cor        : (document.getElementById('cor').value || '').trim(),
        km_inicial : document.getElementById('km_inicial').value,
    };

    console.log('[Abastecimento] Cadastrando veículo:', payload.placa);

    try {
        const res  = await _post(payload);
        if (res.sucesso) {
            _toast('Veículo cadastrado com sucesso!', 'success');
            document.getElementById('formVeiculo').reset();
            await _carregarVeiculos();
        } else {
            _toast(res.mensagem || 'Erro ao cadastrar veículo', 'error');
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao cadastrar veículo:', err);
        _toast('Erro de comunicação com o servidor', 'error');
    }
}

async function _carregarVeiculos() {
    console.log('[Abastecimento] Carregando veículos...');
    try {
        const res = await _get('listar_veiculos');
        if (res.sucesso) {
            _state.veiculos = res.dados || [];
            _renderVeiculos(_state.veiculos);
            console.log(`[Abastecimento] ${_state.veiculos.length} veículos carregados.`);
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao carregar veículos:', err);
    }
}

function _renderVeiculos(lista) {
    const tbody = document.getElementById('listaVeiculos');
    if (!tbody) return;

    if (!lista || lista.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="7"><i class="fas fa-car"></i><p>Nenhum veículo cadastrado</p></td></tr>`;
        return;
    }

    tbody.innerHTML = lista.map(v => `
        <tr>
            <td><strong>${_esc(v.placa)}</strong></td>
            <td>${_esc(v.modelo)}</td>
            <td>${_esc(v.ano)}</td>
            <td>${_esc(v.cor)}</td>
            <td>${parseInt(v.km_inicial || 0).toLocaleString('pt-BR')} km</td>
            <td>${_formatarData(v.data_cadastro)}</td>
            <td>
                <button class="btn-info" onclick="AbastecimentoPage.verDetalhesVeiculo(${v.id})">
                    <i class="fas fa-eye"></i> Ver
                </button>
            </td>
        </tr>
    `).join('');
}

// ============================================================
// BUSCA DE VEÍCULOS
// ============================================================
function _setupBuscaVeiculo() {
    const input = document.getElementById('buscaVeiculo');
    if (!input) return;
    _on(input, 'input', () => {
        const termo = input.value.toLowerCase();
        const filtrados = _state.veiculos.filter(v =>
            v.placa.toLowerCase().includes(termo) ||
            v.modelo.toLowerCase().includes(termo)
        );
        _renderVeiculos(filtrados);
    });
}

// ============================================================
// FORMULÁRIO: ABASTECIMENTO
// ============================================================
function _setupFormAbastecimento() {
    const form = document.getElementById('formAbastecimento');
    _on(form, 'submit', async (e) => {
        e.preventDefault();
        await _lancarAbastecimento();
    });

    const btnLimpar = document.getElementById('btnLimparAbastecimento');
    _on(btnLimpar, 'click', () => {
        form.reset();
        _setDataAtual('data_abastecimento');
        _setEl('km_info', '');
        _esconderPreview();
    });

    // Cascata: veículo → KM info
    const selVeiculo = document.getElementById('veiculo_id');
    _on(selVeiculo, 'change', _onVeiculoChange);

    // Preview ao digitar litros/valor
    const inputLitros = document.getElementById('litros');
    const inputValor  = document.getElementById('valor_abastecimento');
    _on(inputLitros, 'input', _atualizarPreview);
    _on(inputValor,  'input', _atualizarPreview);

    // Atualizar lista
    const btnAtualizar = document.getElementById('btnAtualizarAbastecimentos');
    _on(btnAtualizar, 'click', _carregarAbastecimentos);
}

function _onVeiculoChange() {
    const veiculoId = document.getElementById('veiculo_id').value;
    if (!veiculoId) {
        _setEl('km_info', '');
        return;
    }
    const veiculo = _state.veiculos.find(v => v.id == veiculoId);
    const abastVeiculo = _state.abastecimentos.filter(a => a.veiculo_id == veiculoId);

    if (abastVeiculo.length > 0) {
        const ultimoKm = Math.max(...abastVeiculo.map(a => parseInt(a.km_abastecimento)));
        _setEl('km_info', `Último KM registrado: ${ultimoKm.toLocaleString('pt-BR')} km`);
    } else if (veiculo) {
        _setEl('km_info', `KM inicial do veículo: ${parseInt(veiculo.km_inicial || 0).toLocaleString('pt-BR')} km`);
    }
}

function _atualizarPreview() {
    const litros = parseFloat(document.getElementById('litros').value) || 0;
    const valor  = parseFloat(document.getElementById('valor_abastecimento').value) || 0;

    if (litros <= 0 || valor <= 0) {
        _esconderPreview();
        return;
    }

    const precoLitro  = valor / litros;
    const saldoApos   = _state.saldoAtual - valor;

    _setEl('prev_preco_litro', `R$ ${_formatarMoeda(precoLitro)}`);
    _setEl('prev_total', `R$ ${_formatarMoeda(valor)}`);

    const elSaldoApos = document.getElementById('prev_saldo_apos');
    if (elSaldoApos) {
        elSaldoApos.textContent = `R$ ${_formatarMoeda(saldoApos)}`;
        elSaldoApos.className = saldoApos < 0 ? 'negativo' : '';
    }

    const preview = document.getElementById('calcPreviewAbast');
    if (preview) preview.classList.add('show');
}

function _esconderPreview() {
    const preview = document.getElementById('calcPreviewAbast');
    if (preview) preview.classList.remove('show');
}

async function _lancarAbastecimento() {
    // ═══ PROTEÇÃO CAMADA 1: flag de envio em andamento ═══
    if (_state._enviandoAbast) {
        console.warn('[Abastecimento] Envio já em andamento — duplo clique ignorado.');
        return;
    }

    const veiculoId       = document.getElementById('veiculo_id').value;
    const kmAbastecimento = parseInt(document.getElementById('km_abastecimento').value);
    const valor           = parseFloat(document.getElementById('valor_abastecimento').value);

    // Validar KM
    const veiculo = _state.veiculos.find(v => v.id == veiculoId);
    if (!veiculo) {
        _toast('Selecione um veículo válido.', 'warning');
        return;
    }

    const abastVeiculo = _state.abastecimentos.filter(a => a.veiculo_id == veiculoId);
    let kmMinimo = parseInt(veiculo.km_inicial || 0);
    if (abastVeiculo.length > 0) {
        kmMinimo = Math.max(...abastVeiculo.map(a => parseInt(a.km_abastecimento)));
    }

    if (kmAbastecimento < kmMinimo) {
        _toast(`KM inválido! O KM deve ser ≥ ${kmMinimo.toLocaleString('pt-BR')} km`, 'error');
        return;
    }

    // Verificar saldo
    if (valor > _state.saldoAtual) {
        if (!confirm('Saldo insuficiente! O lançamento ficará negativo. Deseja continuar?')) return;
    }

    const payload = {
        action              : 'lancar_abastecimento',
        veiculo_id          : veiculoId,
        data_abastecimento  : document.getElementById('data_abastecimento').value,
        km_abastecimento    : kmAbastecimento,
        litros              : document.getElementById('litros').value,
        valor               : valor,
        tipo_combustivel    : document.getElementById('tipo_combustivel').value,
        operador_id         : document.getElementById('operador_id').value,
    };

    // ═══ PROTEÇÃO CAMADA 2: chave de idempotência (30s de janela) ═══
    payload._idempotency_key = _gerarIdempotencyKey(payload);

    // ═══ PROTEÇÃO CAMADA 3: desabilitar botão visualmente ═══
    _state._enviandoAbast = true;
    _setBtnLoading('formAbastecimento', true);

    console.log('[Abastecimento] Lançando abastecimento... key:', payload._idempotency_key);

    try {
        const res = await _post(payload);
        if (res.sucesso) {
            _toast('Abastecimento registrado com sucesso!', 'success');
            document.getElementById('formAbastecimento').reset();
            _setDataAtual('data_abastecimento');
            _setEl('km_info', '');
            _esconderPreview();
            await Promise.all([_carregarAbastecimentos(), _atualizarSaldo()]);
        } else {
            _toast(res.mensagem || 'Erro ao registrar abastecimento', 'error');
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao lançar abastecimento:', err);
        _toast('Erro de comunicação com o servidor', 'error');
    } finally {
        // Sempre reabilita o botão e libera a flag, mesmo em caso de erro
        _state._enviandoAbast = false;
        _setBtnLoading('formAbastecimento', false);
    }
}

async function _carregarAbastecimentos() {
    console.log('[Abastecimento] Carregando abastecimentos...');
    try {
        const res = await _get('listar_abastecimentos');
        if (res.sucesso) {
            _state.abastecimentos = res.dados || [];
            _renderAbastecimentos();
            console.log(`[Abastecimento] ${_state.abastecimentos.length} abastecimentos carregados.`);
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao carregar abastecimentos:', err);
    }
}

function _renderAbastecimentos() {
    const tbody = document.getElementById('listaAbastecimentos');
    if (!tbody) return;

    if (_state.abastecimentos.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="7"><i class="fas fa-gas-pump"></i><p>Nenhum abastecimento registrado</p></td></tr>`;
        return;
    }

    tbody.innerHTML = _state.abastecimentos.map(a => `
        <tr>
            <td>${_formatarData(a.data_abastecimento)}</td>
            <td>${_esc(a.veiculo_modelo)} <small style="color:#94a3b8;">(${_esc(a.veiculo_placa)})</small></td>
            <td>${parseInt(a.km_abastecimento || 0).toLocaleString('pt-BR')} km</td>
            <td>${parseFloat(a.litros || 0).toFixed(2)} L</td>
            <td><strong>R$ ${_formatarMoeda(a.valor)}</strong></td>
            <td>${_esc(a.tipo_combustivel)}</td>
            <td>${_esc(a.operador_nome)}</td>
        </tr>
    `).join('');
}

function _carregarVeiculosSelect() {
    const select = document.getElementById('veiculo_id');
    if (!select) return;
    select.innerHTML = '<option value="">Selecione um veículo...</option>' +
        _state.veiculos.map(v => `<option value="${v.id}">${_esc(v.placa)} — ${_esc(v.modelo)}</option>`).join('');
}

async function _carregarUsuariosSelect() {
    try {
        const res = await _get('listar_usuarios');
        if (res.sucesso) {
            _state.usuarios = res.dados || [];
            const select = document.getElementById('operador_id');
            if (select) {
                select.innerHTML = '<option value="">Selecione um operador...</option>' +
                    _state.usuarios.map(u => `<option value="${u.id}">${_esc(u.nome)}</option>`).join('');
            }
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao carregar usuários:', err);
    }
}

// ============================================================
// FORMULÁRIO: RECARGA
// ============================================================
function _setupFormRecarga() {
    const form = document.getElementById('formRecarga');
    _on(form, 'submit', async (e) => {
        e.preventDefault();
        await _registrarRecarga();
    });

    const btnLimpar = document.getElementById('btnLimparRecarga');
    _on(btnLimpar, 'click', () => {
        form.reset();
        _setDataAtual('data_recarga');
    });

    const btnAtualizar = document.getElementById('btnAtualizarRecargas');
    _on(btnAtualizar, 'click', _carregarRecargas);
}

async function _registrarRecarga() {
    // ═══ PROTEÇÃO CAMADA 1: flag de envio em andamento ═══
    if (_state._enviandoRecarga) {
        console.warn('[Abastecimento] Recarga já em andamento — duplo clique ignorado.');
        return;
    }

    const valorRecarga = parseFloat(document.getElementById('valor_recarga').value);
    if (!valorRecarga || valorRecarga <= 0) {
        _toast('Informe um valor de recarga válido.', 'warning');
        return;
    }

    const payload = {
        action        : 'registrar_recarga',
        data_recarga  : document.getElementById('data_recarga').value,
        valor_recarga : valorRecarga,
        valor_minimo  : document.getElementById('valor_minimo').value,
        nf            : (document.getElementById('nf_recarga').value || '').trim(),
    };

    // ═══ PROTEÇÃO CAMADA 2: chave de idempotência (30s de janela) ═══
    payload._idempotency_key = _gerarIdempotencyKey(payload);

    // ═══ PROTEÇÃO CAMADA 3: desabilitar botão visualmente ═══
    _state._enviandoRecarga = true;
    _setBtnLoading('formRecarga', true);

    console.log('[Abastecimento] Registrando recarga... key:', payload._idempotency_key);

    try {
        const res = await _post(payload);
        if (res.sucesso) {
            _toast('Recarga registrada com sucesso!', 'success');
            document.getElementById('formRecarga').reset();
            _setDataAtual('data_recarga');
            await Promise.all([_carregarRecargas(), _atualizarSaldo()]);
        } else {
            _toast(res.mensagem || 'Erro ao registrar recarga', 'error');
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao registrar recarga:', err);
        _toast('Erro de comunicação com o servidor', 'error');
    } finally {
        // Sempre reabilita o botão e libera a flag, mesmo em caso de erro
        _state._enviandoRecarga = false;
        _setBtnLoading('formRecarga', false);
    }
}

async function _carregarRecargas() {
    console.log('[Abastecimento] Carregando recargas...');
    try {
        const res = await _get('listar_recargas');
        if (res.sucesso) {
            _state.recargas = res.dados || [];
            _renderRecargas();
            console.log(`[Abastecimento] ${_state.recargas.length} recargas carregadas.`);
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao carregar recargas:', err);
    }
}

function _renderRecargas() {
    const tbody = document.getElementById('listaRecargas');
    if (!tbody) return;

    if (_state.recargas.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="6"><i class="fas fa-wallet"></i><p>Nenhuma recarga registrada</p></td></tr>`;
        return;
    }

    tbody.innerHTML = _state.recargas.map(r => `
        <tr>
            <td>${_formatarData(r.data_recarga)}</td>
            <td><strong style="color:#16a34a;">R$ ${_formatarMoeda(r.valor_recarga)}</strong></td>
            <td>R$ ${_formatarMoeda(r.valor_minimo)}</td>
            <td>${_esc(r.nf) || '<span style="color:#94a3b8;">—</span>'}</td>
            <td>R$ ${_formatarMoeda(r.saldo_apos)}</td>
            <td>${_esc(r.usuario_nome)}</td>
        </tr>
    `).join('');
}

// ============================================================
// SALDO
// ============================================================
async function _atualizarSaldo() {
    try {
        const res = await _get('obter_saldo');
        if (!res.sucesso) return;

        _state.saldoAtual        = parseFloat(res.saldo || 0);
        _state.valorMinimoAlerta = parseFloat(res.valor_minimo || 0);

        const elKpiValor = document.getElementById('saldoKpiValor');
        const elKpi      = document.getElementById('saldoKpi');

        if (elKpiValor) elKpiValor.textContent = 'R$ ' + _formatarMoeda(_state.saldoAtual);

        if (elKpi) {
            elKpi.classList.remove('negativo', 'alerta');
            if (_state.saldoAtual < 0) {
                elKpi.classList.add('negativo');
            } else if (_state.saldoAtual <= _state.valorMinimoAlerta) {
                elKpi.classList.add('alerta');
            }
        }

        // Alerta de saldo baixo no formulário de lançamento
        const alertSemSaldo = document.getElementById('alertSemSaldo');
        if (alertSemSaldo) {
            alertSemSaldo.style.display = _state.saldoAtual <= 0 ? 'flex' : 'none';
        }

        console.log(`[Abastecimento] Saldo atualizado: R$ ${_formatarMoeda(_state.saldoAtual)}`);
    } catch (err) {
        console.error('[Abastecimento] Erro ao atualizar saldo:', err);
    }
}

// ============================================================
// SINCRONIZAR SALDO (recalcular do zero com base no banco)
// ============================================================
async function _sincronizarSaldo() {
    const btn = document.getElementById('btnSincronizarSaldo');
    const icon = btn?.querySelector('i');

    // Feedback visual: spinner no botão
    if (btn) btn.disabled = true;
    if (icon) icon.className = 'fas fa-spinner fa-spin';

    console.log('[Abastecimento] Iniciando recálculo de saldo...');

    try {
        const res = await _get('recalcular_saldo');

        if (!res.sucesso) throw new Error(res.mensagem);

        const anterior = _formatarMoeda(res.saldo_anterior);
        const correto  = _formatarMoeda(res.saldo_correto);
        const dif      = res.diferenca;

        console.log(`[Abastecimento] Saldo recalculado: R$ ${anterior} → R$ ${correto} (diferença: R$ ${_formatarMoeda(dif)})`);

        // Atualizar exibição do KPI
        await _atualizarSaldo();

        // Toast informativo
        const msg = dif === 0
            ? `Saldo já estava correto: R$ ${correto}`
            : `Saldo corrigido: R$ ${anterior} → R$ ${correto}`;
        _toast(msg, dif === 0 ? 'info' : 'success');

    } catch (err) {
        console.error('[Abastecimento] Erro ao sincronizar saldo:', err);
        _toast('Erro ao recalcular saldo: ' + err.message, 'error');
    } finally {
        if (btn)  btn.disabled = false;
        if (icon) icon.className = 'fas fa-sync-alt';
    }
}

// ============================================================
// RELATÓRIO
// ============================================================
function _setupRelatorio() {
    const btnGerar    = document.getElementById('btnGerarRelatorio');
    const btnExportar = document.getElementById('btnExportarRelatorio');

    _on(btnGerar,    'click', _gerarRelatorio);
    _on(btnExportar, 'click', _exportarCSV);

    // Vincular botão de PDF (já existe no HTML)
    const btnPDF = document.getElementById('btnExportarPDF');
    if (btnPDF) {
        _on(btnPDF, 'click', () => {
            const veiculoId  = document.getElementById('filtro_veiculo').value;
            const dataInicio = document.getElementById('filtro_data_inicio').value;
            const dataFim    = document.getElementById('filtro_data_fim').value;
            const combustivel= document.getElementById('filtro_combustivel').value;

            let url = `/api/api_relatorio_abastecimento_pdf.php?print=true`;
            if (veiculoId)   url += `&veiculo_id=${encodeURIComponent(veiculoId)}`;
            if (dataInicio)  url += `&data_inicio=${encodeURIComponent(dataInicio)}`;
            if (dataFim)     url += `&data_fim=${encodeURIComponent(dataFim)}`;
            if (combustivel) url += `&combustivel=${encodeURIComponent(combustivel)}`;

            console.log('[Abastecimento] Abrindo relatório PDF:', url);
            window.open(url, '_blank');
        });
    }
}

function _carregarVeiculosSelectFiltro() {
    const select = document.getElementById('filtro_veiculo');
    if (!select) return;
    select.innerHTML = '<option value="">Todos os veículos</option>' +
        _state.veiculos.map(v => `<option value="${v.id}">${_esc(v.placa)} — ${_esc(v.modelo)}</option>`).join('');
}

async function _gerarRelatorio() {
    const veiculoId  = document.getElementById('filtro_veiculo').value;
    const dataInicio = document.getElementById('filtro_data_inicio').value;
    const dataFim    = document.getElementById('filtro_data_fim').value;
    const combustivel= document.getElementById('filtro_combustivel').value;

    let url = `${API}?action=relatorio`;
    if (veiculoId)   url += `&veiculo_id=${encodeURIComponent(veiculoId)}`;
    if (dataInicio)  url += `&data_inicio=${encodeURIComponent(dataInicio)}`;
    if (dataFim)     url += `&data_fim=${encodeURIComponent(dataFim)}`;
    if (combustivel) url += `&combustivel=${encodeURIComponent(combustivel)}`;

    console.log('[Abastecimento] Gerando relatório...');

    try {
        const res = await fetch(url, { credentials: 'include' });
        const data = await res.json();

        if (data.sucesso) {
            _state.relatorioData = data.dados || [];
            _renderRelatorio(_state.relatorioData);

            const btnExportar = document.getElementById('btnExportarRelatorio');
            if (btnExportar) btnExportar.disabled = _state.relatorioData.length === 0;
        } else {
            _toast(data.mensagem || 'Erro ao gerar relatório', 'error');
        }
    } catch (err) {
        console.error('[Abastecimento] Erro ao gerar relatório:', err);
        _toast('Erro de comunicação com o servidor', 'error');
    }
}

function _renderRelatorio(dados) {
    const secao = document.getElementById('secaoRelatorio');

    if (!dados || dados.length === 0) {
        if (secao) secao.style.display = 'none';
        _toast('Nenhum registro encontrado com os filtros selecionados', 'warning');
        return;
    }

    // KPIs
    const totalAbast  = dados.length;
    const totalLitros = dados.reduce((s, d) => s + parseFloat(d.litros || 0), 0);
    const totalValor  = dados.reduce((s, d) => s + parseFloat(d.valor || 0), 0);

    let somaConsumo = 0, countConsumo = 0;
    dados.forEach((d, i) => {
        if (i > 0 && d.veiculo_id === dados[i - 1].veiculo_id) {
            const km = parseInt(d.km_abastecimento) - parseInt(dados[i - 1].km_abastecimento);
            const litrosAnt = parseFloat(dados[i - 1].litros);
            if (km > 0 && litrosAnt > 0) { somaConsumo += km / litrosAnt; countConsumo++; }
        }
    });
    const mediaConsumo = countConsumo > 0 ? somaConsumo / countConsumo : 0;

    _setEl('rel_total_abast',   totalAbast.toString());
    _setEl('rel_total_litros',  `${totalLitros.toFixed(2)} L`);
    _setEl('rel_total_valor',   `R$ ${_formatarMoeda(totalValor)}`);
    _setEl('rel_media_consumo', `${mediaConsumo.toFixed(2)} km/L`);

    // Tabela
    const tbody = document.getElementById('relatorioDetalhado');
    if (tbody) {
        tbody.innerHTML = dados.map((d, i) => {
            let kmRodado = '—', consumo = '—';
            const precoLitro = (parseFloat(d.valor || 0) / parseFloat(d.litros || 1)).toFixed(2);

            if (i > 0 && d.veiculo_id === dados[i - 1].veiculo_id) {
                const km = parseInt(d.km_abastecimento) - parseInt(dados[i - 1].km_abastecimento);
                const litrosAnt = parseFloat(dados[i - 1].litros);
                if (km > 0) {
                    kmRodado = km.toLocaleString('pt-BR') + ' km';
                    if (litrosAnt > 0) consumo = (km / litrosAnt).toFixed(2) + ' km/L';
                }
            }

            return `
                <tr>
                    <td>${_formatarData(d.data_abastecimento)}</td>
                    <td>${_esc(d.veiculo_modelo)}</td>
                    <td><strong>${_esc(d.veiculo_placa)}</strong></td>
                    <td>${parseInt(d.km_abastecimento || 0).toLocaleString('pt-BR')} km</td>
                    <td>${kmRodado}</td>
                    <td>${parseFloat(d.litros || 0).toFixed(2)} L</td>
                    <td>R$ ${_formatarMoeda(d.valor)}</td>
                    <td>R$ ${precoLitro}</td>
                    <td>${consumo}</td>
                    <td>${_esc(d.tipo_combustivel)}</td>
                    <td>${_esc(d.operador_nome)}</td>
                </tr>
            `;
        }).join('');
    }

    if (secao) secao.style.display = 'block';
    console.log(`[Abastecimento] Relatório gerado: ${dados.length} registros.`);
}

function _exportarCSV() {
    if (!_state.relatorioData || _state.relatorioData.length === 0) {
        _toast('Gere o relatório antes de exportar', 'warning');
        return;
    }

    const headers = ['Data/Hora','Modelo','Placa','KM','Litros','Valor','Combustível','Operador'];
    const rows = _state.relatorioData.map(d => [
        _formatarData(d.data_abastecimento),
        d.veiculo_modelo,
        d.veiculo_placa,
        d.km_abastecimento,
        parseFloat(d.litros).toFixed(2),
        parseFloat(d.valor).toFixed(2),
        d.tipo_combustivel,
        d.operador_nome,
    ]);

    const csv = [headers, ...rows].map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `abastecimento_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);

    _toast('CSV exportado com sucesso!', 'success');
}

// ============================================================
// MODAL: DETALHES DO VEÍCULO
// ============================================================
function _setupModal() {
    const fechar = [
        document.getElementById('btnFecharModalVeiculo'),
        document.getElementById('btnFecharModalVeiculoFooter'),
    ];
    fechar.forEach(btn => _on(btn, 'click', () => _fecharModal('modalDetalhesVeiculo')));

    const overlay = document.getElementById('modalDetalhesVeiculo');
    _on(overlay, 'click', (e) => {
        if (e.target === overlay) _fecharModal('modalDetalhesVeiculo');
    });
}

function verDetalhesVeiculo(id) {
    const veiculo = _state.veiculos.find(v => v.id == id);
    if (!veiculo) return;

    const abastVeiculo  = _state.abastecimentos.filter(a => a.veiculo_id == id);
    const totalAbast    = abastVeiculo.length;
    const totalLitros   = abastVeiculo.reduce((s, a) => s + parseFloat(a.litros || 0), 0);
    const totalValor    = abastVeiculo.reduce((s, a) => s + parseFloat(a.valor || 0), 0);

    const content = document.getElementById('detalhesVeiculoContent');
    if (content) {
        content.innerHTML = `
            <div class="info-box">
                <strong><i class="fas fa-car" style="margin-right:6px;color:#2563eb;"></i>Dados do Veículo</strong>
                <p><strong>Placa:</strong> ${_esc(veiculo.placa)}</p>
                <p><strong>Modelo:</strong> ${_esc(veiculo.modelo)}</p>
                <p><strong>Ano:</strong> ${_esc(veiculo.ano)}</p>
                <p><strong>Cor:</strong> ${_esc(veiculo.cor)}</p>
                <p><strong>KM Inicial:</strong> ${parseInt(veiculo.km_inicial || 0).toLocaleString('pt-BR')} km</p>
                <p><strong>Cadastrado em:</strong> ${_formatarData(veiculo.data_cadastro)}</p>
            </div>
            <div class="info-box">
                <strong><i class="fas fa-chart-bar" style="margin-right:6px;color:#16a34a;"></i>Estatísticas</strong>
                <p><strong>Total de Abastecimentos:</strong> ${totalAbast}</p>
                <p><strong>Total de Litros:</strong> ${totalLitros.toFixed(2)} L</p>
                <p><strong>Total Gasto:</strong> R$ ${_formatarMoeda(totalValor)}</p>
            </div>
        `;
    }

    _abrirModal('modalDetalhesVeiculo');
}

function _abrirModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('show');
}

function _fecharModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

// ============================================================
// ANTI-DUPLICIDADE: helpers de idempotência e controle de botão
// ============================================================

/**
 * Gera uma chave de idempotência baseada nos dados do payload.
 * Mesmos dados dentro de 30s = mesma chave = backend rejeita duplicata.
 */
function _gerarIdempotencyKey(payload) {
    // Janela de 30 segundos: arredonda timestamp para o intervalo
    const janela = Math.floor(Date.now() / 30000);
    const base   = JSON.stringify(payload) + '|' + janela;
    // Hash simples (djb2) — suficiente para idempotência de curto prazo
    let hash = 5381;
    for (let i = 0; i < base.length; i++) {
        hash = ((hash << 5) + hash) ^ base.charCodeAt(i);
    }
    return 'abast_' + (hash >>> 0).toString(16);
}

/**
 * Desabilita o botão de submit e exibe spinner enquanto a requisição está em andamento.
 */
function _setBtnLoading(formId, loading) {
    const form = document.getElementById(formId);
    if (!form) return;
    const btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    if (loading) {
        btn.disabled = true;
        btn._textoOriginal = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Aguarde...';
    } else {
        btn.disabled = false;
        if (btn._textoOriginal) btn.innerHTML = btn._textoOriginal;
    }
}

// ============================================================
// UTILITÁRIOS HTTP
// ============================================================
async function _get(action) {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
        credentials: 'include',
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

async function _post(payload) {
    // Inclui chave de idempotência no header se presente no payload
    const headers = { 'Content-Type': 'application/json' };
    if (payload._idempotency_key) {
        headers['X-Idempotency-Key'] = payload._idempotency_key;
    }
    const res = await fetch(API, {
        method     : 'POST',
        credentials: 'include',
        headers,
        body       : JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

// ============================================================
// UTILITÁRIOS DE UI
// ============================================================
function _setDataAtual(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    el.value = agora.toISOString().slice(0, 16);
}

function _setEl(id, valor, prop = 'textContent') {
    const el = document.getElementById(id);
    if (el) el[prop] = valor;
}

function _formatarMoeda(valor) {
    return parseFloat(valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function _formatarData(str) {
    if (!str) return '—';
    const d = new Date(str);
    return isNaN(d) ? str : d.toLocaleString('pt-BR');
}

function _validarPlaca(placa) {
    const regexAntigo  = /^[A-Z]{3}-[0-9]{4}$/;
    const regexMercosul = /^[A-Z]{3}[0-9][A-Z][0-9]{2}$/;
    return regexAntigo.test(placa) || regexMercosul.test(placa);
}

function _esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function _toast(mensagem, tipo = 'info') {
    const container = document.getElementById('toastContainerAbast');
    if (!container) return;

    const icons = {
        success : 'fa-check-circle',
        error   : 'fa-exclamation-circle',
        warning : 'fa-exclamation-triangle',
        info    : 'fa-info-circle',
    };

    const toast = document.createElement('div');
    toast.className = `toast ${tipo}`;
    toast.innerHTML = `<i class="fas ${icons[tipo] || icons.info}"></i><span>${_esc(mensagem)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastAbastIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}
