/**
 * ORDENS DE SERVIÇO — JavaScript
 * Módulo completo: Dashboard, Chamados, Configurações, Relatórios
 * Versão: 1.0 | Data: 2026-06-22
 * Padrão: ES6 Module com export init/destroy
 */
'use strict';
// ─── Configuração ─────────────────────────────────────────────────────
const API                = window.location.origin + '/api/api_ordens_servico.php';
const API_MORADORES      = window.location.origin + '/api/api_moradores.php';
const API_USUARIOS       = window.location.origin + '/api/api_usuarios.php';
const API_RH             = window.location.origin + '/api/api_rh_colaboradores.php';
const API_ESTOQUE        = window.location.origin + '/api/api_estoque.php';
const API_USUARIO_LOGADO = window.location.origin + '/api/api_usuario_logado.php';

// Estado global do módulo
const state = {
    abaAtiva: 'dashboard',
    paginaAtual: 1,
    filtros: { status: '', prioridade: '', departamento: '', busca: '', data_ini: '', data_fim: '' },
    osAtual: null,          // O.S aberta no modal de detalhe
    rhSelecionados: [],     // Colaboradores selecionados no form de nova O.S
    relDados: [],           // Dados do relatório gerado
    relColunas: [],         // Colunas do relatório atual
    relTipo: 'listagem_geral', // Tipo de relatório selecionado
    departamentos: [],      // Cache de departamentos
    assuntos: [],           // Cache de assuntos
    usuarios: [],           // Cache de usuários
    usuarioLogado: null,    // Usuário logado (para auto-preencher atendente)
    intAnexos: [],          // Arquivos anexados à interação em edição
    assuntosCache: [],      // Lista completa para paginação local
    assuntosPagina: 1,
    configHHCache: [],      // Lista completa para paginação local
    configHHPagina: 1,
    etapas: [],             // Cache de etapas de projeto (Módulo Projetos)
};

// ─── Utilitários ──────────────────────────────────────────────────────────
function log(msg, dados) {
    console.log('[OS]', msg, dados || '');
}

function toast(msg, tipo = 'sucesso') {
    const el = document.getElementById('os-toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'os-toast ' + tipo;
    el.style.display = 'block';
    clearTimeout(el._timeout);
    el._timeout = setTimeout(() => { el.style.display = 'none'; }, 4000);
}

async function _post(acao, dados = {}) {
    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ acao, ...dados })
        });
        const json = await res.json();
        log('POST ' + acao, json);
        return json;
    } catch (e) {
        log('ERRO POST ' + acao, e);
        return { sucesso: false, mensagem: 'Erro de comunicação com o servidor' };
    }
}

async function _get(acao, params = {}) {
    try {
        const qs = new URLSearchParams({ acao, ...params }).toString();
        const res = await fetch(`${API}?${qs}`, {
            credentials: 'include'
        });
        const json = await res.json();
        log('GET ' + acao, json);
        return json;
    } catch (e) {
        log('ERRO GET ' + acao, e);
        return { sucesso: false, mensagem: 'Erro de comunicação com o servidor' };
    }
}

// POST multipart (upload de imagem de capa, fotos de obra) — sem Content-Type
// manual para o browser montar o boundary do multipart/form-data corretamente.
async function _postMultipart(formData) {
    try {
        const res = await fetch(API, { method: 'POST', credentials: 'include', body: formData });
        const json = await res.json();
        log('POST multipart', json);
        return json;
    } catch (e) {
        log('ERRO POST multipart', e);
        return { sucesso: false, mensagem: 'Erro de comunicação com o servidor' };
    }
}

function formatarData(str) {
    if (!str) return '—';
    // Se já vem formatada (dd/mm/yyyy hh:mm) retorna direto
    if (/^\d{2}\/\d{2}\/\d{4}/.test(str)) return str;
    // Formato ISO
    const d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

function formatarDataSimples(str) {
    if (!str) return '—';
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(str)) return str;
    const d = new Date(str + 'T00:00:00');
    if (isNaN(d)) return str;
    return d.toLocaleDateString('pt-BR');
}

function badgeStatus(status) {
    const map = {
        aberto:     '<span class="os-badge os-badge-aberto">Aberto</span>',
        andamento:  '<span class="os-badge os-badge-andamento">Em Andamento</span>',
        finalizado: '<span class="os-badge os-badge-finalizado">Finalizado</span>',
        cancelado:  '<span class="os-badge os-badge-cancelado">Cancelado</span>',
    };
    return map[status] || status;
}

function badgePrioridade(p) {
    const map = {
        urgente: '<span class="os-badge os-badge-urgente">Urgente</span>',
        alta:    '<span class="os-badge os-badge-alta">Alta</span>',
        media:   '<span class="os-badge os-badge-media">Média</span>',
        baixa:   '<span class="os-badge os-badge-baixa">Baixa</span>',
    };
    return map[p] || p;
}

function iconeTipo(tipo) {
    const map = {
        comentario:   'fa-comment',
        andamento:    'fa-spinner',
        solucao:      'fa-check-circle',
        nota_interna: 'fa-sticky-note',
    };
    return map[tipo] || 'fa-circle';
}

// ─── Abas principais ──────────────────────────────────────────────────────
function initAbas() {
    document.querySelectorAll('.os-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            document.querySelectorAll('.os-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.os-tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            const content = document.getElementById('os-tab-' + tab);
            if (content) content.classList.add('active');
            state.abaAtiva = tab;
            if (tab === 'dashboard')     carregarDashboard();
            if (tab === 'chamados')      carregarChamados();
            if (tab === 'configuracoes') carregarConfiguracoes();
            if (tab === 'relatorios')    initRelatorios();
        });
    });
}

// ─── DASHBOARD ────────────────────────────────────────────────────────────
async function carregarDashboard() {
    const res = await _get('dashboard_kpis');
    if (!res.sucesso) { toast('Erro ao carregar dashboard', 'erro'); return; }
    const d = res.dados;

    document.getElementById('kpi-abertos').textContent    = d.abertos;
    document.getElementById('kpi-andamento').textContent  = d.andamento;
    document.getElementById('kpi-finalizados').textContent = d.finalizados;
    document.getElementById('kpi-urgentes').textContent   = d.urgentes_abertas;
    document.getElementById('kpi-tempo-medio').textContent = d.tempo_medio_horas + 'h';
    document.getElementById('kpi-prazo-vencido').textContent = d.prazo_vencido;

    // Barras de prioridade
    const total = Object.values(d.por_prioridade).reduce((a, b) => a + b, 0) || 1;
    const barsEl = document.getElementById('prioridade-bars');
    barsEl.innerHTML = ['urgente','alta','media','baixa'].map(p => {
        const qtd = d.por_prioridade[p] || 0;
        const pct = Math.round((qtd / total) * 100);
        const label = { urgente: 'Urgente', alta: 'Alta', media: 'Média', baixa: 'Baixa' }[p];
        return `<div class="os-prioridade-bar-item">
            <div class="os-prioridade-bar-label">${label}</div>
            <div class="os-prioridade-bar-track">
                <div class="os-prioridade-bar-fill ${p}" style="width:${pct}%"></div>
            </div>
            <div class="os-prioridade-bar-count">${qtd}</div>
        </div>`;
    }).join('');

    // Gráfico por departamento
    const deptEl  = document.getElementById('departamento-chart');
    const depData = d.por_departamento || [];
    if (!depData.length) {
        deptEl.innerHTML = '<div class="os-loading-text">Nenhum dado de departamento</div>';
    } else {
        const maxDept = Math.max(...depData.map(x => x.total));
        deptEl.innerHTML = depData.map((item, i) => {
            const pctAberta = Math.round((item.abertas   / maxDept) * 100);
            const pctFinal  = Math.round((item.finalizadas / maxDept) * 100);
            return `<div class="os-dept-bar-item">
                <div class="os-dept-bar-label" title="${item.departamento}">${item.departamento}</div>
                <div class="os-dept-bar-track">
                    <div class="os-dept-bar-seg os-dept-seg-aberta" data-w="${pctAberta}" style="width:0"></div>
                    <div class="os-dept-bar-seg os-dept-seg-final"  data-w="${pctFinal}"  style="width:0"></div>
                </div>
                <div class="os-dept-bar-count" title="${item.abertas} em aberto · ${item.finalizadas} finalizada(s)">${item.total}</div>
            </div>`;
        }).join('');
        // Animar barras após render
        requestAnimationFrame(() => requestAnimationFrame(() => {
            deptEl.querySelectorAll('.os-dept-bar-seg').forEach(el => {
                el.style.width = el.dataset.w + '%';
            });
        }));
    }

    // Ranking de unidades
    const rankEl   = document.getElementById('ranking-unidades');
    const rankData = d.top_unidades || [];
    if (!rankData.length) {
        rankEl.innerHTML = '<div class="os-loading-text">Nenhum chamado com unidade vinculada</div>';
    } else {
        const maxRank = rankData[0].total || 1;
        const medals  = ['os-rank-ouro','os-rank-prata','os-rank-bronze'];
        rankEl.innerHTML = rankData.map((u, i) => {
            const pct = Math.round((u.total / maxRank) * 100);
            return `<div class="os-rank-item">
                <div class="os-rank-pos ${medals[i] || 'os-rank-comum'}">${i + 1}</div>
                <div class="os-rank-info">
                    <div class="os-rank-nome" title="${u.unidade}">${u.unidade}</div>
                    <div class="os-rank-bar-track">
                        <div class="os-rank-bar-fill ${medals[i] || 'os-rank-comum'}" data-w="${pct}" style="width:0"></div>
                    </div>
                    <div class="os-rank-detalhe">
                        <span class="os-rank-tag aberta">${u.abertas} aberta${u.abertas !== 1 ? 's' : ''}</span>
                        <span class="os-rank-tag final">${u.finalizadas} finalizada${u.finalizadas !== 1 ? 's' : ''}</span>
                        ${u.canceladas ? `<span class="os-rank-tag cancel">${u.canceladas} cancelada${u.canceladas !== 1 ? 's' : ''}</span>` : ''}
                    </div>
                </div>
                <div class="os-rank-total">${u.total}</div>
            </div>`;
        }).join('');
        requestAnimationFrame(() => requestAnimationFrame(() => {
            rankEl.querySelectorAll('.os-rank-bar-fill').forEach(el => {
                el.style.width = el.dataset.w + '%';
            });
        }));
    }

    // Últimas OS
    const listaEl = document.getElementById('ultimas-os-lista');
    if (!d.ultimas_os || !d.ultimas_os.length) {
        listaEl.innerHTML = '<div class="os-loading-text">Nenhuma OS encontrada</div>';
    } else {
        listaEl.innerHTML = d.ultimas_os.map(os => `
            <div class="os-ultima-item" data-id="${os.id}" onclick="osVerDetalhe(${os.id})">
                <div class="os-ultima-numero">${os.numero}</div>
                <div class="os-ultima-titulo">${os.titulo}</div>
                ${badgeStatus(os.status)}
                <div class="os-ultima-data">${os.data_abertura}</div>
            </div>
        `).join('');
    }
}

// ─── CHAMADOS ─────────────────────────────────────────────────────────────
async function carregarChamados(pagina = 1) {
    state.paginaAtual = pagina;
    const params = {
        pagina,
        por_pagina: 20,
        status:       state.filtros.status,
        prioridade:   state.filtros.prioridade,
        departamento: state.filtros.departamento,
        busca:        state.filtros.busca,
        data_ini:     state.filtros.data_ini,
        data_fim:     state.filtros.data_fim,
    };
    const res = await _get('listar', params);
    const tbody = document.getElementById('tbody-os');
    if (!res.sucesso) {
        tbody.innerHTML = `<tr><td colspan="9" class="os-loading-text">Erro: ${res.mensagem}</td></tr>`;
        return;
    }
    const lista = res.dados.lista || [];
    if (!lista.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="os-loading-text">Nenhuma OS encontrada</td></tr>';
        document.getElementById('os-paginacao').innerHTML = '';
        return;
    }
    tbody.innerHTML = lista.map(os => {
        const isPortal = os.origem_portal === 'portal_morador';
        const precisaAssumir = isPortal && !os.assumido_por_id;
        const trStyle = isPortal ? 'background:linear-gradient(90deg,#fff7ed 0,transparent 8px);border-left:3px solid #d97706;' : '';
        return `
        <tr style="${trStyle}">
            <td>
                <strong style="color:var(--os-primary)">${os.numero}</strong>
                ${isPortal ? `<div style="margin-top:.2rem"><span style="display:inline-flex;align-items:center;gap:.25rem;font-size:.68rem;font-weight:700;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;border-radius:10px;padding:.1rem .45rem;"><i class="fas fa-mobile-alt"></i> Portal</span></div>` : ''}
                ${precisaAssumir ? `<div style="margin-top:.2rem"><span style="display:inline-flex;align-items:center;gap:.25rem;font-size:.68rem;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:10px;padding:.1rem .45rem;"><i class="fas fa-exclamation-triangle"></i> Assumir</span></div>` : ''}
            </td>
            <td>
                <div style="font-weight:600">${os.titulo}</div>
                ${os.assunto_nome ? `<div style="font-size:.78rem;color:#64748b">${os.assunto_nome}</div>` : ''}
            <td>
                ${os.morador_nome || '—'}
                ${os.morador_unidade ? `<div style="font-size:.78rem;color:#64748b">Unid. ${os.morador_unidade}</div>` : ''}
            </td>
            <td>${os.departamento || '—'}</td>
            <td>${badgePrioridade(os.prioridade)}</td>
            <td>${badgeStatus(os.status)}</td>
            <td style="white-space:nowrap;font-size:.82rem">${formatarData(os.data_abertura)}</td>
            <td>${os.atendente_nome || '—'}</td>
            <td>
                <button class="os-btn-acao ver" onclick="osVerDetalhe(${os.id})" title="Ver detalhes"><i class="fas fa-eye"></i></button>
                ${os.status !== 'finalizado' ? `<button class="os-btn-acao editar" onclick="osAbrirEditar(${os.id})" title="Editar"><i class="fas fa-edit"></i></button>` : ''}
                <button class="os-btn-acao imprimir" onclick="osImprimir(${os.id})" title="Imprimir / Gerar PDF"><i class="fas fa-print"></i></button>
                ${os.status !== 'finalizado' ? `<button class="os-btn-acao excluir" onclick="osExcluir(${os.id},'${os.numero}')" title="Excluir"><i class="fas fa-trash"></i></button>` : ''}
                ${precisaAssumir ? `<button class="os-btn-acao" style="background:#d97706;color:#fff;border-color:#d97706" onclick="osAbrirAssumirPortal(${os.id})" title="Assumir OS do Portal"><i class="fas fa-hand-paper"></i></button>` : ''}
            </td>
        </tr>
    `;
    }).join('');

    // Paginação
    renderizarPaginacao(res.dados.total, res.dados.por_pagina, pagina);
}

