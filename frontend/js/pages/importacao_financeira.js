/**
 * ============================================================
 * IMPORTAÇÃO FINANCEIRA — importacao_financeira.js
 * ============================================================
 * Módulo para importar extratos e contas de outros sistemas.
 * Suporta: Extrato BRCondos (PDF), Contas a Pagar BRCondos (PDF), CSV genérico.
 *
 * Objeto público: window.ImpFin  (e window.ImportacaoFinanceira para compatibilidade)
 * @version 2.0.0
 */
(function () {
    'use strict';

    const API = '../api/api_importacao_financeira.php';

    // ── Estado interno ────────────────────────────────────────
    let _loteAtual      = null;
    let _paginaVis      = 1;
    let _totalPagVis    = 1;
    let _itensCacheVis  = [];
    let _lotesCache     = [];
    let _arquivoSel     = null;

    // ══════════════════════════════════════════════════════════
    // INICIALIZAÇÃO
    // ══════════════════════════════════════════════════════════
    function init() {
        console.debug('[ImpFin] init v2.0');

        // Drag and drop
        const zone = document.getElementById('imp_dropZone');
        if (zone) {
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
            zone.addEventListener('drop', e => {
                e.preventDefault();
                zone.classList.remove('drag-over');
                const file = e.dataTransfer.files[0];
                if (file) _selecionarArquivo(file);
            });
        }

        // Input file
        const inp = document.getElementById('imp_arquivo');
        if (inp) inp.addEventListener('change', () => { if (inp.files[0]) _selecionarArquivo(inp.files[0]); });

        // Datas padrão: início do mês atual até hoje
        const hoje = new Date();
        const ini  = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
        const di   = document.getElementById('imp_dataInicio');
        const df   = document.getElementById('imp_dataFim');
        if (di) di.value = ini.toISOString().split('T')[0];
        if (df) df.value = hoje.toISOString().split('T')[0];

        carregarLotes();
    }

    // ══════════════════════════════════════════════════════════
    // TABS
    // ══════════════════════════════════════════════════════════
    function mostrarTab(id, btn) {
        document.querySelectorAll('.imp-tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.imp-tab-btn').forEach(b => b.classList.remove('active'));
        const tab = document.getElementById('imp-tab-' + id);
        if (tab) tab.classList.add('active');
        if (btn) btn.classList.add('active');
        if (id === 'historico') _renderizarHistorico();
    }

    // ══════════════════════════════════════════════════════════
    // UPLOAD E IMPORTAÇÃO
    // ══════════════════════════════════════════════════════════
    function _selecionarArquivo(file) {
        _arquivoSel = file;
        const ext  = file.name.split('.').pop().toUpperCase();
        const nome = document.getElementById('imp_nomeArquivo');
        if (nome) nome.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';

        // Auto-detectar tipo
        const nl = file.name.toLowerCase();
        const sel = document.getElementById('imp_tipoConta');
        if (sel) {
            if (nl.includes('extrato'))                                           sel.value = 'EXTRATO';
            else if (nl.includes('contasapagar') || nl.includes('contas_a_pagar')) sel.value = 'PAGAR';
        }

        const btn = document.getElementById('imp_btnImportar');
        if (btn) btn.disabled = false;
    }

    async function importarArquivo() {
        if (!_arquivoSel) { alert('Selecione um arquivo primeiro.'); return; }

        const form = new FormData();
        form.append('acao',        'importar');
        form.append('arquivo',     _arquivoSel);
        form.append('tipo_conta',  document.getElementById('imp_tipoConta')?.value  || 'EXTRATO');
        form.append('data_inicio', document.getElementById('imp_dataInicio')?.value || '');
        form.append('data_fim',    document.getElementById('imp_dataFim')?.value    || '');

        _setLoading(true, 'Processando arquivo... Isso pode levar alguns segundos para PDFs grandes.');
        const btn = document.getElementById('imp_btnImportar');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...'; }

        try {
            const resp = await fetch(API, { method: 'POST', body: form });
            const data = await resp.json();
            _setLoading(false);

            if (!data.sucesso) {
                alert('Erro: ' + (data.mensagem || 'Falha no processamento.'));
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-import"></i> Processar Arquivo'; }
                return;
            }

            _loteAtual = data.dados;
            _mostrarResultado(data.dados);
            carregarLotes();
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-import"></i> Processar Arquivo'; }

        } catch (e) {
            _setLoading(false);
            alert('Erro de comunicação: ' + e.message);
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-import"></i> Processar Arquivo'; }
        }
    }

    function _mostrarResultado(d) {
        const card = document.getElementById('imp_cardResultado');
        if (!card) return;
        card.style.display = 'block';

        const nomeEl = document.getElementById('imp_nomeArqResultado');
        if (nomeEl) nomeEl.textContent = _arquivoSel?.name || '';

        // KPIs
        const kpis = [
            { label: 'Total de Registros', value: _fmtNum(d.total),                cor: '#2563eb' },
            { label: 'Novos (Pendentes)',   value: _fmtNum(d.pendentes),            cor: '#16a34a' },
            { label: 'Duplicatas',          value: _fmtNum(d.duplicatas),           cor: '#d97706' },
            { label: 'Erros',               value: _fmtNum(d.erros),                cor: '#dc2626' },
            { label: 'Total Entradas',      value: 'R$ ' + _fmtMoeda(d.entradas),  cor: '#16a34a' },
            { label: 'Total Saídas',        value: 'R$ ' + _fmtMoeda(d.saidas),    cor: '#dc2626' }
        ];
        const kpiEl = document.getElementById('imp_kpisResultado');
        if (kpiEl) {
            kpiEl.innerHTML = kpis.map(k => `
              <div class="kpi-card page-card" style="border-top:4px solid ${k.cor};">
                <div class="kpi-label">${k.label}</div>
                <div class="kpi-value" style="color:${k.cor};">${k.value}</div>
              </div>`).join('');
        }

        // Alerta duplicatas
        const alertaDup = document.getElementById('imp_alertaDup');
        if (alertaDup) {
            if (d.duplicatas > 0) {
                alertaDup.style.display = 'flex';
                const txt = document.getElementById('imp_alertaDupTexto');
                if (txt) txt.innerHTML = `<strong>${d.duplicatas} registros</strong> já existem no sistema. Acesse a aba <strong>Conciliação</strong> para revisá-los.`;
                const badge = document.getElementById('imp_badgeDup');
                if (badge) { badge.textContent = d.duplicatas; badge.style.display = 'inline'; }
            } else {
                alertaDup.style.display = 'none';
            }
        }

        _sincronizarSeletores(d.lote_id);
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function confirmarImportacao() {
        if (!_loteAtual) return;
        if (!confirm('Confirmar importação das despesas/receitas para o sistema financeiro?')) return;
        _setLoading(true, 'Importando registros...');
        try {
            const resp = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'confirmar_importar', lote_id: _loteAtual.lote_id })
            });
            const data = await resp.json();
            _setLoading(false);
            alert(data.mensagem || (data.sucesso ? 'Importação concluída!' : 'Erro ao importar.'));
            if (data.sucesso) carregarLotes();
        } catch (e) {
            _setLoading(false);
            alert('Erro: ' + e.message);
        }
    }

    function verExtrato() {
        if (!_loteAtual) return;
        _sincronizarSeletores(_loteAtual.lote_id);
        mostrarTab('extrato', document.getElementById('imp_tabExtrato'));
        carregarResumo(_loteAtual.lote_id);
    }

    function irConciliacao() {
        if (!_loteAtual) return;
        _sincronizarSeletores(_loteAtual.lote_id);
        mostrarTab('conciliar', document.getElementById('imp_tabConciliar'));
        carregarConciliacao(_loteAtual.lote_id);
    }

    function limparForm() {
        _arquivoSel = null;
        const inp = document.getElementById('imp_arquivo');
        if (inp) inp.value = '';
        const nome = document.getElementById('imp_nomeArquivo');
        if (nome) nome.textContent = '';
        const btn = document.getElementById('imp_btnImportar');
        if (btn) btn.disabled = true;
        const card = document.getElementById('imp_cardResultado');
        if (card) card.style.display = 'none';
    }

    // ══════════════════════════════════════════════════════════
    // VISUALIZAÇÃO — RESUMO E GRÁFICOS
    // ══════════════════════════════════════════════════════════
    async function carregarResumo(lote_id) {
        if (!lote_id) return;
        _setLoading(true, 'Carregando resumo do extrato...');
        try {
            const resp = await fetch(`${API}?acao=resumo_lote&lote_id=${lote_id}`);
            const data = await resp.json();
            _setLoading(false);
            if (!data.sucesso) return;

            const d    = data.dados;
            const lote = d.lote;
            const painel = document.getElementById('imp_painelResumo');
            if (painel) painel.style.display = 'block';

            // KPIs
            const saldo = parseFloat(lote.saldo_final) || 0;
            const kpiEl = document.getElementById('imp_kpisExtrato');
            if (kpiEl) {
                kpiEl.innerHTML = [
                    { label: 'Total Registros', value: _fmtNum(lote.total_registros),          cor: '#2563eb' },
                    { label: 'Total Entradas',  value: 'R$ ' + _fmtMoeda(lote.total_entradas), cor: '#16a34a' },
                    { label: 'Total Saídas',    value: 'R$ ' + _fmtMoeda(lote.total_saidas),   cor: '#dc2626' },
                    { label: 'Saldo',           value: 'R$ ' + _fmtMoeda(Math.abs(saldo)),     cor: saldo >= 0 ? '#2563eb' : '#dc2626' },
                    { label: 'Duplicatas',      value: _fmtNum(lote.total_duplicatas),          cor: '#d97706' },
                    { label: 'Importados',      value: _fmtNum(lote.total_importados),          cor: '#16a34a' }
                ].map(k => `
                  <div class="kpi-card page-card" style="border-top:4px solid ${k.cor};">
                    <div class="kpi-label">${k.label}</div>
                    <div class="kpi-value" style="color:${k.cor};">${k.value}</div>
                  </div>`).join('');
            }

            // Gráfico fornecedores (top saídas)
            const topForn = (d.top_fornecedores || []).filter(f => parseFloat(f.total_saida) > 0);
            const maxForn = Math.max(...topForn.map(f => parseFloat(f.total_saida) || 0), 1);
            const chartForn = document.getElementById('imp_chartFornecedores');
            if (chartForn) {
                chartForn.innerHTML = topForn.slice(0, 12).map(f => {
                    const pct = ((parseFloat(f.total_saida) / maxForn) * 100).toFixed(1);
                    return `<div class="imp-bar-item">
                      <div class="imp-bar-label" title="${_esc(f.fornecedor_nome)}">${_esc(f.fornecedor_nome) || '—'}</div>
                      <div class="imp-bar-track"><div class="imp-bar-fill-saida" style="width:${pct}%"></div></div>
                      <div class="imp-bar-val">R$ ${_fmtMoeda(f.total_saida)}</div>
                    </div>`;
                }).join('') || '<p style="color:#94a3b8;font-size:13px;">Nenhuma saída registrada</p>';
            }

            // Gráfico categorias
            const topCat = (d.por_classificacao || []).filter(c => parseFloat(c.total_saida) > 0);
            const maxCat = Math.max(...topCat.map(c => parseFloat(c.total_saida) || 0), 1);
            const chartCat = document.getElementById('imp_chartCategoria');
            if (chartCat) {
                chartCat.innerHTML = topCat.slice(0, 12).map(c => {
                    const pct = ((parseFloat(c.total_saida) / maxCat) * 100).toFixed(1);
                    return `<div class="imp-bar-item">
                      <div class="imp-bar-label" title="${_esc(c.classificacao_despesa)}">${_esc(c.classificacao_despesa) || 'Outros'}</div>
                      <div class="imp-bar-track"><div class="imp-bar-fill-saida" style="width:${pct}%"></div></div>
                      <div class="imp-bar-val">R$ ${_fmtMoeda(c.total_saida)}</div>
                    </div>`;
                }).join('') || '<p style="color:#94a3b8;font-size:13px;">Nenhuma categoria encontrada</p>';
            }

            // Timeline mensal
            const timeline = document.getElementById('imp_timelineMensal');
            if (timeline) {
                timeline.innerHTML = (d.por_mes || []).map(m => {
                    const [ano, mes] = m.mes.split('-');
                    const nomeMes = new Date(parseInt(ano), parseInt(mes) - 1).toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' });
                    return `<div class="imp-mes-card">
                      <div class="mes">${nomeMes.toUpperCase()}</div>
                      ${parseFloat(m.total_entrada) > 0 ? `<div class="ent">▲ R$ ${_fmtMoeda(m.total_entrada)}</div>` : ''}
                      ${parseFloat(m.total_saida)   > 0 ? `<div class="sai">▼ R$ ${_fmtMoeda(m.total_saida)}</div>`   : ''}
                      <div class="qtd">${m.qtd} lançamentos</div>
                    </div>`;
                }).join('') || '<p style="color:#94a3b8;font-size:13px;">Nenhum dado mensal</p>';
            }

            // Carregar tabela de lançamentos
            _paginaVis = 1;
            carregarItens(lote_id);

        } catch (e) {
            _setLoading(false);
            console.error('[ImpFin] carregarResumo:', e);
        }
    }

    async function carregarItens(lote_id, pagina) {
        if (!lote_id) return;
        pagina = pagina || _paginaVis;
        const busca  = document.getElementById('imp_buscaVis')?.value    || '';
        const tipo   = document.getElementById('imp_filtroTipo')?.value   || '';
        const status = document.getElementById('imp_filtroStatus')?.value || '';

        const url = `${API}?acao=listar_itens&lote_id=${lote_id}&pagina=${pagina}&por_pagina=100&busca=${encodeURIComponent(busca)}&tipo=${tipo}&status=${status}`;
        try {
            const resp = await fetch(url);
            const data = await resp.json();
            if (!data.sucesso) return;

            const itens = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);
            _itensCacheVis = itens;
            _totalPagVis   = data.dados?.paginas || 1;
            _paginaVis     = data.dados?.pagina  || pagina;

            const totalEl = document.getElementById('imp_totalLanc');
            if (totalEl) totalEl.textContent = `— ${_fmtNum(data.dados?.total || itens.length)} lançamentos`;

            _renderizarTabelaLancamentos(itens);
            _renderizarPaginacao('imp_paginacaoVis', _paginaVis, _totalPagVis, function(p) {
                _paginaVis = p;
                carregarItens(document.getElementById('imp_seletorLoteVis')?.value, p);
            });
        } catch (e) {
            console.error('[ImpFin] carregarItens:', e);
        }
    }

    function filtrarItens() {
        _paginaVis = 1;
        const lote_id = document.getElementById('imp_seletorLoteVis')?.value;
        if (lote_id) carregarItens(lote_id, 1);
    }

    function _renderizarTabelaLancamentos(itens) {
        const tbody = document.getElementById('imp_tbodyLanc');
        if (!tbody) return;
        if (!itens || itens.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="empty-table">Nenhum lançamento encontrado</td></tr>';
            return;
        }
        tbody.innerHTML = itens.map(item => {
            const ent = parseFloat(item.valor_entrada) || 0;
            const sai = parseFloat(item.valor_saida)   || parseFloat(item.valor) || 0;
            const sal = parseFloat(item.saldo_apos)    || 0;
            const dt  = item.data_lancamento || item.data_vencimento || '';
            return `<tr>
              <td style="white-space:nowrap;">${_fmtData(dt)}</td>
              <td>${_badgeTipo(item.tipo_lancamento)}</td>
              <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${_esc(item.fornecedor_nome)}">${_esc(item.fornecedor_nome) || '—'}</td>
              <td class="imp-hist-cell" title="${_esc(item.historico_completo || item.observacao)}">${_esc(item.historico_completo || item.observacao) || '—'}</td>
              <td style="font-size:11px;color:#64748b;">${_esc(item.classificacao_despesa) || '—'}</td>
              <td style="text-align:right;">${ent > 0 ? '<span class="imp-val-ent">+ R$ ' + _fmtMoeda(ent) + '</span>' : '—'}</td>
              <td style="text-align:right;">${sai > 0 ? '<span class="imp-val-sai">- R$ ' + _fmtMoeda(sai) + '</span>' : '—'}</td>
              <td style="text-align:right;">${sal ? '<span class="imp-val-sal">R$ ' + _fmtMoeda(sal) + '</span>' : '—'}</td>
              <td>${_badgeStatus(item.status_importacao)}</td>
              <td>
                ${(item.status_importacao === 'PENDENTE' || item.status_importacao === 'DUPLICATA')
                  ? `<button class="btn btn-sm btn-secondary" onclick="ImpFin.ignorarItem(${item.id})" title="Ignorar"><i class="fas fa-ban"></i></button>`
                  : '—'}
              </td>
            </tr>`;
        }).join('');
    }

    function exportarCSV() {
        if (!_itensCacheVis.length) { alert('Nenhum dado para exportar.'); return; }
        const header = 'Data;Tipo;Fornecedor;Histórico;Categoria;Entrada;Saída;Saldo;Status';
        const linhas = _itensCacheVis.map(i => [
            i.data_lancamento || i.data_vencimento || '',
            i.tipo_lancamento || '',
            i.fornecedor_nome || '',
            i.historico_completo || i.observacao || '',
            i.classificacao_despesa || '',
            i.valor_entrada || '0',
            i.valor_saida || i.valor || '0',
            i.saldo_apos || '',
            i.status_importacao || ''
        ].map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';'));
        const csv  = '\uFEFF' + header + '\n' + linhas.join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = 'extrato_importado.csv'; a.click();
        URL.revokeObjectURL(url);
    }

    // ══════════════════════════════════════════════════════════
    // CONCILIAÇÃO
    // ══════════════════════════════════════════════════════════
    async function carregarConciliacao(lote_id) {
        if (!lote_id) return;
        const status = document.getElementById('imp_filtroStatusConc')?.value || 'DUPLICATA';
        const url    = `${API}?acao=listar_itens&lote_id=${lote_id}&status=${status}&por_pagina=200`;
        try {
            const resp = await fetch(url);
            const data = await resp.json();
            if (!data.sucesso) return;

            const painel = document.getElementById('imp_painelConciliacao');
            if (painel) painel.style.display = 'block';

            const itens = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);
            const totalEl = document.getElementById('imp_totalConc');
            if (totalEl) totalEl.textContent = `— ${itens.length} itens`;

            const tbody = document.getElementById('imp_tbodyConc');
            if (!tbody) return;
            if (!itens.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="empty-table">Nenhum item encontrado para este filtro</td></tr>';
                return;
            }
            tbody.innerHTML = itens.map(item => {
                const val = parseFloat(item.valor_saida) || parseFloat(item.valor_entrada) || parseFloat(item.valor) || 0;
                const dt  = item.data_lancamento || item.data_vencimento || '';
                return `<tr>
                  <td><input type="checkbox" class="imp-chk-item" value="${item.id}"></td>
                  <td style="white-space:nowrap;">${_fmtData(dt)}</td>
                  <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${_esc(item.fornecedor_nome)}">${_esc(item.fornecedor_nome) || '—'}</td>
                  <td class="imp-hist-cell" title="${_esc(item.historico_completo || item.observacao)}">${_esc(item.historico_completo || item.observacao) || '—'}</td>
                  <td style="text-align:right;font-weight:700;color:#dc2626;">R$ ${_fmtMoeda(val)}</td>
                  <td>${_badgeStatus(item.status_importacao)}</td>
                  <td style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="btn btn-sm btn-primary" onclick="ImpFin.conciliarItem(${item.id})"><i class="fas fa-check"></i> Conciliar</button>
                    <button class="btn btn-sm btn-secondary" onclick="ImpFin.ignorarItem(${item.id})"><i class="fas fa-ban"></i></button>
                  </td>
                </tr>`;
            }).join('');
        } catch (e) {
            console.error('[ImpFin] carregarConciliacao:', e);
        }
    }

    async function conciliarItem(item_id) {
        try {
            const resp = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'conciliar_item', item_id })
            });
            const data = await resp.json();
            if (data.sucesso) {
                const lote_id = document.getElementById('imp_seletorLoteCon')?.value;
                if (lote_id) carregarConciliacao(lote_id);
            }
        } catch (e) { console.error('[ImpFin] conciliarItem:', e); }
    }

    async function ignorarItem(item_id) {
        try {
            const resp = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'ignorar_item', item_id })
            });
            const data = await resp.json();
            if (data.sucesso) {
                const lv = document.getElementById('imp_seletorLoteVis')?.value;
                const lc = document.getElementById('imp_seletorLoteCon')?.value;
                if (lc) carregarConciliacao(lc);
                if (lv) carregarItens(lv);
            }
        } catch (e) { console.error('[ImpFin] ignorarItem:', e); }
    }

    function toggleCheckAll(cb) {
        document.querySelectorAll('.imp-chk-item').forEach(c => c.checked = cb.checked);
    }

    async function conciliarTodos() {
        const ids = [...document.querySelectorAll('.imp-chk-item:checked')].map(c => c.value);
        if (!ids.length) { alert('Selecione ao menos um item.'); return; }
        for (const id of ids) await conciliarItem(id);
    }

    async function ignorarTodos() {
        const ids = [...document.querySelectorAll('.imp-chk-item:checked')].map(c => c.value);
        if (!ids.length) { alert('Selecione ao menos um item.'); return; }
        for (const id of ids) await ignorarItem(id);
    }

    // ══════════════════════════════════════════════════════════
    // HISTÓRICO DE LOTES
    // ══════════════════════════════════════════════════════════
    async function carregarLotes() {
        try {
            const resp = await fetch(`${API}?acao=listar_lotes`);
            const data = await resp.json();
            if (!data.sucesso) return;

            _lotesCache = Array.isArray(data.dados) ? data.dados : [];

            // Atualizar seletores
            const opts = _lotesCache.map(l =>
                `<option value="${l.id}">${_esc(l.nome_arquivo)} — ${_fmtDataBR(l.data_importacao)}</option>`
            ).join('');
            const selVis = document.getElementById('imp_seletorLoteVis');
            const selCon = document.getElementById('imp_seletorLoteCon');
            if (selVis) selVis.innerHTML = '<option value="">— Selecione um lote —</option>' + opts;
            if (selCon) selCon.innerHTML = '<option value="">— Selecione um lote —</option>' + opts;

            if (document.getElementById('imp-tab-historico')?.classList.contains('active')) {
                _renderizarHistorico();
            }
        } catch (e) { console.error('[ImpFin] carregarLotes:', e); }
    }

    function _renderizarHistorico() {
        const container = document.getElementById('imp_listaLotes');
        if (!container) return;
        if (!_lotesCache.length) {
            container.innerHTML = '<div class="page-card" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:32px;margin-bottom:12px;display:block;"></i>Nenhum lote importado ainda</div>';
            return;
        }
        container.innerHTML = _lotesCache.map(l => {
            const ext   = (l.tipo_arquivo || 'CSV').toUpperCase();
            const saldo = parseFloat(l.saldo_final) || 0;
            return `<div class="imp-lote-card">
              <div class="imp-lote-icon imp-lote-icon-${ext.toLowerCase()}">
                <i class="fas fa-file-${ext === 'PDF' ? 'pdf' : 'csv'}"></i>
              </div>
              <div class="imp-lote-info">
                <div class="imp-lote-nome">${_esc(l.nome_arquivo)}</div>
                <div class="imp-lote-meta">
                  ${_fmtDataBR(l.data_importacao)} &bull; ${l.tipo_conta || '—'} &bull; ${l.formato_origem || 'genérico'}
                  ${l.data_inicio ? ` &bull; ${_fmtDataBR(l.data_inicio)} a ${_fmtDataBR(l.data_fim)}` : ''}
                  &bull; por ${_esc(l.usuario || 'sistema')}
                </div>
              </div>
              <div class="imp-lote-stats">
                <div class="imp-lote-stat"><div class="v">${_fmtNum(l.total_registros)}</div><div class="l">Registros</div></div>
                <div class="imp-lote-stat"><div class="v" style="color:#16a34a;">R$ ${_fmtMoeda(l.total_entradas)}</div><div class="l">Entradas</div></div>
                <div class="imp-lote-stat"><div class="v" style="color:#dc2626;">R$ ${_fmtMoeda(l.total_saidas)}</div><div class="l">Saídas</div></div>
                <div class="imp-lote-stat"><div class="v" style="color:${saldo >= 0 ? '#2563eb' : '#dc2626'};">R$ ${_fmtMoeda(Math.abs(saldo))}</div><div class="l">Saldo</div></div>
                <div class="imp-lote-stat"><div class="v" style="color:#d97706;">${_fmtNum(l.total_duplicatas)}</div><div class="l">Duplicatas</div></div>
              </div>
              <div class="imp-lote-acoes">
                <button class="btn btn-sm btn-primary" onclick="ImpFin.abrirLote(${l.id})">
                  <i class="fas fa-chart-bar"></i> Ver
                </button>
                <button class="btn btn-sm btn-danger" onclick="ImpFin.excluirLote(${l.id})">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>`;
        }).join('');
    }

    function abrirLote(lote_id) {
        _sincronizarSeletores(lote_id);
        mostrarTab('extrato', document.getElementById('imp_tabExtrato'));
        carregarResumo(lote_id);
    }

    async function excluirLote(lote_id) {
        if (!confirm('Excluir este lote e todos os seus itens? Esta ação não pode ser desfeita.')) return;
        try {
            const resp = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'excluir_lote', lote_id })
            });
            const data = await resp.json();
            if (data.sucesso) carregarLotes();
        } catch (e) { console.error('[ImpFin] excluirLote:', e); }
    }

    // ══════════════════════════════════════════════════════════
    // HELPERS INTERNOS
    // ══════════════════════════════════════════════════════════
    function _sincronizarSeletores(lote_id) {
        const selVis = document.getElementById('imp_seletorLoteVis');
        const selCon = document.getElementById('imp_seletorLoteCon');
        if (selVis) selVis.value = lote_id;
        if (selCon) selCon.value = lote_id;
    }

    function _setLoading(show, texto) {
        const wrap = document.getElementById('imp_progressWrap');
        const txt  = document.getElementById('imp_progressText');
        if (wrap) wrap.style.display = show ? 'block' : 'none';
        if (txt && texto) txt.textContent = texto;
    }

    function _renderizarPaginacao(id, atual, total, cb) {
        const el = document.getElementById(id);
        if (!el) return;
        if (total <= 1) { el.innerHTML = ''; return; }
        let html = `<button ${atual === 1 ? 'disabled' : ''} onclick="(${cb.toString()})(${atual - 1})">‹ Anterior</button>`;
        const ini = Math.max(1, atual - 2);
        const fim = Math.min(total, atual + 2);
        if (ini > 1) html += `<button onclick="(${cb.toString()})(1)">1</button>${ini > 2 ? '<span style=\'padding:0 4px;\'>…</span>' : ''}`;
        for (let i = ini; i <= fim; i++) {
            html += `<button class="${i === atual ? 'active' : ''}" onclick="(${cb.toString()})(${i})">${i}</button>`;
        }
        if (fim < total) html += `${fim < total - 1 ? '<span style=\'padding:0 4px;\'>…</span>' : ''}<button onclick="(${cb.toString()})(${total})">${total}</button>`;
        html += `<button ${atual === total ? 'disabled' : ''} onclick="(${cb.toString()})(${atual + 1})">Próximo ›</button>`;
        html += `<span class="imp-pag-info">Página ${atual} de ${total}</span>`;
        el.innerHTML = html;
    }

    function _badgeTipo(tipo) {
        const map   = { ENTRADA: 'badge-entrada', SAIDA: 'badge-saida', TARIFA: 'badge-tarifa', TRANSFERENCIA: 'badge-transferencia' };
        const icons = { ENTRADA: 'fa-arrow-up', SAIDA: 'fa-arrow-down', TARIFA: 'fa-university', TRANSFERENCIA: 'fa-exchange-alt' };
        if (!tipo) return '<span class="badge-imp badge-ignorado">—</span>';
        const cls  = map[tipo]   || 'badge-ignorado';
        const icon = icons[tipo] || 'fa-circle';
        return `<span class="badge-imp ${cls}"><i class="fas ${icon}"></i> ${tipo}</span>`;
    }

    function _badgeStatus(s) {
        const map = {
            PENDENTE: 'badge-pendente', IMPORTADO: 'badge-importado', DUPLICATA: 'badge-duplicata',
            IGNORADO: 'badge-ignorado', CONCILIADO: 'badge-conciliado', ERRO: 'badge-erro'
        };
        return `<span class="badge-imp ${map[s] || 'badge-ignorado'}">${s || '—'}</span>`;
    }

    function _fmtMoeda(v) {
        return (parseFloat(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function _fmtNum(v) {
        return parseInt(v || 0).toLocaleString('pt-BR');
    }

    function _fmtData(d) {
        if (!d) return '—';
        if (d.includes('/')) return d;
        const parts = d.split('-');
        if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
        return d;
    }

    function _fmtDataBR(d) {
        if (!d) return '—';
        try { return new Date(d).toLocaleDateString('pt-BR'); } catch(e) { return d; }
    }

    function _esc(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ══════════════════════════════════════════════════════════
    // OBJETO PÚBLICO
    // ══════════════════════════════════════════════════════════
    const pub = {
        init,
        mostrarTab,
        importarArquivo,
        confirmarImportacao,
        verExtrato,
        irConciliacao,
        limparForm,
        carregarResumo,
        carregarItens,
        filtrarItens,
        exportarCSV,
        carregarConciliacao,
        conciliarItem,
        ignorarItem,
        toggleCheckAll,
        conciliarTodos,
        ignorarTodos,
        carregarLotes,
        abrirLote,
        excluirLote
    };

    window.ImpFin = pub;
    // Compatibilidade com versão anterior
    window.ImportacaoFinanceira = pub;

    // Auto-inicializar
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
