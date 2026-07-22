'use strict';

const apiUrl = '../api/api_logs_sistema.php';

const CATEGORIA_TIPOS = {
    auth:       ['LOGIN_SUCESSO', 'LOGIN_FALHA', 'LOGIN_ERRO', 'LOGIN_BLOQUEADO', 'LOGIN_CLIENT_ERROR'],
    acesso:     ['ACESSO_NEGADO', 'ACESSO_RFID', 'ACESSO_NEGADO_RFID', 'IP_BLOQUEADO'],
    rfid:       ['ACESSO_RFID', 'ACESSO_NEGADO_RFID'],
    sessao:     ['SESSAO_VERIFICAR', 'SESSAO_RENOVADA'],
    dados:      ['OBTER_DADOS_ERRO'],
    manutencao: ['LIMPEZA_LOGS'],
};

const state = {
    pagina: 1,
    totalPaginas: 1,
    relPagina: 1,
    abaAtiva: 'auditoria',
    todosTipos: [],
    listeners: [],
};

export function init() {
    window.SegurancaPage = {
        trocarAba,
        gerarRelatorio,
        limparFiltrosRel,
        exportarRelatorio,
    };

    _setupListeners();
    _setDefaultPeriod();
    _carregarTipos();
    _carregarLogs();
    _carregarEstatisticas();
}

export function destroy() {
    state.listeners.forEach(({ el, type, fn }) => el.removeEventListener(type, fn));
    state.listeners = [];
    delete window.SegurancaPage;
}

// ============================================================
// TAB SWITCHING
// ============================================================
function trocarAba(aba) {
    state.abaAtiva = aba;

    document.querySelectorAll('.page-seguranca .tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === aba);
    });

    const tabAuditoria  = document.getElementById('tab-auditoria');
    const tabRelatorios = document.getElementById('tab-relatorios');

    if (aba === 'auditoria') {
        tabAuditoria.classList.remove('seg-hidden');
        tabRelatorios.classList.add('seg-hidden');
    } else {
        tabAuditoria.classList.add('seg-hidden');
        tabRelatorios.classList.remove('seg-hidden');
        _syncRelPeriodo();
        _filtrarRelTipo();
    }
}

// ============================================================
// EVENT SETUP
// ============================================================
function _setupListeners() {
    _bind('#btnBuscar',     e => { e.preventDefault(); _carregarLogs(1); });
    _bind('#btnLimpar',     e => { e.preventDefault(); _limparFiltros(); });
    _bind('#btnExportar',   e => { e.preventDefault(); _exportarCSV(); });
    _bind('#btnAtualizar',  e => { e.preventDefault(); _carregarEstatisticas(); _carregarLogs(1); });
    _bind('#btnLimparLogs', e => { e.preventDefault(); _confirmarLimpeza(); });

    ['#filtroUsuario', '#filtroBusca'].forEach(sel => {
        const el = document.querySelector(sel);
        if (!el) return;
        const fn = e => { if (e.key === 'Enter') _carregarLogs(1); };
        el.addEventListener('keypress', fn);
        state.listeners.push({ el, type: 'keypress', fn });
    });

    const relCat = document.getElementById('relCategoria');
    if (relCat) {
        const fn = () => _filtrarRelTipo();
        relCat.addEventListener('change', fn);
        state.listeners.push({ el: relCat, type: 'change', fn });
    }
}

function _bind(selector, fn) {
    const el = document.querySelector(selector);
    if (!el) return;
    el.addEventListener('click', fn);
    state.listeners.push({ el, type: 'click', fn });
}

// ============================================================
// AUDITORIA TAB
// ============================================================
function _setDefaultPeriod() {
    const hoje  = new Date();
    const inicio = new Date(hoje.getTime() - 29 * 24 * 60 * 60 * 1000);
    const fmt   = d => d.toISOString().split('T')[0];

    const di = document.getElementById('filtroDataInicio');
    const df = document.getElementById('filtroDataFim');
    if (di) di.value = fmt(inicio);
    if (df) df.value = fmt(hoje);
}