function renderizarPaginacao(total, porPagina, atual) {
    const paginas = Math.ceil(total / porPagina);
    const el = document.getElementById('os-paginacao');
    if (paginas <= 1) { el.innerHTML = ''; return; }
    let html = `<button class="os-pag-btn" onclick="osPaginar(${atual - 1})" ${atual === 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;
    for (let i = 1; i <= paginas; i++) {
        if (i === 1 || i === paginas || Math.abs(i - atual) <= 2) {
            html += `<button class="os-pag-btn ${i === atual ? 'active' : ''}" onclick="osPaginar(${i})">${i}</button>`;
        } else if (Math.abs(i - atual) === 3) {
            html += '<span style="padding:0 4px;color:#94a3b8">...</span>';
        }
    }
    html += `<button class="os-pag-btn" onclick="osPaginar(${atual + 1})" ${atual === paginas ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
    el.innerHTML = html;
}

// Expor para uso inline nos botões
window.osPaginar = (p) => carregarChamados(p);
window.osVerDetalhe = (id) => abrirDetalhe(id);
window.osAbrirEditar = (id) => abrirEditar(id);
window.osExcluir = (id, numero) => excluirOS(id, numero);

// ─── IMPRIMIR / GERAR PDF ─────────────────────────────────────────────────
function imprimirOS(id) {
    const url = window.location.origin + '/frontend/pages/imprimir_os.html?id=' + id;
    window.open(url, '_blank', 'width=900,height=750,scrollbars=yes,resizable=yes');
}
window.osImprimir = (id) => imprimirOS(id);

// ─── FILTROS ──────────────────────────────────────────────────────────────
function _coletarFiltros() {
    state.filtros.status       = document.getElementById('filtro-status')?.value      || '';
    state.filtros.prioridade   = document.getElementById('filtro-prioridade')?.value  || '';
    state.filtros.departamento = document.getElementById('filtro-departamento')?.value || '';
    state.filtros.busca        = document.getElementById('filtro-busca')?.value.trim() || '';
    state.filtros.data_ini     = document.getElementById('filtro-data-ini')?.value    || '';
    state.filtros.data_fim     = document.getElementById('filtro-data-fim')?.value    || '';
}

function _renderChipsAtivos() {
    const container = document.getElementById('osChipsAtivos');
    if (!container) return;
    const chips = [];
    const labels = {
        status:       { aberto: 'Aberto', andamento: 'Em Andamento', finalizado: 'Finalizado', cancelado: 'Cancelado' },
        prioridade:   { urgente: 'Urgente', alta: 'Alta', media: 'Média', baixa: 'Baixa' },
    };
    if (state.filtros.status)       chips.push({ campo: 'status',       label: 'Status: ' + (labels.status[state.filtros.status] || state.filtros.status) });
    if (state.filtros.prioridade)   chips.push({ campo: 'prioridade',   label: 'Prioridade: ' + (labels.prioridade[state.filtros.prioridade] || state.filtros.prioridade) });
    if (state.filtros.departamento) chips.push({ campo: 'departamento', label: 'Depto: ' + state.filtros.departamento });
    if (state.filtros.busca)        chips.push({ campo: 'busca',        label: '🔍 ' + state.filtros.busca });
    if (state.filtros.data_ini)     chips.push({ campo: 'data_ini',     label: 'De: ' + state.filtros.data_ini.split('-').reverse().join('/') });
    if (state.filtros.data_fim)     chips.push({ campo: 'data_fim',     label: 'Até: ' + state.filtros.data_fim.split('-').reverse().join('/') });

    if (!chips.length) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }
    container.style.display = 'flex';
    container.innerHTML = '<span class="os-chips-label">Filtros ativos:</span>'
        + chips.map(c => `<span class="os-chip" data-campo="${c.campo}">${c.label} <button onclick="osRemoverChip('${c.campo}')" title="Remover filtro">×</button></span>`).join('')
        + `<button class="os-chip-limpar-todos" onclick="osLimparFiltros()"><i class="fas fa-times"></i> Limpar todos</button>`;
}

function initFiltros() {
    // Botão Filtrar
    document.getElementById('btnFiltrar')?.addEventListener('click', () => {
        _coletarFiltros();
        _renderChipsAtivos();
        carregarChamados(1);
    });

    // Botão Limpar todos os filtros
    document.getElementById('btnLimparFiltros')?.addEventListener('click', () => {
        osLimparFiltros();
    });

    // Botão × dentro do campo busca
    const inputBusca = document.getElementById('filtro-busca');
    const btnLimparBusca = document.getElementById('btnLimparBusca');
    if (inputBusca && btnLimparBusca) {
        inputBusca.addEventListener('input', () => {
            btnLimparBusca.style.display = inputBusca.value ? 'flex' : 'none';
            // Debounce: filtra automaticamente 400ms após parar de digitar
            clearTimeout(inputBusca._debounce);
            inputBusca._debounce = setTimeout(() => {
                _coletarFiltros();
                _renderChipsAtivos();
                carregarChamados(1);
            }, 400);
        });
        inputBusca.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                clearTimeout(inputBusca._debounce);
                _coletarFiltros();
                _renderChipsAtivos();
                carregarChamados(1);
            }
        });
        btnLimparBusca.addEventListener('click', () => {
            inputBusca.value = '';
            btnLimparBusca.style.display = 'none';
            _coletarFiltros();
            _renderChipsAtivos();
            carregarChamados(1);
        });
    }

    // Selects disparam filtro imediato ao mudar
    ['filtro-status', 'filtro-prioridade', 'filtro-departamento'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => {
            _coletarFiltros();
            _renderChipsAtivos();
            carregarChamados(1);
        });
    });

    // Datas disparam filtro ao sair do campo
    ['filtro-data-ini', 'filtro-data-fim'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => {
            _coletarFiltros();
            _renderChipsAtivos();
            carregarChamados(1);
        });
    });
}

// Exposto globalmente para chips e botão limpar
window.osRemoverChip = function(campo) {
    state.filtros[campo] = '';
    const elMap = {
        status:       'filtro-status',
        prioridade:   'filtro-prioridade',
        departamento: 'filtro-departamento',
        busca:        'filtro-busca',
        data_ini:     'filtro-data-ini',
        data_fim:     'filtro-data-fim',
    };
    const el = document.getElementById(elMap[campo]);
    if (el) el.value = '';
    if (campo === 'busca') {
        const btn = document.getElementById('btnLimparBusca');
        if (btn) btn.style.display = 'none';
    }
    _renderChipsAtivos();
    carregarChamados(1);
};

window.osLimparFiltros = function() {
    ['filtro-status', 'filtro-prioridade', 'filtro-departamento', 'filtro-busca', 'filtro-data-ini', 'filtro-data-fim'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const btnLimpar = document.getElementById('btnLimparBusca');
    if (btnLimpar) btnLimpar.style.display = 'none';
    state.filtros = { status: '', prioridade: '', departamento: '', busca: '', data_ini: '', data_fim: '' };
    _renderChipsAtivos();
    carregarChamados(1);
};

// ─── MODAL: NOVA / EDITAR OS ──────────────────────────────────────────────
function abrirModalNova() {
    limparFormOS();
    document.getElementById('modal-os-titulo').innerHTML = '<i class="fas fa-plus-circle"></i> Nova Ordem de Serviço';
    document.getElementById('row-numero').style.display = 'none';
    // Auto-preencher atendente com o usuário logado
    if (state.usuarioLogado) {
        const sel = document.getElementById('os-atendente');
        if (sel) {
            const opt = Array.from(sel.options).find(o => o.value == state.usuarioLogado.id);
            if (opt) sel.value = opt.value;
        }
    }
    document.getElementById('modal-os').style.display = 'flex';
}

async function abrirEditar(id) {
    const res = await _get('buscar', { id });
    if (!res.sucesso) { toast('Erro ao carregar OS', 'erro'); return; }
    const os = res.dados;
    limparFormOS();
    document.getElementById('modal-os-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar O.S — ' + os.numero;
    document.getElementById('os-id').value = os.id;
    document.getElementById('os-numero-view').value = os.numero;
    document.getElementById('row-numero').style.display = 'flex';
    document.getElementById('os-titulo').value = os.titulo || '';
    document.getElementById('os-prioridade').value = os.prioridade || 'media';
    document.getElementById('os-assunto').value = os.assunto_id || '';
    document.getElementById('os-departamento').value = os.departamento || '';
    document.getElementById('os-horas-estimadas').value = os.horas_estimadas || '';
    document.getElementById('os-data-previsao').value = os.data_previsao || '';
    document.getElementById('os-atendente').value = os.atendente_id || '';
    document.getElementById('os-descricao').innerHTML = os.descricao || '';
    document.getElementById('os-projeto-publico').checked = !!(os.projeto_publico && Number(os.projeto_publico) === 1);
    _atualizarCamposProjetoOS(os.projeto_etapa_id, os.projeto_percentual);
    if (os.morador_id) {
        document.getElementById('os-morador-id').value = os.morador_id;
        document.getElementById('os-morador-nome').value = os.morador_nome;
        document.getElementById('os-morador-unidade').value = os.morador_unidade;
        document.getElementById('os-morador-busca').value = os.morador_nome + (os.morador_unidade ? ' — Unid. ' + os.morador_unidade : '');
        const tag = document.getElementById('os-morador-tag');
        tag.innerHTML = `<i class="fas fa-user"></i> ${os.morador_nome} — Unid. ${os.morador_unidade || '?'} <button onclick="limparMorador()">×</button>`;
        tag.style.display = 'inline-flex';
    }
    // RH
    if (os.recursos_humanos && os.recursos_humanos.length) {
        state.rhSelecionados = os.recursos_humanos.map(r => ({
            id: r.colaborador_id, nome: r.colaborador_nome, cargo: r.cargo, departamento: r.departamento
        }));
        renderizarRHTags();
    }
    document.getElementById('modal-os').style.display = 'flex';
}

function limparFormOS() {
    document.getElementById('os-id').value = '';
    document.getElementById('os-titulo').value = '';
    document.getElementById('os-prioridade').value = 'media';
    document.getElementById('os-assunto').value = '';
    document.getElementById('os-departamento').value = '';
    document.getElementById('os-horas-estimadas').value = '';
    document.getElementById('os-data-previsao').value = '';
    document.getElementById('os-atendente').value = '';
    document.getElementById('os-descricao').innerHTML = '';
    document.getElementById('os-morador-id').value = '';
    document.getElementById('os-morador-nome').value = '';
    document.getElementById('os-morador-unidade').value = '';
    document.getElementById('os-morador-busca').value = '';
    document.getElementById('os-morador-tag').style.display = 'none';
    document.getElementById('os-pai-id').value = '';
    document.getElementById('os-pai-busca').value = '';
    document.getElementById('os-pai-tag').style.display = 'none';
    document.getElementById('os-projeto-publico').checked = false;
    _atualizarCamposProjetoOS(null, '');
    state.rhSelecionados = [];
    renderizarRHTags();
}

// Mostra/oculta (via JS, sem reload) os campos de Etapa/Conclusão no
// modal de criar/editar O.S., conforme o checkbox "Publicar como Projeto".
// Reaproveita os mesmos helpers de população de select usados na aba
// Projeto e na aba Interações — uma única fonte de dados (state.etapas).
function _atualizarCamposProjetoOS(etapaAtualId, percentualAtual) {
    const marcado = document.getElementById('os-projeto-publico').checked;
    const bloco = document.getElementById('os-projeto-campos-extra');
    if (marcado) {
        bloco.classList.add('ativo');
        _popularEtapaSelect(document.getElementById('os-projeto-etapa'), etapaAtualId);
        _popularPercentualSelect(document.getElementById('os-projeto-percentual'), percentualAtual);
    } else {
        bloco.classList.remove('ativo');
    }
}

async function salvarOS() {
    const id     = document.getElementById('os-id').value;
    const titulo = document.getElementById('os-titulo').value.trim();
    if (!titulo) { toast('Título é obrigatório', 'aviso'); return; }

    // Atendente: pegar o texto correto do select (ignorar "Selecione")
    const atendenteSelect = document.getElementById('os-atendente');
    const atendenteId     = atendenteSelect.value || null;
    const atendenteNome   = atendenteId ? (atendenteSelect.selectedOptions[0]?.text || '') : '';

    const dados = {
        id:               id || undefined,
        titulo,
        prioridade:       document.getElementById('os-prioridade').value,
        assunto_id:       document.getElementById('os-assunto').value || null,
        departamento:     document.getElementById('os-departamento').value || '',
        morador_id:       document.getElementById('os-morador-id').value || null,
        morador_nome:     document.getElementById('os-morador-nome').value || '',
        morador_unidade:  document.getElementById('os-morador-unidade').value || '',
        atendente_id:     atendenteId,
        atendente_nome:   atendenteNome,
        data_previsao:    document.getElementById('os-data-previsao').value || null,
        descricao:        document.getElementById('os-descricao').innerHTML,
        os_pai_id:        document.getElementById('os-pai-id').value || null,
        recursos_humanos: state.rhSelecionados,
        projeto_publico:  document.getElementById('os-projeto-publico').checked ? 1 : 0,
    };

    // Etapa/Conclusão só fazem sentido (e só existem visíveis no formulário)
    // quando "Publicar como Projeto" está marcado — e Etapa é obrigatória
    // nesse caso (nunca digitação livre, sempre vinda da lista configurada).
    if (document.getElementById('os-projeto-publico').checked) {
        const etapaSelecionada = document.getElementById('os-projeto-etapa').value;
        if (!etapaSelecionada) { toast('Selecione a Etapa do projeto', 'aviso'); return; }
        dados.projeto_etapa_id  = etapaSelecionada;
        dados.projeto_percentual = document.getElementById('os-projeto-percentual').value;
    }

    log('Salvando O.S', dados);

    const acao = id ? 'editar' : 'criar';
    const btn  = document.getElementById('btnSalvarOS');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    const res = await _post(acao, dados);
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Salvar O.S';

    if (res.sucesso) {
        const previsaoDefinida = dados.data_previsao && !id && dados.atendente_id;
        const msg = previsaoDefinida
            ? (res.mensagem || 'O.S criada!') + ' Atendente notificado sobre o prazo.'
            : res.mensagem || 'O.S salva com sucesso!';
        toast(msg, 'sucesso');
        fecharModalOS();
        carregarChamados(state.paginaAtual);
        if (state.abaAtiva === 'dashboard') carregarDashboard();
    } else {
        toast(res.mensagem || 'Erro ao salvar O.S', 'erro');
    }
}

async function excluirOS(id, numero) {
    if (!confirm(`Confirma a exclusão da O.S ${numero}?`)) return;
    const res = await _get('excluir', { id });
    if (res.sucesso) {
        toast('O.S excluída com sucesso', 'sucesso');
        carregarChamados(state.paginaAtual);
    } else {
        toast(res.mensagem || 'Erro ao excluir', 'erro');
    }
}

function fecharModalOS() {
    document.getElementById('modal-os').style.display = 'none';
}

// ─── MODAL: DETALHE DA OS ─────────────────────────────────────────────────
async function abrirDetalhe(id) {
    const res = await _get('buscar', { id });
    if (!res.sucesso) { toast('Erro ao carregar OS', 'erro'); return; }
    const os = res.dados;
    state.osAtual = os;

    // Cabeçalho
    document.getElementById('detalhe-titulo').innerHTML = `<i class="fas fa-wrench"></i> ${os.numero} — ${os.titulo}`;
    document.getElementById('detalhe-badges').innerHTML = badgeStatus(os.status) + ' ' + badgePrioridade(os.prioridade);

    // Informações
    document.getElementById('d-numero').textContent     = os.numero;
    document.getElementById('d-status').innerHTML       = badgeStatus(os.status);
    document.getElementById('d-prioridade').innerHTML   = badgePrioridade(os.prioridade);
    document.getElementById('d-assunto').textContent    = os.assunto_nome || '—';
    document.getElementById('d-departamento').textContent = os.departamento || '—';
    document.getElementById('d-morador').textContent    = os.morador_nome || '—';
    document.getElementById('d-unidade').textContent    = os.morador_unidade || '—';
    document.getElementById('d-atendente').textContent  = os.atendente_nome || '—';
    document.getElementById('d-abertura').textContent   = formatarData(os.data_abertura);
    const elPrevisao = document.getElementById('d-previsao');
    elPrevisao.textContent = formatarDataSimples(os.data_previsao);
    elPrevisao.classList.remove('os-prazo-ok', 'os-prazo-proximo', 'os-prazo-vencido');
    if (os.data_previsao && os.status !== 'finalizado' && os.status !== 'cancelado') {
        const diasRestantes = Math.ceil((new Date(os.data_previsao) - new Date()) / 86400000);
        if (diasRestantes < 0)        elPrevisao.classList.add('os-prazo-vencido');
        else if (diasRestantes <= 3)  elPrevisao.classList.add('os-prazo-proximo');
        else                          elPrevisao.classList.add('os-prazo-ok');
    }
    document.getElementById('d-horas-est').textContent  = os.horas_estimadas ? os.horas_estimadas + 'h' : '—';
    document.getElementById('d-horas-tot').textContent  = os.horas_totais ? os.horas_totais + 'h' : '—';
    document.getElementById('d-descricao').innerHTML    = os.descricao || '—';

    // Mostrar/ocultar formulários conforme status
    const finalizado = os.status === 'finalizado' || os.status === 'cancelado';
    document.getElementById('os-nova-interacao-form').style.display = finalizado ? 'none' : 'block';
    document.getElementById('os-finalizar-form').style.display = 'none';
    document.getElementById('btnIniciarFinalizacao').style.display = finalizado ? 'none' : 'inline-flex';
    // Ocultar form de adicionar material em OS encerrada
    const matBusca = document.querySelector('#dtab-materiais .os-mat-busca');
    if (matBusca) matBusca.style.display = finalizado ? 'none' : '';

    // Limpar editor e anexos ao abrir
    const intEditor = document.getElementById('int-mensagem-editor');
    if (intEditor) intEditor.innerHTML = '';
    state.intAnexos = [];
    renderizarAnexos();

    // Resetar campos de Andamento (Etapa/%/Fotos/Publicar) da interação
    document.getElementById('int-tipo').value = 'comentario';
    document.getElementById('int-fotos').value = '';
    document.getElementById('intFotosCount').textContent = '';
    document.getElementById('int-definir-capa').checked = false;
    _atualizarVisibilidadeAndamentoExtra();

    // Resetar sub-abas
    document.querySelectorAll('.os-detalhe-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.os-detalhe-content').forEach(c => c.classList.remove('active'));
    document.querySelector('.os-detalhe-tab[data-dtab="info"]').classList.add('active');
    document.getElementById('dtab-info').classList.add('active');

    // A aba "Projeto" só existe para O.S marcadas como Projeto Público —
    // para uma O.S comum ela fica completamente oculta (nunca poluindo a
    // interface nem oferecendo campos que não se aplicam).
    const ehProjetoAtual = Number(os.projeto_publico) === 1;
    const tabProjeto = document.querySelector('.os-detalhe-tab[data-dtab="projeto"]');
    if (tabProjeto) tabProjeto.style.display = ehProjetoAtual ? '' : 'none';

    // Carregar interações
    carregarInteracoes(id);

    // Carregar materiais
    carregarMateriais(id);

    // Carregar equipe
    renderizarEquipe(os.recursos_humanos || []);

    document.getElementById('modal-detalhe').style.display = 'flex';
}

// Sub-abas do detalhe
function initDetalheAbas() {
    document.querySelectorAll('.os-detalhe-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const dtab = btn.dataset.dtab;
            document.querySelectorAll('.os-detalhe-tab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.os-detalhe-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('dtab-' + dtab).classList.add('active');
            if (dtab === 'interacoes' && state.osAtual) carregarInteracoes(state.osAtual.id);
            if (dtab === 'materiais'  && state.osAtual) carregarMateriais(state.osAtual.id);
            if (dtab === 'projeto'    && state.osAtual) carregarAbaProjeto(state.osAtual);
        });
    });
}

// ─── INTERAÇÕES ───────────────────────────────────────────────────────────
async function carregarInteracoes(osId) {
    const res = await _get('listar_interacoes', { os_id: osId });
    const el = document.getElementById('os-timeline');
    if (!res.sucesso) { el.innerHTML = '<div class="os-loading-text">Erro ao carregar interações</div>'; return; }
    const lista = res.dados || [];
    if (!lista.length) {
        el.innerHTML = '<div class="os-loading-text">Nenhuma interação ainda</div>';
        return;
    }
    el.innerHTML = lista.map(int => {
        let anexosHtml = '';
        if (int.anexos) {
            try {
                const anexos = typeof int.anexos === 'string' ? JSON.parse(int.anexos) : int.anexos;
                if (Array.isArray(anexos) && anexos.length) {
                    anexosHtml = `<div class="os-timeline-anexos">${
                        anexos.map(a => `
                            <a class="os-anexo-chip os-anexo-chip-dl" href="${a.base64}" download="${a.nome}" title="Baixar ${a.nome}">
                                <i class="${_iconAnexo(a.tipo)}"></i>
                                <span class="os-anexo-chip-nome">${a.nome}</span>
                                <span class="os-anexo-chip-tam">${_formatTamanho(a.tamanho)}</span>
                            </a>
                        `).join('')
                    }</div>`;
                }
            } catch(_) {}
        }
        let fotosHtml = '';
        if (Array.isArray(int.fotos) && int.fotos.length) {
            // As imagens nunca são servidas direto da pasta de uploads — sempre
            // passam por api_imagem_projeto.php, que valida o acesso.
            fotosHtml = `<div class="os-timeline-fotos">${
                int.fotos.map(f => {
                    const url = `${window.location.origin}/api/api_imagem_projeto.php?tipo=foto&id=${f.id}`;
                    return `<img src="${url}" alt="${f.arquivo_nome_original || 'Foto da obra'}" loading="lazy" title="${f.arquivo_nome_original || ''}" onclick="window.open('${url}','_blank')">`;
                }).join('')
            }</div>`;
        }
        const projetoInfo = (int.tipo === 'andamento' && (int.etapa_nome || int.percentual !== null))
            ? `<div class="os-timeline-meta" style="margin-top:4px">
                 ${int.etapa_nome ? `<span class="os-badge os-badge-andamento" style="font-size:.7rem"><i class="fas fa-list-ol"></i> ${int.etapa_nome}</span>` : ''}
                 ${int.percentual !== null && int.percentual !== undefined ? `<span class="os-badge os-badge-aberto" style="font-size:.7rem">${int.percentual}%</span>` : ''}
                 ${Number(int.publica) === 1 ? '<span class="os-badge os-badge-finalizado" style="font-size:.7rem"><i class="fas fa-globe"></i> Público</span>' : ''}
               </div>`
            : '';
        return `
        <div class="os-timeline-item">
            <div class="os-timeline-icon ${int.tipo}">
                <i class="fas ${iconeTipo(int.tipo)}"></i>
            </div>
            <div class="os-timeline-body">
                <div class="os-timeline-meta">
                    <span class="os-timeline-autor">${int.usuario_nome || 'Sistema'}</span>
                    ${badgeTipoInteracao(int.tipo)}
                    <span class="os-timeline-data">${formatarData(int.criado_em)}</span>
                </div>
                <div class="os-timeline-mensagem">${int.mensagem}</div>
                ${projetoInfo}
                ${anexosHtml}
                ${fotosHtml}
            </div>
        </div>`;
    }).join('');
    // Scroll para o final
    el.scrollTop = el.scrollHeight;
}

function badgeTipoInteracao(tipo) {
    const map = {
        comentario:   '<span class="os-badge os-badge-andamento" style="font-size:.7rem">Comentário</span>',
        andamento:    '<span class="os-badge os-badge-aberto" style="font-size:.7rem">Andamento</span>',
        solucao:      '<span class="os-badge os-badge-finalizado" style="font-size:.7rem">Solução</span>',
        nota_interna: '<span class="os-badge os-badge-cancelado" style="font-size:.7rem">Nota Interna</span>',
    };
    return map[tipo] || '';
}