function _carregarTipos() {
    fetch(`${apiUrl}?action=tipos`)
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) return;
            state.todosTipos = data.dados;

            const sel  = document.getElementById('filtroTipo');
            const tags = document.getElementById('segurancaTags');
            sel.innerHTML  = '<option value="">Todos os tipos</option>';
            tags.innerHTML = '';

            data.dados.forEach(t => {
                sel.innerHTML += `<option value="${t.tipo}">${t.tipo} (${t.total})</option>`;
                const pill = document.createElement('span');
                pill.className   = 'seg-pill';
                pill.innerHTML   = `<i class="fas fa-tag"></i> ${t.tipo}`;
                tags.appendChild(pill);
            });

            document.getElementById('segurancaTipos').textContent = data.dados.length;
            _populateRelTipo(data.dados);
        })
        .catch(err => console.error('[seguranca] tipos:', err));
}

function _populateRelTipo(tipos) {
    const sel = document.getElementById('relTipo');
    if (!sel) return;
    sel.innerHTML = '<option value="">Todos os tipos</option>';
    tipos.forEach(t => {
        sel.innerHTML += `<option value="${t.tipo}">${t.tipo} (${t.total})</option>`;
    });
}

function _filtrarRelTipo() {
    const cat     = document.getElementById('relCategoria')?.value || '';
    const sel     = document.getElementById('relTipo');
    if (!sel) return;

    const allowed = cat ? (CATEGORIA_TIPOS[cat] || []) : null;
    sel.innerHTML = '<option value="">Todos os tipos</option>';
    state.todosTipos.forEach(t => {
        if (!allowed || allowed.includes(t.tipo)) {
            sel.innerHTML += `<option value="${t.tipo}">${t.tipo} (${t.total})</option>`;
        }
    });
}

function _carregarLogs(pagina = 1) {
    state.pagina = pagina;
    const tbody = document.querySelector('#tableLogs tbody');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;">Carregando...</td></tr>';

    const p = new URLSearchParams();
    p.append('pagina', pagina);
    p.append('limite', document.getElementById('filtroLimite').value || '100');

    const tipo    = document.getElementById('filtroTipo').value;
    const usuario = document.getElementById('filtroUsuario').value.trim();
    const di      = document.getElementById('filtroDataInicio').value;
    const df      = document.getElementById('filtroDataFim').value;
    const busca   = document.getElementById('filtroBusca').value.trim();

    if (tipo)    p.append('tipo', tipo);
    if (usuario) p.append('usuario', usuario);
    if (di)      p.append('data_inicio', di);
    if (df)      p.append('data_fim', df);
    if (busca)   p.append('busca', busca);

    fetch(`${apiUrl}?${p}`)
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) {
                _mostrarAlerta('error', data.mensagem || 'Falha ao carregar logs');
                _renderTabela([]);
                _renderPaginacao({ pagina_atual: 1, total_paginas: 1 });
                return;
            }
            _renderTabela(data.dados.logs);
            _renderPaginacao(data.dados.paginacao);
            document.getElementById('segurancaTotal').textContent = data.dados.paginacao.total_registros;
        })
        .catch(err => {
            console.error('[seguranca] logs:', err);
            _mostrarAlerta('error', 'Erro: ' + err.message);
            _renderTabela([]);
        });
}