async function adicionarInteracao() {
    if (!state.osAtual) return;
    const tipo    = document.getElementById('int-tipo').value;
    const editor  = document.getElementById('int-mensagem-editor');
    const mensagem = editor ? editor.innerHTML.trim() : '';
    if (!mensagem || mensagem === '<br>') { toast('Mensagem é obrigatória', 'aviso'); return; }

    const ehProjeto  = !!(state.osAtual.projeto_publico && Number(state.osAtual.projeto_publico) === 1);
    const ehAndamento = tipo === 'andamento' && ehProjeto;
    const etapaId    = ehAndamento ? document.getElementById('int-etapa').value : '';
    const percentual = ehAndamento ? document.getElementById('int-percentual').value : '';
    const publica     = ehAndamento && document.getElementById('int-publica').checked;
    const fotosInput  = document.getElementById('int-fotos');

    if (ehAndamento && !etapaId) { toast('Selecione a Etapa do projeto', 'aviso'); return; }

    const btn = document.getElementById('btnAdicionarInteracao');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

    let res;
    const temFotos = ehAndamento && fotosInput && fotosInput.files.length > 0;
    if (temFotos) {
        const fd = new FormData();
        fd.append('acao', 'adicionar_interacao');
        fd.append('os_id', state.osAtual.id);
        fd.append('tipo', tipo);
        fd.append('mensagem', mensagem);
        if (etapaId) fd.append('etapa_id', etapaId);
        if (percentual !== '') fd.append('percentual', percentual);
        fd.append('publica', publica ? '1' : '0');
        fd.append('definir_capa', document.getElementById('int-definir-capa').checked ? '1' : '0');
        Array.from(fotosInput.files).forEach(f => fd.append('fotos[]', f));
        res = await _postMultipart(fd);
    } else {
        const payload = { os_id: state.osAtual.id, tipo, mensagem };
        if (state.intAnexos.length) payload.anexos = state.intAnexos;
        if (ehAndamento) {
            if (etapaId) payload.etapa_id = etapaId;
            if (percentual !== '') payload.percentual = percentual;
            payload.publica = publica;
        }
        res = await _post('adicionar_interacao', payload);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Interação';

    if (res.sucesso) {
        toast('Interação adicionada', 'sucesso');
        if (editor) editor.innerHTML = '';
        state.intAnexos = [];
        renderizarAnexos();
        if (ehAndamento) {
            document.getElementById('int-etapa').value = '';
            document.getElementById('int-percentual').value = '';
            if (fotosInput) fotosInput.value = '';
            const cnt = document.getElementById('intFotosCount');
            if (cnt) cnt.textContent = '';
            const definiuCapa = document.getElementById('int-definir-capa').checked;
            document.getElementById('int-definir-capa').checked = false;
            // Reflete localmente o progresso já recalculado pelo backend, sem esperar reabrir o modal
            if (percentual !== '') state.osAtual.projeto_percentual = parseInt(percentual, 10);
            if (etapaId)            state.osAtual.projeto_etapa_id  = parseInt(etapaId, 10);
            if (definiuCapa) {
                // Força o <img> a recarregar com o novo arquivo (mesma URL, mas com cache-busting)
                const capaImg = document.getElementById('projeto-capa-preview');
                if (capaImg) {
                    capaImg.src = `${window.location.origin}/api/api_imagem_projeto.php?tipo=capa&os_id=${state.osAtual.id}&t=${Date.now()}`;
                    capaImg.style.display = 'block';
                }
            }
        }
        if (state.osAtual.status === 'aberto') {
            state.osAtual.status = 'andamento';
            document.getElementById('detalhe-badges').innerHTML = badgeStatus('andamento') + ' ' + badgePrioridade(state.osAtual.prioridade);
        }
        carregarInteracoes(state.osAtual.id);
        carregarChamados(state.paginaAtual);
        if (ehProjeto && document.getElementById('dtab-projeto').classList.contains('active')) {
            carregarAbaProjeto(state.osAtual);
        }
    } else {
        toast(res.mensagem || 'Erro ao adicionar interação', 'erro');
    }
}

// ─── FINALIZAÇÃO ──────────────────────────────────────────────────────────
function iniciarFinalizacao() {
    document.getElementById('os-nova-interacao-form').style.display = 'none';
    document.getElementById('os-finalizar-form').style.display = 'block';
    document.getElementById('fin-horas').focus();
}

function cancelarFinalizacao() {
    document.getElementById('os-finalizar-form').style.display = 'none';
    document.getElementById('os-nova-interacao-form').style.display = 'block';
}

async function confirmarFinalizacao() {
    if (!state.osAtual) return;
    const horasRaw = document.getElementById('fin-horas').value;
    const horas    = horasRaw !== '' ? parseFloat(horasRaw) : null;
    const observacao      = document.getElementById('fin-observacao').value.trim();
    const horasEstimadas  = document.getElementById('fin-horas-estimadas')?.value || null;

    const btn = document.getElementById('btnConfirmarFinalizacao');
    btn.disabled = true;
    const res = await _post('finalizar', {
        os_id: state.osAtual.id,
        horas_totais: horas,
        horas_estimadas: horasEstimadas,
        observacao_finalizacao: observacao
    });
    btn.disabled = false;

    if (res.sucesso) {
        toast('O.S finalizada com sucesso!', 'sucesso');
        state.osAtual.status = 'finalizado';
        document.getElementById('detalhe-badges').innerHTML = badgeStatus('finalizado') + ' ' + badgePrioridade(state.osAtual.prioridade);
        document.getElementById('os-finalizar-form').style.display = 'none';
        document.getElementById('os-nova-interacao-form').style.display = 'none';
        if (horas !== null) document.getElementById('d-horas-tot').textContent = horas + 'h';
        if (horasEstimadas) document.getElementById('d-horas-est').textContent = horasEstimadas + 'h';
        carregarInteracoes(state.osAtual.id);
        carregarChamados(state.paginaAtual);
        if (state.abaAtiva === 'dashboard') carregarDashboard();
    } else {
        toast(res.mensagem || 'Erro ao finalizar O.S', 'erro');
    }
}

// ─── MATERIAIS / ESTOQUE ──────────────────────────────────────────────────
async function carregarMateriais(osId) {
    const el = document.getElementById('lista-materiais-os');
    el.innerHTML = '<div class="os-loading-text"><i class="fas fa-spinner fa-spin"></i> Carregando materiais...</div>';
    const res = await _get('listar_materiais', { os_id: osId });
    if (!res.sucesso) {
        el.innerHTML = '<div class="os-loading-text os-loading-erro"><i class="fas fa-exclamation-circle"></i> Erro ao carregar materiais</div>';
        return;
    }
    const lista  = res.dados || [];
    const osEncerrada = state.osAtual?.status === 'finalizado' || state.osAtual?.status === 'cancelado';
    if (!lista.length) {
        const msg = osEncerrada
            ? 'Nenhum material foi registrado nesta O.S'
            : 'Nenhum material adicionado ainda';
        el.innerHTML = `<div class="os-loading-text"><i class="fas fa-box-open"></i> ${msg}</div>`;
        return;
    }
    let total = 0;
    const linhas = lista.map(m => {
        const subtotal = parseFloat(m.quantidade) * parseFloat(m.preco_unitario);
        total += subtotal;
        const acaoBotao = (!m.estoque_baixado && !osEncerrada)
            ? `<button class="os-btn-acao excluir" onclick="osRemoverMaterial(${m.id})" title="Remover"><i class="fas fa-trash"></i></button>`
            : '';
        return `<tr>
            <td>${m.produto_nome}</td>
            <td style="text-align:center">${parseFloat(m.quantidade)}</td>
            <td>R$ ${parseFloat(m.preco_unitario).toFixed(2)}</td>
            <td><strong>R$ ${subtotal.toFixed(2)}</strong></td>
            <td style="text-align:center">${m.estoque_baixado ? '<span class="os-badge os-badge-finalizado">Baixado</span>' : '<span class="os-badge os-badge-aberto">Pendente</span>'}</td>
            <td>${acaoBotao}</td>
        </tr>`;
    }).join('');
    el.innerHTML = `
        <table class="os-mat-table">
            <thead><tr>
                <th>Produto</th><th style="text-align:center">Qtd</th><th>Preço Unit.</th><th>Total</th><th style="text-align:center">Estoque</th><th></th>
            </tr></thead>
            <tbody>${linhas}</tbody>
        </table>
        <div class="os-mat-total">
            <i class="fas fa-receipt"></i> Total de materiais: <strong>R$ ${total.toFixed(2)}</strong>
            ${osEncerrada ? ' <span class="os-mat-total-badge">OS Finalizada</span>' : ''}
        </div>`;
}

window.osRemoverMaterial = async (id) => {
    if (!confirm('Remover este material?')) return;
    const res = await _get('remover_material', { id });
    if (res.sucesso) {
        toast('Material removido', 'sucesso');
        if (state.osAtual) carregarMateriais(state.osAtual.id);
    } else {
        toast(res.mensagem || 'Erro ao remover', 'erro');
    }
};

async function adicionarMaterial() {
    if (!state.osAtual) return;
    const prodId   = document.getElementById('mat-produto-id').value;
    const prodNome = document.getElementById('mat-produto-nome').value;
    const preco    = parseFloat(document.getElementById('mat-preco-unitario').value) || 0;
    const qtd      = parseFloat(document.getElementById('mat-quantidade').value) || 1;
    const estoqueDisp = parseFloat(document.getElementById('mat-estoque-disponivel').value) || 0;

    if (!prodId) { toast('Selecione um produto', 'aviso'); return; }
    if (qtd <= 0) { toast('Quantidade inválida', 'aviso'); return; }
    if (estoqueDisp <= 0) { toast(`Estoque do produto "${prodNome}" está zerado`, 'erro'); return; }
    if (qtd > estoqueDisp) { toast(`Estoque insuficiente para "${prodNome}" — disponível: ${estoqueDisp}`, 'erro'); return; }

    const res = await _post('adicionar_material', {
        os_id: state.osAtual.id,
        produto_id: prodId,
        produto_nome: prodNome,
        quantidade: qtd,
        preco_unitario: preco
    });
    if (res.sucesso) {
        toast('Material adicionado', 'sucesso');
        document.getElementById('mat-produto-busca').value = '';
        document.getElementById('mat-produto-id').value = '';
        document.getElementById('mat-produto-nome').value = '';
        document.getElementById('mat-preco-unitario').value = '';
        document.getElementById('mat-estoque-disponivel').value = '';
        document.getElementById('mat-quantidade').value = '1';
        carregarMateriais(state.osAtual.id);
    } else {
        toast(res.mensagem || 'Erro ao adicionar material', 'erro');
    }
}

// ─── EQUIPE (RH) ──────────────────────────────────────────────────────────
function renderizarEquipe(lista) {
    const el = document.getElementById('lista-rh-os');
    if (!lista.length) {
        el.innerHTML = '<div class="os-loading-text">Nenhum colaborador vinculado</div>';
        return;
    }
    el.innerHTML = lista.map(r => `
        <div class="os-rh-equipe-item">
            <div class="os-rh-avatar">${(r.colaborador_nome || r.nome || '?')[0].toUpperCase()}</div>
            <div>
                <div class="os-rh-equipe-nome">${r.colaborador_nome || r.nome}</div>
                <div class="os-rh-equipe-cargo">${r.cargo || ''} ${r.departamento ? '— ' + r.departamento : ''}</div>
            </div>
        </div>
    `).join('');
}

// ─── AUTOCOMPLETE: MORADORES ──────────────────────────────────────────────
function initAutocompleteMorador() {
    const input = document.getElementById('os-morador-busca');
    const lista = document.getElementById('os-morador-lista');
    let timer;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { lista.classList.remove('visible'); return; }
        timer = setTimeout(async () => {
            const res = await fetch(`${API_MORADORES}?nome=${encodeURIComponent(q)}&por_pagina=10`, { credentials: 'include' });
            const json = await res.json();
            const moradores = json.dados?.itens || json.dados?.moradores || json.dados || [];
            if (!moradores.length) { lista.classList.remove('visible'); return; }
            lista.innerHTML = moradores.map(m => `
                <div class="os-autocomplete-item" data-id="${m.id}" data-nome="${m.nome}" data-unidade="${m.unidade || ''}">
                    ${m.nome}
                    <div class="os-ac-sub">Unidade: ${m.unidade || '—'}</div>
                </div>
            `).join('');
            lista.classList.add('visible');
        }, 300);
    });

    lista.addEventListener('click', e => {
        const item = e.target.closest('.os-autocomplete-item');
        if (!item) return;
        document.getElementById('os-morador-id').value = item.dataset.id;
        document.getElementById('os-morador-nome').value = item.dataset.nome;
        document.getElementById('os-morador-unidade').value = item.dataset.unidade;
        input.value = item.dataset.nome + (item.dataset.unidade ? ' — Unid. ' + item.dataset.unidade : '');
        const tag = document.getElementById('os-morador-tag');
        tag.innerHTML = `<i class="fas fa-user"></i> ${item.dataset.nome} — Unid. ${item.dataset.unidade || '?'} <button onclick="limparMorador()">×</button>`;
        tag.style.display = 'inline-flex';
        lista.classList.remove('visible');
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('#os-morador-busca') && !e.target.closest('#os-morador-lista')) {
            lista.classList.remove('visible');
        }
    });
}

window.limparMorador = () => {
    document.getElementById('os-morador-id').value = '';
    document.getElementById('os-morador-nome').value = '';
    document.getElementById('os-morador-unidade').value = '';
    document.getElementById('os-morador-busca').value = '';
    document.getElementById('os-morador-tag').style.display = 'none';
};

// ─── AUTOCOMPLETE: RH COLABORADORES ──────────────────────────────────────
function initAutocompleteRH() {
    const input = document.getElementById('os-rh-busca');
    const lista = document.getElementById('os-rh-lista');
    let timer;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { lista.classList.remove('visible'); return; }
        timer = setTimeout(async () => {
            const res = await fetch(`${API_RH}?acao=listar&busca=${encodeURIComponent(q)}&ativo=1`, { credentials: 'include' });
            const json = await res.json();
            const colaboradores = json.dados || [];
            if (!colaboradores.length) { lista.classList.remove('visible'); return; }
            lista.innerHTML = colaboradores.map(c => `
                <div class="os-autocomplete-item" data-id="${c.id}" data-nome="${c.nome}" data-cargo="${c.cargo || ''}" data-dep="${c.departamento || ''}">
                    ${c.nome}
                    <div class="os-ac-sub">${c.cargo || ''} ${c.departamento ? '— ' + c.departamento : ''}</div>
                </div>
            `).join('');
            lista.classList.add('visible');
        }, 300);
    });

    lista.addEventListener('click', e => {
        const item = e.target.closest('.os-autocomplete-item');
        if (!item) return;
        const id = parseInt(item.dataset.id);
        if (state.rhSelecionados.find(r => r.id === id)) {
            toast('Colaborador já adicionado', 'aviso');
        } else {
            state.rhSelecionados.push({
                id, nome: item.dataset.nome, cargo: item.dataset.cargo, departamento: item.dataset.dep
            });
            renderizarRHTags();
        }
        input.value = '';
        lista.classList.remove('visible');
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('#os-rh-busca') && !e.target.closest('#os-rh-lista')) {
            lista.classList.remove('visible');
        }
    });
}

function renderizarRHTags() {
    const el = document.getElementById('os-rh-tags');
    el.innerHTML = state.rhSelecionados.map((r, i) => `
        <div class="os-rh-tag">
            <i class="fas fa-user"></i> ${r.nome}
            <button onclick="osRemoverRH(${i})">×</button>
        </div>
    `).join('');
}

window.osRemoverRH = (i) => {
    state.rhSelecionados.splice(i, 1);
    renderizarRHTags();
};

// ─── AUTOCOMPLETE: PRODUTOS (ESTOQUE) ─────────────────────────────────────
function initAutocompleteProdutos() {
    const input = document.getElementById('mat-produto-busca');
    const lista = document.getElementById('mat-produto-lista');
    let timer;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { lista.classList.remove('visible'); return; }
        timer = setTimeout(async () => {
            const res = await fetch(`${API_ESTOQUE}?action=produtos&busca=${encodeURIComponent(q)}`, { credentials: 'include' });
            const json = await res.json();
            const produtos = json.dados || [];
            if (!produtos.length) { lista.classList.remove('visible'); return; }
            lista.innerHTML = produtos.map(p => {
                const estoque    = parseFloat(p.quantidade_estoque) || 0;
                const semEstoque = estoque <= 0;
                return `
                <div class="os-autocomplete-item${semEstoque ? ' os-ac-sem-estoque' : ''}" data-id="${p.id}" data-nome="${p.nome}" data-preco="${p.preco_unitario || 0}" data-estoque="${estoque}">
                    ${p.nome}${semEstoque ? '<span class="os-ac-badge-zerado">Sem estoque</span>' : ''}
                    <div class="os-ac-sub">Estoque: ${p.quantidade_estoque} | R$ ${parseFloat(p.preco_unitario || 0).toFixed(2)}</div>
                </div>`;
            }).join('');
            lista.classList.add('visible');
        }, 300);
    });

    lista.addEventListener('click', e => {
        const item = e.target.closest('.os-autocomplete-item');
        if (!item) return;
        const estoque = parseFloat(item.dataset.estoque) || 0;
        if (estoque <= 0) {
            toast(`Estoque do produto "${item.dataset.nome}" está zerado. Selecione outro produto.`, 'erro');
            lista.classList.remove('visible');
            return;
        }
        document.getElementById('mat-produto-id').value = item.dataset.id;
        document.getElementById('mat-produto-nome').value = item.dataset.nome;
        document.getElementById('mat-preco-unitario').value = item.dataset.preco;
        document.getElementById('mat-estoque-disponivel').value = estoque;
        input.value = item.dataset.nome;
        lista.classList.remove('visible');
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('#mat-produto-busca') && !e.target.closest('#mat-produto-lista')) {
            lista.classList.remove('visible');
        }
    });
}

// ─── HELPERS DE ANEXO ─────────────────────────────────────────────────────
function _iconAnexo(tipo) {
    if (!tipo) return 'fas fa-file';
    if (tipo.startsWith('image/'))                           return 'fas fa-file-image';
    if (tipo === 'application/pdf')                          return 'fas fa-file-pdf';
    if (tipo.includes('word') || tipo.includes('document'))  return 'fas fa-file-word';
    if (tipo.includes('excel') || tipo.includes('sheet'))    return 'fas fa-file-excel';
    if (tipo.includes('zip') || tipo.includes('compressed')) return 'fas fa-file-archive';
    return 'fas fa-file-alt';
}

function _formatTamanho(bytes) {
    if (bytes < 1024)        return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function renderizarAnexos() {
    const el = document.getElementById('int-anexos-lista');
    const badge = document.getElementById('intAnexoCount');
    if (!el) return;
    if (!state.intAnexos.length) {
        el.innerHTML = '';
        if (badge) badge.textContent = '';
        return;
    }
    if (badge) badge.textContent = state.intAnexos.length + ' anexo' + (state.intAnexos.length > 1 ? 's' : '');
    el.innerHTML = state.intAnexos.map((a, i) => `
        <div class="os-anexo-chip">
            <i class="${_iconAnexo(a.tipo)}"></i>
            <span class="os-anexo-chip-nome">${a.nome}</span>
            <span class="os-anexo-chip-tam">${_formatTamanho(a.tamanho)}</span>
            <button type="button" class="os-anexo-chip-rm" onclick="osRemoverAnexo(${i})" title="Remover"><i class="fas fa-times"></i></button>
        </div>
    `).join('');
}

window.osRemoverAnexo = (i) => {
    state.intAnexos.splice(i, 1);
    renderizarAnexos();
};

// ─── EDITOR RICH TEXT ─────────────────────────────────────────────────────
function initEditor() {
    document.querySelectorAll('.os-editor-btn[data-cmd]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.execCommand(btn.dataset.cmd, false, null);
            document.getElementById('os-descricao').focus();
        });
    });

    document.getElementById('btnInserirImagem').addEventListener('click', () => {
        document.getElementById('inputImagem').click();
    });

    document.getElementById('inputImagem').addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = ev => {
            document.execCommand('insertImage', false, ev.target.result);
        };
        reader.readAsDataURL(file);
        e.target.value = '';
    });
}

// ─── EDITOR INTERAÇÃO (RICH TEXT + ANEXOS) ────────────────────────────────
function initInteracaoEditor() {
    document.querySelectorAll('.os-editor-btn[data-int-cmd]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.execCommand(btn.dataset.intCmd, false, null);
            document.getElementById('int-mensagem-editor').focus();
        });
    });

    document.getElementById('btnIntImagem').addEventListener('click', () => {
        document.getElementById('intInputImagem').click();
    });

    document.getElementById('intInputImagem').addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = ev => {
            document.execCommand('insertImage', false, ev.target.result);
        };
        reader.readAsDataURL(file);
        e.target.value = '';
    });

    document.getElementById('btnIntAnexar').addEventListener('click', () => {
        document.getElementById('intInputAnexo').click();
    });

    document.getElementById('intInputAnexo').addEventListener('change', e => {
        const MAX = 10 * 1024 * 1024; // 10 MB por arquivo
        Array.from(e.target.files).forEach(file => {
            if (file.size > MAX) {
                toast(`"${file.name}" excede 10 MB e não foi adicionado`, 'aviso');
                return;
            }
            if (state.intAnexos.length >= 10) {
                toast('Limite de 10 anexos por interação', 'aviso');
                return;
            }
            const reader = new FileReader();
            reader.onload = ev => {
                state.intAnexos.push({ nome: file.name, tipo: file.type, tamanho: file.size, base64: ev.target.result });
                renderizarAnexos();
            };
            reader.readAsDataURL(file);
        });
        e.target.value = '';
    });
}

// ─── CARREGAR SELECTS (ASSUNTOS, DEPARTAMENTOS, USUÁRIOS) ─────────────────
async function carregarSelects() {
    // Assuntos
    const resA = await _get('listar_assuntos');
    state.assuntos = resA.sucesso ? (resA.dados || []) : [];
    preencherSelect('os-assunto', state.assuntos, 'id', 'nome', '— Selecione —');
    preencherSelect('hh-assunto', state.assuntos, 'id', 'nome', '— Nenhum —');

    // Departamentos (endpoint próprio da OS — inclui base fixa + RH + OS existentes)
    try {
        const resD = await _get('listar_departamentos');
        state.departamentos = resD.dados || [];
        const opts = state.departamentos.map(d => ({ id: d, nome: d }));
        preencherSelect('os-departamento', opts, 'id', 'nome', '— Selecione —');
        preencherSelect('filtro-departamento', opts, 'id', 'nome', 'Todos');
        preencherSelect('rel-departamento', opts, 'id', 'nome', 'Todos');
        preencherSelect('assunto-departamento', opts, 'id', 'nome', '— Todos —');
    } catch (e) { log('Erro ao carregar departamentos', e); }

    // Usuários (atendentes)
    try {
        const resU = await fetch(`${API_USUARIOS}`, { credentials: 'include' });
        const jsonU = await resU.json();
        state.usuarios = jsonU.dados || [];
        preencherSelect('os-atendente', state.usuarios, 'id', 'nome', '— Selecione —');
    } catch (e) { log('Erro ao carregar usuários', e); }
}

function preencherSelect(id, lista, valKey, labelKey, placeholder) {
    const sel = document.getElementById(id);
    if (!sel) return;
    const atual = sel.value;
    sel.innerHTML = `<option value="">${placeholder}</option>` +
        lista.map(item => `<option value="${item[valKey]}">${item[labelKey]}</option>`).join('');
    if (atual) sel.value = atual;
}

// ─── CONFIGURAÇÕES ────────────────────────────────────────────────────────
async function carregarConfiguracoes() {
    carregarAssuntos();
    carregarConfigHH();
    carregarEtapas();
}

const CFG_POR_PAGINA = 10;

function _cfgPagHTML(pagina, totalPag, ini, total, fnPrev, fnNext) {
    if (totalPag <= 1) return '';
    return `<div class="os-cfg-pag">
        <span class="os-cfg-pag-info">${ini + 1}–${Math.min(ini + CFG_POR_PAGINA, total)} de ${total}</span>
        <button class="os-cfg-pag-btn" onclick="${fnPrev}(${pagina - 1})" ${pagina <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>
        <span class="os-cfg-pag-num">${pagina} / ${totalPag}</span>
        <button class="os-cfg-pag-btn" onclick="${fnNext}(${pagina + 1})" ${pagina >= totalPag ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>
    </div>`;
}

async function carregarAssuntos(pagina = 1) {
    const el = document.getElementById('lista-assuntos');
    el.innerHTML = '<div class="os-loading-text">Carregando...</div>';
    const res = await _get('listar_assuntos', { ativo: '' });
    if (!res.sucesso) { el.innerHTML = '<div class="os-loading-text">Erro ao carregar</div>'; return; }
    state.assuntosCache = (res.dados || []).sort((a, b) =>
        (a.nome || '').localeCompare(b.nome || '', 'pt-BR'));
    _renderAssuntos(pagina);
}

function _renderAssuntos(pagina) {
    const lista = state.assuntosCache;
    const el    = document.getElementById('lista-assuntos');
    if (!lista.length) { el.innerHTML = '<div class="os-loading-text">Nenhum assunto cadastrado</div>'; return; }
    const total    = lista.length;
    const totalPag = Math.ceil(total / CFG_POR_PAGINA);
    pagina = Math.max(1, Math.min(pagina, totalPag));
    state.assuntosPagina = pagina;
    const ini   = (pagina - 1) * CFG_POR_PAGINA;
    const slice = lista.slice(ini, ini + CFG_POR_PAGINA);
    el.innerHTML = slice.map(a => `
        <div class="os-lista-item">
            <div class="os-lista-item-info">
                <div class="os-lista-item-nome">${a.nome}</div>
                <div class="os-lista-item-sub">${a.departamento || 'Todos os departamentos'} ${!a.ativo ? '— <em>Inativo</em>' : ''}</div>
            </div>
            <div class="os-lista-item-acoes">
                <button class="os-btn-acao editar" onclick="osEditarAssunto(${a.id})" title="Editar"><i class="fas fa-edit"></i></button>
                <button class="os-btn-acao excluir" onclick="osExcluirAssunto(${a.id},${JSON.stringify(a.nome)})" title="Excluir"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    `).join('') + _cfgPagHTML(pagina, totalPag, ini, total, 'osAssuntosPag', 'osAssuntosPag');
}

async function carregarConfigHH(pagina = 1) {
    const el = document.getElementById('lista-config-hh');
    el.innerHTML = '<div class="os-loading-text">Carregando...</div>';
    const res = await _get('listar_config');
    if (!res.sucesso) { el.innerHTML = '<div class="os-loading-text">Erro ao carregar</div>'; return; }
    state.configHHCache = res.dados || [];
    _renderConfigHH(pagina);
}

function _renderConfigHH(pagina) {
    const lista = state.configHHCache;
    const el    = document.getElementById('lista-config-hh');
    if (!lista.length) { el.innerHTML = '<div class="os-loading-text">Nenhuma configuração cadastrada</div>'; return; }
    const total    = lista.length;
    const totalPag = Math.ceil(total / CFG_POR_PAGINA);
    pagina = Math.max(1, Math.min(pagina, totalPag));
    state.configHHPagina = pagina;
    const ini   = (pagina - 1) * CFG_POR_PAGINA;
    const slice = lista.slice(ini, ini + CFG_POR_PAGINA);
    el.innerHTML = slice.map(c => `
        <div class="os-lista-item">
            <div class="os-lista-item-info">
                <div class="os-lista-item-nome">${c.descricao}</div>
                <div class="os-lista-item-sub">
                    ${c.assunto_nome ? 'Assunto: ' + c.assunto_nome + ' | ' : ''}
                    ${c.horas_estimadas}h estimadas
                    ${c.custo_hora > 0 ? ' | R$ ' + parseFloat(c.custo_hora).toFixed(2) + '/h' : ''}
                </div>
            </div>
            <div class="os-lista-item-acoes">
                <button class="os-btn-acao editar" onclick="osEditarConfigHH(${c.id})" title="Editar"><i class="fas fa-edit"></i></button>
                <button class="os-btn-acao excluir" onclick="osExcluirConfigHH(${c.id})" title="Excluir"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    `).join('') + _cfgPagHTML(pagina, totalPag, ini, total, 'osConfigHHPag', 'osConfigHHPag');
}

// Modal Assunto
window.osAssuntosPag  = (p) => _renderAssuntos(p);
window.osConfigHHPag  = (p) => _renderConfigHH(p);