function _renderTabela(logs) {
    const tbody = document.querySelector('#tableLogs tbody');
    tbody.innerHTML = '';

    if (!logs || logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:#64748b;">Nenhum registro encontrado</td></tr>';
        return;
    }

    logs.forEach(log => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${log.id}</td>
            <td>${log.data_hora_formatada}</td>
            <td><span class="badge ${_badgeClass(log.tipo)}">${log.tipo}</span></td>
            <td style="max-width:420px;word-break:break-word;">${log.descricao || ''}</td>
            <td>${log.usuario || '<em>Sistema</em>'}</td>
            <td>${log.ip || '—'}</td>
        `;
        tbody.appendChild(tr);
    });
}

function _badgeClass(tipo) {
    const map = {
        LOGIN_SUCESSO:     'badge-success',
        LOGIN_FALHA:       'badge-danger',
        LOGIN_ERRO:        'badge-danger',
        LOGIN_BLOQUEADO:   'badge-warning',
        ACESSO_RFID:       'badge-success',
        ACESSO_NEGADO:     'badge-danger',
        ACESSO_NEGADO_RFID:'badge-danger',
        IP_BLOQUEADO:      'badge-warning',
        LIMPEZA_LOGS:      'badge-info',
        OBTER_DADOS_ERRO:  'badge-danger',
        SESSAO_VERIFICAR:  'badge-info',
        SESSAO_RENOVADA:   'badge-info',
        LOGIN_CLIENT_ERROR:'badge-warning',
    };
    return map[tipo] || 'badge-secondary';
}

function _renderPaginacao(paginacao) {
    state.totalPaginas = paginacao.total_paginas || 1;
    const container = document.getElementById('pagination');
    container.innerHTML = '';
    _buildPaginacao(container, state.pagina, state.totalPaginas, p => _carregarLogs(p));
}

function _limparFiltros() {
    ['filtroTipo', 'filtroUsuario', 'filtroDataInicio', 'filtroDataFim', 'filtroBusca'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const lim = document.getElementById('filtroLimite');
    if (lim) lim.value = '100';
    _setDefaultPeriod();
    _carregarLogs(1);
}

function _carregarEstatisticas() {
    const di = document.getElementById('filtroDataInicio').value
        || new Date(Date.now() - 29 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
    const df = document.getElementById('filtroDataFim').value
        || new Date().toISOString().split('T')[0];

    const fd = new FormData();
    fd.append('data_inicio', di);
    fd.append('data_fim',    df);

    fetch(`${apiUrl}?action=estatisticas`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) return;
            const byTipo  = data.dados.logs_por_tipo || [];
            const total   = data.dados.total_geral || 0;
            const acessos = byTipo.reduce((n, i) =>
                n + (['ACESSO','LOGIN'].some(k => i.tipo.includes(k)) ? parseInt(i.total, 10) : 0), 0);
            const erros   = byTipo.reduce((n, i) =>
                n + (['ERRO','FALHA'].some(k => i.tipo.toUpperCase().includes(k)) ? parseInt(i.total, 10) : 0), 0);

            document.getElementById('segurancaTotal').textContent   = total;
            document.getElementById('segurancaAcessos').textContent = acessos;
            document.getElementById('segurancaErros').textContent   = erros;
        })
        .catch(err => console.error('[seguranca] estatísticas:', err));
}

function _exportarCSV() {
    const p = new URLSearchParams({ action: 'exportar' });
    const tipo    = document.getElementById('filtroTipo').value;
    const usuario = document.getElementById('filtroUsuario').value.trim();
    const di      = document.getElementById('filtroDataInicio').value;
    const df      = document.getElementById('filtroDataFim').value;
    const busca   = document.getElementById('filtroBusca').value.trim();

    if (tipo)    p.append('tipo', tipo);
    if (usuario) p.append('usuario', usuario);
    if (di)      p.append('data_inicio', di);
    if (df)      p.append('data_fim', df);
    if (busca)   p.append('busca', busca);

    fetch(`${apiUrl}?${p}`)
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) { _mostrarAlerta('error', data.mensagem || 'Erro ao exportar'); return; }
            _downloadCSV(data.dados, `seguranca_auditoria_${new Date().toISOString().split('T')[0]}.csv`);
            _mostrarAlerta('success', `${data.dados.length} logs exportados.`);
        })
        .catch(err => _mostrarAlerta('error', 'Erro ao exportar: ' + err.message));
}

function _confirmarLimpeza() {
    const dias = prompt('Limpar logs com mais de quantos dias?\n\n(Mínimo: 30 dias)\n(Recomendado: 90 dias)', '90');
    if (dias === null) return;
    const d = parseInt(dias, 10);
    if (isNaN(d) || d < 30) { _mostrarAlerta('error', 'Mínimo de 30 dias.'); return; }
    if (!confirm(`Deseja realmente limpar logs com mais de ${d} dias? Esta ação não pode ser desfeita.`)) return;

    fetch(`${apiUrl}?action=limpar`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ dias: d }),
    })
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) { _mostrarAlerta('error', data.mensagem || 'Erro ao limpar logs'); return; }
            _mostrarAlerta('success', `${data.dados.registros_excluidos} logs antigos removidos.`);
            _carregarLogs(1);
            _carregarEstatisticas();
        })
        .catch(err => _mostrarAlerta('error', 'Erro: ' + err.message));
}

// ============================================================
// RELATÓRIOS TAB
// ============================================================
function _syncRelPeriodo() {
    const di  = document.getElementById('filtroDataInicio').value;
    const df  = document.getElementById('filtroDataFim').value;
    const rdi = document.getElementById('relDataInicio');
    const rdf = document.getElementById('relDataFim');
    if (rdi && !rdi.value && di) rdi.value = di;
    if (rdf && !rdf.value && df) rdf.value = df;
}

function gerarRelatorio(pagina = 1) {
    state.relPagina = pagina;

    const p = new URLSearchParams({ action: 'relatorio', pagina });
    const cat  = document.getElementById('relCategoria')?.value      || '';
    const tipo = document.getElementById('relTipo')?.value           || '';
    const sev  = document.getElementById('relSeveridade')?.value     || '';
    const di   = document.getElementById('relDataInicio')?.value     || '';
    const df   = document.getElementById('relDataFim')?.value        || '';
    const usr  = (document.getElementById('relUsuario')?.value || '').trim();

    if (cat)  p.append('categoria',   cat);
    if (tipo) p.append('tipo',        tipo);
    if (sev)  p.append('severidade',  sev);
    if (di)   p.append('data_inicio', di);
    if (df)   p.append('data_fim',    df);
    if (usr)  p.append('usuario',     usr);

    const tbody = document.getElementById('rel-logs-tbody');
    tbody.innerHTML = '<tr><td colspan="7" class="seg-empty-cell">Carregando...</td></tr>';

    fetch(`${apiUrl}?${p}`)
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) {
                _mostrarAlerta('error', data.mensagem || 'Erro ao gerar relatório');
                return;
            }
            _renderResumo(data.dados.resumo_por_tipo || []);
            _renderTimeline(data.dados.timeline       || []);
            _renderUsuarios(data.dados.top_usuarios   || []);
            _renderRelLogs(data.dados.logs            || []);
            _renderRelPaginacao(data.dados.paginacao  || {});

            const info  = document.getElementById('rel-logs-info');
            const total = data.dados.paginacao?.total_registros || 0;
            if (info) info.textContent = `${total} registro(s) encontrado(s).`;
        })
        .catch(err => {
            _mostrarAlerta('error', 'Erro: ' + err.message);
            tbody.innerHTML = '<tr><td colspan="7" class="seg-empty-cell">Erro ao carregar dados.</td></tr>';
        });
}

function _renderResumo(resumo) {
    const card = document.getElementById('rel-resumo-card');
    const grid = document.getElementById('rel-exception-grid');
    if (!grid) return;

    if (resumo.length === 0) { card.classList.add('seg-hidden'); return; }

    card.classList.remove('seg-hidden');
    grid.innerHTML = resumo.map(item => {
        const sev = _severidadeFromTipo(item.tipo);
        return `
            <div class="seg-exception-card seg-exception-card--${sev}">
                <span class="exc-num">${item.total}</span>
                <span class="exc-type">${item.tipo}</span>
                <span class="exc-last">${item.ultimo || ''}</span>
            </div>
        `;
    }).join('');
}

function _renderTimeline(timeline) {
    const card = document.getElementById('rel-timeline-card');
    const wrap = document.getElementById('rel-timeline');
    if (!wrap) return;

    if (timeline.length === 0) { card.classList.add('seg-hidden'); return; }

    card.classList.remove('seg-hidden');
    const maxVal = Math.max(...timeline.map(d => parseInt(d.total, 10)));

    wrap.innerHTML = timeline.map(item => {
        const h = maxVal > 0 ? Math.max(3, Math.round((parseInt(item.total, 10) / maxVal) * 90)) : 3;
        return `
            <div class="seg-tl-bar" title="${item.dia}: ${item.total}">
                <span class="seg-tl-count">${item.total}</span>
                <div class="seg-tl-fill" style="height:${h}px;"></div>
                <span class="seg-tl-label">${item.dia}</span>
            </div>
        `;
    }).join('');
}

function _renderUsuarios(usuarios) {
    const card  = document.getElementById('rel-usuarios-card');
    const tbody = document.getElementById('rel-usuarios-tbody');
    if (!tbody) return;

    if (usuarios.length === 0) { card.classList.add('seg-hidden'); return; }

    card.classList.remove('seg-hidden');
    tbody.innerHTML = usuarios.map((u, i) => `
        <tr>
            <td>${i + 1}</td>
            <td>${u.usuario || '<em>Sistema</em>'}</td>
            <td><strong>${u.total}</strong></td>
            <td>${u.ultimo_acesso || '—'}</td>
        </tr>
    `).join('');
}

function _renderRelLogs(logs) {
    const tbody = document.getElementById('rel-logs-tbody');
    if (!tbody) return;

    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="seg-empty-cell"><i class="fas fa-search seg-empty-icon"></i>Nenhum registro encontrado para os filtros selecionados.</td></tr>';
        return;
    }

    tbody.innerHTML = logs.map(log => {
        const sev = _severidadeFromTipo(log.tipo);
        return `
            <tr>
                <td>${log.id}</td>
                <td>${log.data_hora_formatada}</td>
                <td><span class="badge ${_badgeClass(log.tipo)}">${log.tipo}</span></td>
                <td>${_badgeSeveridade(sev)}</td>
                <td style="max-width:360px;word-break:break-word;">${log.descricao || ''}</td>
                <td>${log.usuario || '<em>Sistema</em>'}</td>
                <td>${log.ip || '—'}</td>
            </tr>
        `;
    }).join('');
}

function _renderRelPaginacao(paginacao) {
    const container = document.getElementById('rel-pagination');
    if (!container) return;
    container.innerHTML = '';
    _buildPaginacao(container, paginacao.pagina_atual || 1, paginacao.total_paginas || 1, p => gerarRelatorio(p));
}

function limparFiltrosRel() {
    ['relCategoria', 'relTipo', 'relSeveridade', 'relDataInicio', 'relDataFim', 'relUsuario'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    _filtrarRelTipo();
}

function exportarRelatorio() {
    const p = new URLSearchParams({ action: 'relatorio', pagina: 1, limite: 9999 });
    const cat  = document.getElementById('relCategoria')?.value      || '';
    const tipo = document.getElementById('relTipo')?.value           || '';
    const sev  = document.getElementById('relSeveridade')?.value     || '';
    const di   = document.getElementById('relDataInicio')?.value     || '';
    const df   = document.getElementById('relDataFim')?.value        || '';
    const usr  = (document.getElementById('relUsuario')?.value || '').trim();

    if (cat)  p.append('categoria',   cat);
    if (tipo) p.append('tipo',        tipo);
    if (sev)  p.append('severidade',  sev);
    if (di)   p.append('data_inicio', di);
    if (df)   p.append('data_fim',    df);
    if (usr)  p.append('usuario',     usr);

    fetch(`${apiUrl}?${p}`)
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) { _mostrarAlerta('error', data.mensagem || 'Erro ao exportar'); return; }
            const logs = (data.dados.logs || []).map(l => ({
                ...l, severidade: _severidadeFromTipo(l.tipo), data_hora: l.data_hora_formatada,
            }));
            _downloadCSV(
                logs,
                `seguranca_relatorio_${new Date().toISOString().split('T')[0]}.csv`,
                ['id', 'data_hora', 'tipo', 'severidade', 'descricao', 'usuario', 'ip']
            );
            _mostrarAlerta('success', `${logs.length} registros exportados.`);
        })
        .catch(err => _mostrarAlerta('error', 'Erro ao exportar: ' + err.message));
}

// ============================================================
// SHARED HELPERS
// ============================================================
function _severidadeFromTipo(tipo) {
    if (['LOGIN_BLOQUEADO', 'ACESSO_NEGADO', 'ACESSO_NEGADO_RFID', 'IP_BLOQUEADO'].includes(tipo)) return 'critico';
    if (tipo.includes('ERRO') || tipo.includes('FALHA') || tipo === 'LOGIN_CLIENT_ERROR') return 'erro';
    if (tipo === 'SESSAO_VERIFICAR' || tipo.includes('AVISO')) return 'aviso';
    return 'info';
}

function _badgeSeveridade(sev) {
    const map    = { critico: 'badge-danger', erro: 'badge-warning', aviso: 'badge-info', info: 'badge-success' };
    const labels = { critico: 'Crítico', erro: 'Erro', aviso: 'Aviso', info: 'Info' };
    return `<span class="badge ${map[sev] || 'badge-secondary'}">${labels[sev] || sev}</span>`;
}

function _buildPaginacao(container, paginaAtual, totalPaginas, callback) {
    const mkBtn = (label, page, disabled = false, active = false) => {
        const btn = document.createElement('button');
        btn.textContent = label;
        btn.disabled    = disabled;
        if (active) btn.classList.add('active');
        btn.addEventListener('click', () => callback(page));
        return btn;
    };

    container.appendChild(mkBtn('«', 1,           paginaAtual === 1));
    container.appendChild(mkBtn('‹', Math.max(1, paginaAtual - 1), paginaAtual === 1));

    const maxBtns = 5;
    let start = Math.max(1, paginaAtual - 2);
    let end   = Math.min(totalPaginas, start + maxBtns - 1);
    if (end - start < maxBtns - 1) start = Math.max(1, end - maxBtns + 1);

    for (let i = start; i <= end; i++) {
        container.appendChild(mkBtn(String(i), i, false, i === paginaAtual));
    }

    container.appendChild(mkBtn('›', Math.min(totalPaginas, paginaAtual + 1), paginaAtual === totalPaginas));
    container.appendChild(mkBtn('»', totalPaginas, paginaAtual === totalPaginas));

    const info = document.createElement('span');
    info.style.cssText = 'color:#64748b;font-size:0.82rem;margin-left:8px;';
    info.textContent   = `${paginaAtual} / ${totalPaginas}`;
    container.appendChild(info);
}

function _downloadCSV(logs, filename, cols) {
    const fields = cols || ['id', 'data_hora_formatada', 'tipo', 'descricao', 'usuario', 'ip'];
    const header = fields.join(',');
    const rows   = logs.map(l =>
        fields.map(f => `"${(l[f] || '').toString().replace(/"/g, '""')}"`).join(',')
    );
    const blob = new Blob([[header, ...rows].join('\n')], { type: 'text/csv;charset=utf-8;' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
}

function _mostrarAlerta(tipo, mensagem) {
    const container = document.querySelector('.page-seguranca');
    if (!container) return;

    const alerta = document.createElement('div');
    alerta.className = `page-alert page-alert-${tipo}`;
    const icon = tipo === 'success' ? 'check-circle' : 'exclamation-circle';
    alerta.innerHTML = `<i class="fas fa-${icon}"></i> ${mensagem}`;

    const ref = container.querySelector('.seg-kpi-grid') || container.firstElementChild;
    container.insertBefore(alerta, ref);
    setTimeout(() => alerta.remove(), 5000);
}