window.osEditarAssunto = async (id) => {
    const res = await _get('listar_assuntos', { ativo: '' });
    const assunto = (res.dados || []).find(a => a.id == id);
    if (!assunto) return;
    document.getElementById('assunto-id').value = assunto.id;
    document.getElementById('assunto-nome').value = assunto.nome;
    document.getElementById('assunto-descricao').value = assunto.descricao || '';
    document.getElementById('assunto-departamento').value = assunto.departamento || '';
    document.getElementById('modal-assunto-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar Assunto';
    document.getElementById('modal-assunto').style.display = 'flex';
};

window.osExcluirAssunto = async (id, nome) => {
    if (!confirm(`Excluir assunto "${nome}"?`)) return;
    const res = await _get('excluir_assunto', { id });
    if (res.sucesso) { toast('Assunto excluído', 'sucesso'); carregarAssuntos(); carregarSelects(); }
    else toast(res.mensagem || 'Erro ao excluir', 'erro');
};

async function salvarAssunto() {
    const id   = document.getElementById('assunto-id').value;
    const nome = document.getElementById('assunto-nome').value.trim();
    if (!nome) { toast('Nome é obrigatório', 'aviso'); return; }
    const dados = {
        id: id || undefined,
        nome,
        descricao:    document.getElementById('assunto-descricao').value.trim(),
        departamento: document.getElementById('assunto-departamento').value,
    };
    const acao = id ? 'editar_assunto' : 'criar_assunto';
    const res = await _post(acao, dados);
    if (res.sucesso) {
        toast('Assunto salvo', 'sucesso');
        document.getElementById('modal-assunto').style.display = 'none';
        carregarAssuntos();
        carregarSelects();
    } else {
        toast(res.mensagem || 'Erro ao salvar', 'erro');
    }
}

// Modal Config HH
window.osEditarConfigHH = async (id) => {
    const res = await _get('listar_config');
    const config = (res.dados || []).find(c => c.id == id);
    if (!config) return;
    document.getElementById('hh-id').value = config.id;
    document.getElementById('hh-assunto').value = config.assunto_id || '';
    document.getElementById('hh-descricao').value = config.descricao;
    document.getElementById('hh-horas').value = config.horas_estimadas;
    document.getElementById('hh-custo').value = config.custo_hora;
    document.getElementById('modal-hh-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar Configuração';
    document.getElementById('modal-config-hh').style.display = 'flex';
};

window.osExcluirConfigHH = async (id) => {
    if (!confirm('Excluir esta configuração?')) return;
    const res = await _get('excluir_config', { id });
    if (res.sucesso) { toast('Configuração excluída', 'sucesso'); carregarConfigHH(); }
    else toast(res.mensagem || 'Erro ao excluir', 'erro');
};

async function salvarConfigHH() {
    const id = document.getElementById('hh-id').value;
    const descricao = document.getElementById('hh-descricao').value.trim();
    if (!descricao) { toast('Descrição é obrigatória', 'aviso'); return; }
    const dados = {
        id: id || undefined,
        assunto_id:     document.getElementById('hh-assunto').value || null,
        descricao,
        horas_estimadas: parseFloat(document.getElementById('hh-horas').value) || 1,
        custo_hora:     parseFloat(document.getElementById('hh-custo').value) || 0,
    };
    const res = await _post('salvar_config', dados);
    if (res.sucesso) {
        toast('Configuração salva', 'sucesso');
        document.getElementById('modal-config-hh').style.display = 'none';
        carregarConfigHH();
    } else {
        toast(res.mensagem || 'Erro ao salvar', 'erro');
    }
}

// ─── ETAPAS (Módulo Projetos) ──────────────────────────────────────────────
async function carregarEtapas() {
    const el = document.getElementById('lista-etapas');
    el.innerHTML = '<div class="os-loading-text">Carregando...</div>';
    const res = await _get('listar_etapas', { ativo: '' });
    if (!res.sucesso) { el.innerHTML = '<div class="os-loading-text">Erro ao carregar</div>'; return; }
    state.etapas = (res.dados || []).slice().sort((a, b) => (a.ordem - b.ordem) || (a.nome || '').localeCompare(b.nome || '', 'pt-BR'));
    _renderEtapas();
}

function _renderEtapas() {
    const lista = state.etapas || [];
    const el = document.getElementById('lista-etapas');
    if (!lista.length) { el.innerHTML = '<div class="os-loading-text">Nenhuma etapa cadastrada</div>'; return; }
    el.innerHTML = lista.map(e => `
        <div class="os-lista-item">
            <div class="os-lista-item-info">
                <div class="os-lista-item-nome">${e.ordem ? e.ordem + '. ' : ''}${e.nome}</div>
                <div class="os-lista-item-sub">${Number(e.ativo) === 1 ? 'Ativa' : '<em>Inativa</em>'}</div>
            </div>
            <div class="os-lista-item-acoes">
                <button class="os-btn-acao editar" onclick="osEditarEtapa(${e.id})" title="Editar"><i class="fas fa-edit"></i></button>
                <button class="os-btn-acao excluir" onclick="osExcluirEtapa(${e.id},${JSON.stringify(e.nome)})" title="Excluir"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    `).join('');
}

window.osEditarEtapa = (id) => {
    const etapa = (state.etapas || []).find(e => e.id == id);
    if (!etapa) return;
    document.getElementById('etapa-id').value = etapa.id;
    document.getElementById('etapa-nome').value = etapa.nome;
    document.getElementById('etapa-ordem').value = etapa.ordem || 0;
    document.getElementById('modal-etapa-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar Etapa';
    document.getElementById('modal-etapa').style.display = 'flex';
};

window.osExcluirEtapa = async (id, nome) => {
    if (!confirm(`Excluir etapa "${nome}"?`)) return;
    const res = await _get('excluir_etapa', { id });
    if (res.sucesso) { toast('Etapa excluída', 'sucesso'); carregarEtapas(); }
    else toast(res.mensagem || 'Erro ao excluir', 'erro');
};

async function salvarEtapa() {
    const id   = document.getElementById('etapa-id').value;
    const nome = document.getElementById('etapa-nome').value.trim();
    if (!nome) { toast('Nome é obrigatório', 'aviso'); return; }
    const dados = {
        id: id || undefined,
        nome,
        ordem: parseInt(document.getElementById('etapa-ordem').value, 10) || 0,
    };
    const acao = id ? 'editar_etapa' : 'criar_etapa';
    const res = await _post(acao, dados);
    if (res.sucesso) {
        toast('Etapa salva', 'sucesso');
        document.getElementById('modal-etapa').style.display = 'none';
        carregarEtapas();
    } else {
        toast(res.mensagem || 'Erro ao salvar', 'erro');
    }
}

// ─── PROJETO (aba "Projeto" do modal de detalhe) ───────────────────────────
function _popularPercentualSelect(selectEl, valorAtual) {
    if (!selectEl) return;
    selectEl.innerHTML = '<option value="">— Não alterar —</option>';
    for (let p = 0; p <= 100; p += 5) {
        const o = document.createElement('option');
        o.value = p; o.textContent = p + '%';
        if (String(p) === String(valorAtual)) o.selected = true;
        selectEl.appendChild(o);
    }
}

async function _popularEtapaSelect(selectEl, valorAtual) {
    if (!selectEl) return;
    if (!state.etapas || !state.etapas.length) {
        const res = await _get('listar_etapas', { ativo: '1' });
        state.etapas = res.sucesso ? (res.dados || []) : [];
    }
    selectEl.innerHTML = '<option value="">— Selecione a etapa —</option>' +
        state.etapas.filter(e => Number(e.ativo) === 1 || String(e.id) === String(valorAtual))
            .map(e => `<option value="${e.id}" ${String(e.id) === String(valorAtual) ? 'selected' : ''}>${e.nome}</option>`).join('');
}

function _atualizarVisibilidadeAndamentoExtra() {
    const tipo = document.getElementById('int-tipo').value;
    const extra = document.getElementById('int-andamento-extra');
    const ehProjeto = !!(state.osAtual && Number(state.osAtual.projeto_publico) === 1);
    if (tipo === 'andamento' && ehProjeto) {
        extra.classList.add('ativo');
        _popularEtapaSelect(document.getElementById('int-etapa'), state.osAtual.projeto_etapa_id);
        _popularPercentualSelect(document.getElementById('int-percentual'), state.osAtual.projeto_percentual);
    } else {
        extra.classList.remove('ativo');
    }
}

async function carregarAbaProjeto(os) {
    const pct = Math.max(0, Math.min(100, parseInt(os.projeto_percentual, 10) || 0));
    const resumo = document.getElementById('projeto-progresso-resumo');
    resumo.style.display = 'block';
    document.getElementById('projeto-pct-display').textContent = pct + '%';
    document.getElementById('projeto-pct-barra').style.width = pct + '%';

    _popularEtapaSelect(document.getElementById('projeto-etapa-atual'), os.projeto_etapa_id);
    _popularPercentualSelect(document.getElementById('projeto-percentual-atual'), os.projeto_percentual);

    // Título, departamento, responsável e datas não são editados aqui —
    // vêm sempre da própria O.S (aba Informações), nunca duplicados.

    const capaImg = document.getElementById('projeto-capa-preview');
    if (os.projeto_imagem_capa) {
        capaImg.src = `${window.location.origin}/api/api_imagem_projeto.php?tipo=capa&os_id=${os.id}`;
        capaImg.style.display = 'block';
    } else {
        capaImg.style.display = 'none';
    }

    carregarTimelinePreview(os.id);
    carregarDocumentosProjeto(os.id);
}

async function carregarTimelinePreview(osId) {
    const el = document.getElementById('projeto-timeline-preview');
    const res = await _get('listar_interacoes', { os_id: osId });
    if (!res.sucesso) { el.innerHTML = '<div class="os-loading-text">Erro ao carregar</div>'; return; }
    const etapas = (res.dados || []).filter(i => i.tipo === 'andamento' && i.etapa_nome);
    if (!etapas.length) { el.innerHTML = '<div class="os-loading-text">Nenhum andamento com etapa registrado ainda.</div>'; return; }
    el.innerHTML = etapas.map((i, idx) => {
        const isUltima = idx === etapas.length - 1;
        const statusClasse = isUltima ? 'atual' : 'concluida';
        const icone = isUltima ? 'fa-circle' : 'fa-check-circle';
        return `
        <div class="os-projeto-timeline-item">
            <i class="fas ${icone} os-projeto-timeline-check ${statusClasse}"></i>
            <div>
                <strong>${i.etapa_nome}</strong> ${i.percentual !== null && i.percentual !== undefined ? `— ${i.percentual}%` : ''}
                <div class="os-timeline-data">${formatarData(i.criado_em)} ${Number(i.publica) === 1 ? '· <i class="fas fa-globe"></i> Público' : ''}</div>
            </div>
        </div>`;
    }).join('');
}

async function salvarProjeto() {
    if (!state.osAtual) return;
    // Só o que é exclusivo do Projeto é salvo aqui — título, departamento,
    // responsável e datas continuam vindo direto da O.S., nunca duplicados.
    // Alterar Etapa/Conclusão aqui atualiza o projeto imediatamente e é
    // auditado no backend (usuário, data/hora, valores antes → depois).
    const dados = {
        os_id: state.osAtual.id,
        projeto_etapa_id:    document.getElementById('projeto-etapa-atual').value || null,
        projeto_percentual:  document.getElementById('projeto-percentual-atual').value,
    };
    const btn = document.getElementById('btnSalvarProjeto');
    btn.disabled = true;
    const res = await _post('salvar_projeto', dados);
    btn.disabled = false;
    if (res.sucesso) {
        toast('Informações do projeto salvas', 'sucesso');
        Object.assign(state.osAtual, dados);
        const pct = Math.max(0, Math.min(100, parseInt(dados.projeto_percentual, 10) || 0));
        document.getElementById('projeto-pct-display').textContent = pct + '%';
        document.getElementById('projeto-pct-barra').style.width = pct + '%';
        carregarTimelinePreview(state.osAtual.id);
    } else {
        toast(res.mensagem || 'Erro ao salvar projeto', 'erro');
    }
}

async function uploadCapaProjeto(file) {
    if (!state.osAtual || !file) return;
    const fd = new FormData();
    fd.append('acao', 'upload_imagem_capa');
    fd.append('os_id', state.osAtual.id);
    fd.append('imagem', file);
    const res = await _postMultipart(fd);
    if (res.sucesso) {
        toast('Imagem de capa atualizada', 'sucesso');
        state.osAtual.projeto_imagem_capa = res.dados.arquivo;
        const capaImg = document.getElementById('projeto-capa-preview');
        capaImg.src = `${window.location.origin}/api/api_imagem_projeto.php?tipo=capa&os_id=${state.osAtual.id}&t=${Date.now()}`;
        capaImg.style.display = 'block';
    } else {
        toast(res.mensagem || 'Erro ao enviar imagem', 'erro');
    }
}

async function carregarDocumentosProjeto(osId) {
    const el = document.getElementById('projeto-docs-lista');
    const [resVinc, resTodos] = await Promise.all([
        _get('listar_documentos_projeto', { os_id: osId }),
        fetch(`${window.location.origin}/api/api_documentos.php?acao=documentos_listar&status=ativo&pagina=1`, { credentials: 'include' }).then(r => r.json()).catch(() => ({ sucesso: false })),
    ]);
    const vinculados = new Set((resVinc.dados || []).map(d => String(d.id)));
    const todos = (resTodos && resTodos.sucesso) ? (resTodos.dados?.documentos || []) : [];
    if (!todos.length) {
        el.innerHTML = '<div class="os-loading-text">Nenhum documento disponível no GED para vincular.</div>';
        return;
    }
    el.innerHTML = todos.map(d => `
        <label>
            <input type="checkbox" value="${d.id}" ${vinculados.has(String(d.id)) ? 'checked' : ''}>
            ${d.nome}
        </label>
    `).join('') + `<div class="os-interacao-actions" style="margin-top:10px"><button class="btn-os-add-small" onclick="window._osSalvarDocsProjeto(${osId})"><i class="fas fa-link"></i> Salvar Vínculos</button></div>`;
}

window._osSalvarDocsProjeto = async (osId) => {
    const ids = Array.from(document.querySelectorAll('#projeto-docs-lista input[type=checkbox]:checked')).map(c => parseInt(c.value, 10));
    const res = await _post('vincular_documentos_projeto', { os_id: osId, documento_ids: ids });
    if (res.sucesso) toast('Documentos vinculados ao projeto', 'sucesso');
    else toast(res.mensagem || 'Erro ao vincular documentos', 'erro');
};

// ─── RELATÓRIOS ───────────────────────────────────────────────────────────

const REL_TIPOS = {
    listagem_geral:    { titulo: 'Listagem Geral de OS', descricao: 'Todas as ordens de serviço no período com detalhes completos de cada chamado.', icon: 'fa-list-ul', cor: '#2563eb',
        colunas: [{k:'numero',l:'Número'},{k:'titulo',l:'Título'},{k:'departamento',l:'Departamento'},{k:'morador_nome',l:'Morador'},{k:'morador_unidade',l:'Unidade'},{k:'prioridade',l:'Prioridade',badge:true},{k:'status',l:'Status',badge:true},{k:'atendente_nome',l:'Atendente'},{k:'abertura',l:'Abertura'},{k:'finalizacao',l:'Finalização'},{k:'dias',l:'Dias',align:'center'},{k:'horas',l:'Horas',align:'center'}] },

    unidades_abertas:  { titulo: 'Unidades com OS em Aberto', descricao: 'Ranking das unidades que possuem chamados em aberto ou em andamento. Essencial para identificar onde a atenção é mais urgente.', icon: 'fa-home', cor: '#dc2626',
        colunas: [{k:'unidade',l:'Unidade'},{k:'morador',l:'Morador'},{k:'total_aberto',l:'Total Aberto',align:'center'},{k:'urgentes',l:'Urgentes',align:'center'},{k:'altas',l:'Alta Prioridade',align:'center'},{k:'medias',l:'Média',align:'center'},{k:'baixas',l:'Baixa',align:'center'},{k:'abertura_mais_antiga',l:'OS Mais Antiga'}] },

    os_finalizadas:    { titulo: 'OS Finalizadas — Análise de SLA', descricao: 'Chamados encerrados com tempo de resolução, horas estimadas vs reais e observações de finalização.', icon: 'fa-check-circle', cor: '#16a34a',
        colunas: [{k:'numero',l:'Número'},{k:'titulo',l:'Título'},{k:'departamento',l:'Departamento'},{k:'morador_nome',l:'Morador'},{k:'morador_unidade',l:'Unidade'},{k:'prioridade',l:'Prioridade',badge:true},{k:'atendente_nome',l:'Atendente'},{k:'abertura',l:'Abertura'},{k:'finalizacao',l:'Finalização'},{k:'dias_resolucao',l:'Dias',align:'center'},{k:'horas_estimadas',l:'H. Estimadas',align:'center'},{k:'horas_reais',l:'H. Reais',align:'center'},{k:'observacao',l:'Observação'}] },

    por_atendente:     { titulo: 'Produtividade por Atendente', descricao: 'Quantidade de OS por técnico/atendente, taxa de finalização, horas registradas e tempo médio de resolução.', icon: 'fa-user-hard-hat', cor: '#7c3aed',
        colunas: [{k:'atendente',l:'Atendente'},{k:'total',l:'Total OS',align:'center'},{k:'finalizadas',l:'Finalizadas',align:'center'},{k:'em_aberto',l:'Em Aberto',align:'center'},{k:'canceladas',l:'Canceladas',align:'center'},{k:'urgentes_atendidas',l:'Urgentes',align:'center'},{k:'media_dias',l:'Média Dias',align:'center'},{k:'total_horas',l:'Total Horas',align:'center'}] },

    por_departamento:  { titulo: 'Resumo por Departamento', descricao: 'Volume de chamados, status e métricas de resolução agrupados por departamento.', icon: 'fa-building', cor: '#0891b2',
        colunas: [{k:'departamento',l:'Departamento'},{k:'total',l:'Total',align:'center'},{k:'em_aberto',l:'Em Aberto',align:'center'},{k:'finalizadas',l:'Finalizadas',align:'center'},{k:'canceladas',l:'Canceladas',align:'center'},{k:'urgentes',l:'Urgentes',align:'center'},{k:'media_dias_resolucao',l:'Média Dias',align:'center'},{k:'total_horas',l:'Total Horas',align:'center'}] },

    prazo_vencido:     { titulo: 'OS com Prazo Vencido (SLA)', descricao: 'Chamados com data de previsão ultrapassada ainda sem finalização. Indica violações de SLA que exigem ação imediata.', icon: 'fa-calendar-times', cor: '#ea580c',
        colunas: [{k:'numero',l:'Número'},{k:'titulo',l:'Título'},{k:'departamento',l:'Departamento'},{k:'morador_nome',l:'Morador'},{k:'morador_unidade',l:'Unidade'},{k:'prioridade',l:'Prioridade',badge:true},{k:'status',l:'Status',badge:true},{k:'atendente_nome',l:'Atendente'},{k:'abertura',l:'Abertura'},{k:'previsao',l:'Previsão'},{k:'dias_atraso',l:'Dias Atraso',align:'center'}] },

    tempo_resolucao:   { titulo: 'Tempo de Resolução por Departamento', descricao: 'Análise do tempo médio, mínimo e máximo para resolução de chamados por departamento. Identifica gargalos operacionais.', icon: 'fa-stopwatch', cor: '#d97706',
        colunas: [{k:'departamento',l:'Departamento'},{k:'total_finalizadas',l:'Finalizadas',align:'center'},{k:'media_dias',l:'Média Dias',align:'center'},{k:'min_dias',l:'Menor Tempo',align:'center'},{k:'max_dias',l:'Maior Tempo',align:'center'},{k:'media_horas',l:'Média Horas',align:'center'},{k:'total_horas',l:'Total Horas',align:'center'}] },

    ranking_ocorrencias:{ titulo: 'Ranking de Ocorrências por Assunto', descricao: 'Tipos de problemas mais frequentes. Ajuda a identificar padrões recorrentes e priorizar ações preventivas.', icon: 'fa-trophy', cor: '#f59e0b',
        colunas: [{k:'assunto',l:'Assunto/Categoria'},{k:'departamento',l:'Departamento'},{k:'total',l:'Total',align:'center'},{k:'em_aberto',l:'Em Aberto',align:'center'},{k:'finalizadas',l:'Finalizadas',align:'center'},{k:'alta_prioridade',l:'Alta Prior.',align:'center'},{k:'media_dias',l:'Média Dias',align:'center'}] },
};

function initRelatorios() {
    const hoje = new Date();
    const ini  = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    document.getElementById('rel-data-inicio').value = ini.toISOString().split('T')[0];
    document.getElementById('rel-data-fim').value    = hoje.toISOString().split('T')[0];

    // Tipo cards click
    document.querySelectorAll('.os-rel-tipo-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.os-rel-tipo-card').forEach(c => c.classList.remove('ativo'));
            card.classList.add('ativo');
            const tipo = card.dataset.tipo;
            document.getElementById('rel-tipo').value = tipo;
            const info = REL_TIPOS[tipo];
            if (info) {
                document.getElementById('rel-descricao-texto').textContent = info.descricao;
                document.getElementById('rel-descricao-box').style.setProperty('--rel-cor', info.cor);
            }
        });
    });

    // Selecionar o primeiro por padrão
    const first = document.querySelector('.os-rel-tipo-card');
    if (first) first.click();
}

async function gerarRelatorio() {
    const tipo   = document.getElementById('rel-tipo').value;
    const d_ini  = document.getElementById('rel-data-inicio').value;
    const d_fim  = document.getElementById('rel-data-fim').value;
    const dep    = document.getElementById('rel-departamento').value;
    const unid   = document.getElementById('rel-unidade').value.trim();

    if (!tipo) { toast('Selecione um tipo de relatório', 'aviso'); return; }

    const btn = document.getElementById('btnGerarRelatorio');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...';

    const res = await _post('relatorio', { tipo, data_ini: d_ini, data_fim: d_fim, departamento: dep, unidade: unid });

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-chart-bar"></i> Gerar';

    if (!res.sucesso) { toast(res.mensagem || 'Erro ao gerar relatório', 'erro'); return; }

    const info  = REL_TIPOS[tipo];
    const dados = res.dados.dados || [];
    state.relDados   = dados;
    state.relColunas = info.colunas;
    state.relTipo    = tipo;

    // KPIs de resumo
    const kpiEl = document.getElementById('rel-kpis');
    kpiEl.innerHTML = _relKpis(tipo, dados);

    // Título + contagem
    document.getElementById('rel-tabela-titulo').innerHTML =
        `<i class="fas ${info.icon}" style="color:${info.cor}"></i> ${info.titulo}`;
    document.getElementById('rel-tabela-contagem').textContent =
        `${dados.length} registro${dados.length !== 1 ? 's' : ''} encontrado${dados.length !== 1 ? 's' : ''}`;

    // Tabela dinâmica
    const thead = document.getElementById('thead-relatorio');
    const tbody = document.getElementById('tbody-relatorio');

    thead.innerHTML = '<tr>' + info.colunas.map(c =>
        `<th${c.align ? ` style="text-align:${c.align}"` : ''}>${c.l}</th>`
    ).join('') + '</tr>';

    if (!dados.length) {
        tbody.innerHTML = `<tr><td colspan="${info.colunas.length}" class="os-loading-text">Nenhum registro encontrado para os filtros selecionados</td></tr>`;
    } else {
        tbody.innerHTML = dados.map(row =>
            '<tr>' + info.colunas.map(c => {
                let val = row[c.k] ?? '—';
                if (c.badge && c.k === 'prioridade') val = badgePrioridade(val);
                else if (c.badge && c.k === 'status')    val = badgeStatus(val);
                const align = c.align ? ` style="text-align:${c.align}"` : '';
                return `<td${align}>${val}</td>`;
            }).join('') + '</tr>'
        ).join('');
    }

    document.getElementById('rel-resultado').style.display = 'block';
    document.getElementById('rel-resultado').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function _relKpis(tipo, dados) {
    if (!dados.length) return '';
    const total = dados.length;

    if (tipo === 'listagem_geral') {
        const fin = dados.filter(r => r.status === 'finalizado').length;
        const ab  = dados.filter(r => r.status === 'aberto' || r.status === 'andamento').length;
        const hrs = dados.reduce((s, r) => s + (parseFloat(r.horas) || 0), 0);
        return `
            <div class="os-rel-kpi"       ><div class="os-rel-kpi-valor">${total}</div><div class="os-rel-kpi-label">Total</div></div>
            <div class="os-rel-kpi green" ><div class="os-rel-kpi-valor">${fin}</div><div class="os-rel-kpi-label">Finalizadas</div></div>
            <div class="os-rel-kpi amber" ><div class="os-rel-kpi-valor">${ab}</div><div class="os-rel-kpi-label">Em Aberto</div></div>
            <div class="os-rel-kpi purple"><div class="os-rel-kpi-valor">${hrs.toFixed(1)}h</div><div class="os-rel-kpi-label">Horas Totais</div></div>`;
    }
    if (tipo === 'unidades_abertas') {
        const urg = dados.reduce((s, r) => s + (parseInt(r.urgentes) || 0), 0);
        const tot = dados.reduce((s, r) => s + (parseInt(r.total_aberto) || 0), 0);
        return `
            <div class="os-rel-kpi"     ><div class="os-rel-kpi-valor">${total}</div><div class="os-rel-kpi-label">Unidades Afetadas</div></div>
            <div class="os-rel-kpi amber"><div class="os-rel-kpi-valor">${tot}</div><div class="os-rel-kpi-label">OS em Aberto</div></div>
            <div class="os-rel-kpi red" ><div class="os-rel-kpi-valor">${urg}</div><div class="os-rel-kpi-label">Urgentes</div></div>`;
    }
    if (tipo === 'os_finalizadas') {
        const hrs = dados.reduce((s, r) => s + (parseFloat(r.horas_reais) || 0), 0);
        const avg = dados.reduce((s, r) => s + (parseInt(r.dias_resolucao) || 0), 0) / (total || 1);
        return `
            <div class="os-rel-kpi green" ><div class="os-rel-kpi-valor">${total}</div><div class="os-rel-kpi-label">Finalizadas</div></div>
            <div class="os-rel-kpi purple"><div class="os-rel-kpi-valor">${hrs.toFixed(1)}h</div><div class="os-rel-kpi-label">Horas Trabalhadas</div></div>
            <div class="os-rel-kpi amber" ><div class="os-rel-kpi-valor">${avg.toFixed(1)}d</div><div class="os-rel-kpi-label">Média de Resolução</div></div>`;
    }
    if (tipo === 'prazo_vencido') {
        const maxAtraso = Math.max(...dados.map(r => parseInt(r.dias_atraso) || 0));
        return `
            <div class="os-rel-kpi red"  ><div class="os-rel-kpi-valor">${total}</div><div class="os-rel-kpi-label">SLAs Violados</div></div>
            <div class="os-rel-kpi amber"><div class="os-rel-kpi-valor">${maxAtraso}d</div><div class="os-rel-kpi-label">Maior Atraso</div></div>`;
    }
    if (tipo === 'por_atendente') {
        const fin = dados.reduce((s, r) => s + (parseInt(r.finalizadas) || 0), 0);
        const hrs = dados.reduce((s, r) => s + (parseFloat(r.total_horas) || 0), 0);
        return `
            <div class="os-rel-kpi"      ><div class="os-rel-kpi-valor">${total}</div><div class="os-rel-kpi-label">Atendentes</div></div>
            <div class="os-rel-kpi green"><div class="os-rel-kpi-valor">${fin}</div><div class="os-rel-kpi-label">OS Finalizadas</div></div>
            <div class="os-rel-kpi purple"><div class="os-rel-kpi-valor">${hrs.toFixed(1)}h</div><div class="os-rel-kpi-label">Horas Totais</div></div>`;
    }
    // Fallback genérico
    return `<div class="os-rel-kpi"><div class="os-rel-kpi-valor">${total}</div><div class="os-rel-kpi-label">Registros</div></div>`;
}

function exportarCSV() {
    if (!state.relDados || !state.relDados.length) { toast('Gere o relatório primeiro', 'aviso'); return; }
    const cols = state.relColunas;
    const cab  = cols.map(c => `"${c.l}"`).join(',');
    const linhas = state.relDados.map(row =>
        cols.map(c => `"${String(row[c.k] ?? '').replace(/"/g,'""')}"`).join(',')
    );
    const csv  = '﻿' + [cab, ...linhas].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `os_${state.relTipo}_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

function exportarPDF() {
    if (!state.relDados || !state.relDados.length) { toast('Gere o relatório primeiro', 'aviso'); return; }
    const info  = REL_TIPOS[state.relTipo] || { titulo: 'Relatório', cor: '#ea580c', icon: 'fa-file' };
    const cols  = state.relColunas;
    const d_ini = document.getElementById('rel-data-inicio').value || '';
    const d_fim = document.getElementById('rel-data-fim').value    || '';
    const periodo = d_ini && d_fim ? `${d_ini.split('-').reverse().join('/')} a ${d_fim.split('-').reverse().join('/')}` : 'Todos os períodos';

    const thHTML = cols.map(c => `<th${c.align ? ` class="c"` : ''}>${c.l}</th>`).join('');
    const tbHTML = state.relDados.map((row, idx) =>
        `<tr class="${idx % 2 === 0 ? '' : 'alt'}">` +
        cols.map(c => {
            let v = row[c.k] ?? '—';
            // Strip HTML badges for PDF
            v = String(v).replace(/<[^>]+>/g, '');
            return `<td${c.align ? ` class="c"` : ''}>${v}</td>`;
        }).join('') + '</tr>'
    ).join('');

    const win = window.open('', '_blank');
    if (!win) { toast('Pop-up bloqueado. Permita pop-ups para exportar PDF.', 'aviso'); return; }
    win.document.write(`<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8">
<title>${info.titulo}</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#1e293b;padding:20px}
  .hdr{border-bottom:3px solid ${info.cor};padding-bottom:10px;margin-bottom:14px}
  .hdr h1{font-size:15px;color:${info.cor};margin-bottom:3px}
  .hdr .meta{font-size:9px;color:#64748b}
  table{width:100%;border-collapse:collapse;margin-top:6px}
  th{background:#f1f5f9;font-size:8.5px;font-weight:700;text-transform:uppercase;padding:5px 6px;text-align:left;border-bottom:2px solid #e2e8f0;color:#475569;letter-spacing:.3px}
  td{padding:4px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top}
  th.c,td.c{text-align:center}
  tr.alt td{background:#fafafa}
  .footer{margin-top:12px;font-size:8px;color:#94a3b8;text-align:right}
  @page{margin:15mm}
  @media print{body{padding:0}}
</style>
</head><body>
<div class="hdr">
  <h1>Ordens de Serviço — ${info.titulo}</h1>
  <div class="meta">Período: ${periodo} &nbsp;|&nbsp; ${state.relDados.length} registros &nbsp;|&nbsp; Gerado em ${new Date().toLocaleString('pt-BR')}</div>
</div>
<table><thead><tr>${thHTML}</tr></thead><tbody>${tbHTML}</tbody></table>
<div class="footer">Sistema de Gestão de Condomínio &nbsp;|&nbsp; Ordens de Serviço</div>
<script>setTimeout(()=>{window.print();},400);<\/script>
</body></html>`);
    win.document.close();
}

// ─── BUSCA DE OS PAI ──────────────────────────────────────────────────────
function initBuscaOSPai() {
    const input = document.getElementById('os-pai-busca');
    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 3) return;
        timer = setTimeout(async () => {
            const res = await _get('listar', { busca: q, por_pagina: 5 });
            const lista = res.dados?.lista || [];
            if (!lista.length) return;
            // Usar a primeira que bater exato pelo número
            const match = lista.find(o => o.numero.toLowerCase() === q.toLowerCase());
            if (match) {
                document.getElementById('os-pai-id').value = match.id;
                const tag = document.getElementById('os-pai-tag');
                tag.innerHTML = `<i class="fas fa-link"></i> ${match.numero} — ${match.titulo} <button onclick="limparOSPai()">×</button>`;
                tag.style.display = 'inline-flex';
            }
        }, 500);
    });
}

window.limparOSPai = () => {
    document.getElementById('os-pai-id').value = '';
    document.getElementById('os-pai-busca').value = '';
    document.getElementById('os-pai-tag').style.display = 'none';
};

// ─── INICIALIZAÇÃO ────────────────────────────────────────────────────────
function init_modulo() {
    log('Iniciando módulo Ordens de Serviço');

    initAbas();
    initFiltros();
    initEditor();
    initInteracaoEditor();
    initAutocompleteMorador();
    initAutocompleteRH();
    initAutocompleteProdutos();
    initDetalheAbas();
    initBuscaOSPai();

    // Pré-carrega o cache de Etapas uma única vez, no início do módulo —
    // evita que os 3 pontos que dependem dele (checkbox no modal-os, aba
    // Projeto, aba Interações) disparem buscas concorrentes/atrasadas e
    // apareçam vazios na primeira renderização.
    carregarEtapas();

    // Botão Nova OS
    document.getElementById('btnNovaOS').addEventListener('click', () => {
        abrirModalNova();
    });

    // Botões do modal OS
    document.getElementById('btnSalvarOS').addEventListener('click', salvarOS);
    document.getElementById('btnCancelarOS').addEventListener('click', fecharModalOS);
    document.getElementById('btnFecharModalOS').addEventListener('click', fecharModalOS);

    // Checkbox "Publicar como Projeto" — revela/oculta Etapa e Conclusão
    // no próprio modal, via JS, sem reload de página.
    document.getElementById('os-projeto-publico').addEventListener('change', () => {
        const etapaAtual = document.getElementById('os-projeto-etapa').value || null;
        const percentualAtual = document.getElementById('os-projeto-percentual').value || '';
        _atualizarCamposProjetoOS(etapaAtual, percentualAtual);
    });

    // Fechar modal ao clicar no overlay
    document.getElementById('modal-os').addEventListener('click', e => {
        if (e.target === document.getElementById('modal-os')) fecharModalOS();
    });

    // Botões do modal detalhe
    document.getElementById('btnFecharDetalhe').addEventListener('click', () => {
        document.getElementById('modal-detalhe').style.display = 'none';
    });
    document.getElementById('btnFecharDetalhe2').addEventListener('click', () => {
        document.getElementById('modal-detalhe').style.display = 'none';
    });
    document.getElementById('btnEditarOS').addEventListener('click', () => {
        if (state.osAtual) {
            document.getElementById('modal-detalhe').style.display = 'none';
            abrirEditar(state.osAtual.id);
        }
    });
    document.getElementById('modal-detalhe').addEventListener('click', e => {
        if (e.target === document.getElementById('modal-detalhe')) {
            document.getElementById('modal-detalhe').style.display = 'none';
        }
    });

    // Interações
    document.getElementById('btnAdicionarInteracao').addEventListener('click', adicionarInteracao);
    document.getElementById('btnIniciarFinalizacao').addEventListener('click', iniciarFinalizacao);
    document.getElementById('btnCancelarFinalizacao').addEventListener('click', cancelarFinalizacao);
    document.getElementById('btnConfirmarFinalizacao').addEventListener('click', confirmarFinalizacao);

    // Materiais
    document.getElementById('btnAdicionarMaterial').addEventListener('click', adicionarMaterial);

    // Modal Assunto
    document.getElementById('btnNovoAssunto').addEventListener('click', () => {
        document.getElementById('assunto-id').value = '';
        document.getElementById('assunto-nome').value = '';
        document.getElementById('assunto-descricao').value = '';
        document.getElementById('assunto-departamento').value = '';
        document.getElementById('modal-assunto-titulo').innerHTML = '<i class="fas fa-tag"></i> Novo Assunto';
        document.getElementById('modal-assunto').style.display = 'flex';
    });
    document.getElementById('btnSalvarAssunto').addEventListener('click', salvarAssunto);
    document.getElementById('btnCancelarAssunto').addEventListener('click', () => {
        document.getElementById('modal-assunto').style.display = 'none';
    });
    document.getElementById('btnFecharModalAssunto').addEventListener('click', () => {
        document.getElementById('modal-assunto').style.display = 'none';
    });
    document.getElementById('modal-assunto').addEventListener('click', e => {
        if (e.target === document.getElementById('modal-assunto')) {
            document.getElementById('modal-assunto').style.display = 'none';
        }
    });

    // Modal Config HH
    document.getElementById('btnNovaConfig').addEventListener('click', () => {
        document.getElementById('hh-id').value = '';
        document.getElementById('hh-assunto').value = '';
        document.getElementById('hh-descricao').value = '';
        document.getElementById('hh-horas').value = '1';
        document.getElementById('hh-custo').value = '0';
        document.getElementById('modal-hh-titulo').innerHTML = '<i class="fas fa-user-clock"></i> Nova Configuração';
        document.getElementById('modal-config-hh').style.display = 'flex';
    });
    document.getElementById('btnSalvarHH').addEventListener('click', salvarConfigHH);
    document.getElementById('btnCancelarHH').addEventListener('click', () => {
        document.getElementById('modal-config-hh').style.display = 'none';
    });
    document.getElementById('btnFecharModalHH').addEventListener('click', () => {
        document.getElementById('modal-config-hh').style.display = 'none';
    });
    document.getElementById('modal-config-hh').addEventListener('click', e => {
        if (e.target === document.getElementById('modal-config-hh')) {
            document.getElementById('modal-config-hh').style.display = 'none';
        }
    });

    // Modal Etapa (Módulo Projetos)
    document.getElementById('btnNovaEtapa').addEventListener('click', () => {
        document.getElementById('etapa-id').value = '';
        document.getElementById('etapa-nome').value = '';
        document.getElementById('etapa-ordem').value = (state.etapas || []).length + 1;
        document.getElementById('modal-etapa-titulo').innerHTML = '<i class="fas fa-list-ol"></i> Nova Etapa';
        document.getElementById('modal-etapa').style.display = 'flex';
    });
    document.getElementById('btnSalvarEtapa').addEventListener('click', salvarEtapa);
    document.getElementById('btnCancelarEtapa').addEventListener('click', () => {
        document.getElementById('modal-etapa').style.display = 'none';
    });
    document.getElementById('btnFecharModalEtapa').addEventListener('click', () => {
        document.getElementById('modal-etapa').style.display = 'none';
    });
    document.getElementById('modal-etapa').addEventListener('click', e => {
        if (e.target === document.getElementById('modal-etapa')) {
            document.getElementById('modal-etapa').style.display = 'none';
        }
    });

    // Aba Projeto (modal de detalhe)
    document.getElementById('btnSalvarProjeto').addEventListener('click', salvarProjeto);
    document.getElementById('projeto-capa-input').addEventListener('change', e => {
        const file = e.target.files[0];
        if (file) uploadCapaProjeto(file);
        e.target.value = '';
    });

    // Interações — mostrar/ocultar campos de Andamento (Etapa/%/Fotos/Publicar)
    document.getElementById('int-tipo').addEventListener('change', _atualizarVisibilidadeAndamentoExtra);
    document.getElementById('int-fotos').addEventListener('change', e => {
        const cnt = document.getElementById('intFotosCount');
        cnt.textContent = e.target.files.length ? `${e.target.files.length} foto(s) selecionada(s)` : '';
    });

    // Relatórios
    document.getElementById('btnGerarRelatorio').addEventListener('click', gerarRelatorio);
    document.getElementById('btnExportarCSV').addEventListener('click', exportarCSV);
    document.getElementById('btnExportarPDF').addEventListener('click', exportarPDF);

    // Carregar dados iniciais — primeiro busca o usuário logado para auto-preencher o atendente
    fetch(API_USUARIO_LOGADO, { credentials: 'include' })
        .then(r => r.json())
        .then(json => {
            if (json.sucesso && json.usuario) {
                state.usuarioLogado = json.usuario;
                log('Usuário logado:', json.usuario.nome);
            }
        })
        .catch(e => log('Erro ao buscar usuário logado', e))
        .finally(() => {
            carregarSelects().then(() => {
                // Após carregar selects, auto-preencher o atendente no select
                if (state.usuarioLogado) {
                    const sel = document.getElementById('os-atendente');
                    if (sel) {
                        const opt = Array.from(sel.options).find(o => o.value == state.usuarioLogado.id);
                        if (opt) sel.value = opt.value;
                    }
                }
                carregarDashboard();
            });
        });

    log('Módulo Ordens de Serviço inicializado');
}

 // ─── Lifecycle ES6 Module ─────────────────────────────────────────────────────
export function init() {
// O router chama init() após injetar o HTML
init_modulo();
}
export function destroy() {
log('Destruindo módulo Ordens de Serviço');
// Remover globals expostos
['osPaginar','osVerDetalhe','osAbrirEditar','osExcluir','osRemoverMaterial',
 'osRemoverRH','osEditarAssunto','osExcluirAssunto','osEditarConfigHH','osExcluirConfigHH',
 'osAssuntosPag','osConfigHHPag','osEditarEtapa','osExcluirEtapa',
 '_osSalvarDocsProjeto',
 'limparMorador','limparOSPai','osRemoverAnexo'].forEach(k => { if (window[k]) delete window[k]; });
if (window.OrdensServico) delete window.OrdensServico;
}

// ─── ASSUMIR OS DO PORTAL ──────────────────────────────────────────────────────
// Nota: estas funções são expostas via window.* pois são chamadas inline no HTML
function osAbrirAssumirPortal(id) {
    const existente = document.getElementById('modalAssumirPortalOS');
    if (existente) existente.remove();
    const modalEl = document.createElement('div');
    modalEl.id = 'modalAssumirPortalOS';
    modalEl.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;padding:1rem;';
    modalEl.innerHTML = `
        <div style="background:#fff;border-radius:12px;padding:1.5rem;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.2);">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;">
                <i class="fas fa-hand-paper" style="color:#d97706;font-size:1.2rem;"></i>
                <h3 style="margin:0;font-size:1.05rem;font-weight:700;">Assumir OS do Portal</h3>
            </div>
            <p style="font-size:.88rem;color:#64748b;margin:0 0 1rem;">
                Ao assumir esta OS, você se torna o atendente responsável.
                Classifique a prioridade e, se houver, informe a OS pai.
            </p>
            <div style="margin-bottom:.75rem;">
                <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem;">
                    Prioridade <span style="color:#dc2626">*</span>
                </label>
                <select id="assumirPortalPrioridade" style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem;">
                    <option value="media">Média</option>
                    <option value="baixa">Baixa</option>
                    <option value="alta">Alta</option>
                    <option value="urgente">Urgente</option>
                </select>
            </div>
            <div style="margin-bottom:1.25rem;">
                <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem;">Nº OS Pai (opcional)</label>
                <input type="number" id="assumirPortalOSPai" placeholder="ID da OS pai (se houver)"
                    style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem;box-sizing:border-box;">
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                <button onclick="document.getElementById('modalAssumirPortalOS').remove()"
                    style="padding:.5rem 1rem;border:1.5px solid #e2e8f0;border-radius:8px;background:transparent;cursor:pointer;font-size:.88rem;">
                    Cancelar
                </button>
                <button id="btnConfirmarAssumirPortal" data-os-id="${id}"
                    onclick="osConfirmarAssumirPortal(${id})"
                    style="padding:.5rem 1.25rem;background:#d97706;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem;font-weight:700;">
                    <i class="fas fa-check"></i> Assumir
                </button>
            </div>
        </div>`;
    document.body.appendChild(modalEl);
}
window.osAbrirAssumirPortal = osAbrirAssumirPortal;

function osConfirmarAssumirPortal(id) {
    const prioridade = document.getElementById('assumirPortalPrioridade')?.value || 'media';
    const osPaiRaw   = document.getElementById('assumirPortalOSPai')?.value || '';
    const osPaiId    = osPaiRaw ? parseInt(osPaiRaw) : null;
    const btn = document.getElementById('btnConfirmarAssumirPortal');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assumindo...'; }

    fetch(API_OS + '?acao=assumir_portal', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, prioridade, os_pai_id: osPaiId })
    })
    .then(r => r.json())
    .then(res => {
        const modal = document.getElementById('modalAssumirPortalOS');
        if (modal) modal.remove();
        if (res.sucesso) {
            mostrarToast(res.mensagem || 'OS assumida com sucesso!', 'success');
            carregarChamados(state.paginaAtual || 1);
        } else {
            mostrarToast(res.mensagem || 'Erro ao assumir OS.', 'error');
        }
    })
    .catch(e => {
        log('Erro ao assumir OS do portal:', e);
        mostrarToast('Erro de conexão. Tente novamente.', 'error');
    });
}
window.osConfirmarAssumirPortal = osConfirmarAssumirPortal;
