/**
 * ============================================================
 * LOGS FINANCEIRO — logs_financeiro.js
 * ============================================================
 * Painel de diagnóstico do módulo financeiro.
 * Objeto público: window.LogsFin
 * @version 1.0.0
 */
(function () {
    'use strict';

    const API = '../api/api_logs_financeiro.php';

    let _pagina    = 1;
    let _totalPag  = 1;
    let _cache     = [];

    // ── Init ─────────────────────────────────────────────────
    function init() {
        console.debug('[LogsFin] init v1.0');

        // Datas padrão: últimos 7 dias
        const hoje = new Date();
        const ini  = new Date(hoje); ini.setDate(hoje.getDate() - 7);
        const di   = document.getElementById('lf_filtroDataIni');
        const df   = document.getElementById('lf_filtroDataFim');
        if (di) di.value = ini.toISOString().split('T')[0];
        if (df) df.value = hoje.toISOString().split('T')[0];

        carregarResumo();
        filtrar();
    }

    // ── Resumo / KPIs ─────────────────────────────────────────
    async function carregarResumo() {
        try {
            const resp = await fetch(`${API}?acao=resumo`);
            const data = await resp.json();
            if (!data.sucesso) return;
            const d = data.dados;

            // KPIs 24h
            const k24 = d.kpis_24h || {};
            const k7d = d.kpis_7d  || {};
            _setEl('lf_kTotal',   _fmtNum(Object.values(k7d).reduce((a, b) => a + b, 0)));
            _setEl('lf_kCritico', _fmtNum(k24['CRITICO'] || 0));
            _setEl('lf_kErro',    _fmtNum(k24['ERRO']    || 0));
            _setEl('lf_kAviso',   _fmtNum(k24['AVISO']   || 0));
            _setEl('lf_kInfo',    _fmtNum(k24['INFO']    || 0));
            _setEl('lf_kDebug',   _fmtNum(k24['DEBUG']   || 0));

            // Por módulo
            const modEl = document.getElementById('lf_porModulo');
            if (modEl) {
                if (!d.por_modulo?.length) {
                    modEl.innerHTML = '<div style="color:#94a3b8;font-size:13px;grid-column:1/-1;">Nenhum log nos últimos 7 dias</div>';
                } else {
                    modEl.innerHTML = d.por_modulo.map(m => {
                        const erros = parseInt(m.erros) + parseInt(m.criticos);
                        const cor   = erros > 0 ? '#dc2626' : (parseInt(m.avisos) > 0 ? '#d97706' : '#16a34a');
                        return `<div class="log-modulo-card" style="border-left-color:${cor};">
                          <div class="lm-nome"><i class="fas fa-cube"></i> ${_esc(m.modulo)}</div>
                          <div class="lm-stats">
                            ${parseInt(m.criticos) > 0 ? `<span class="lm-stat" style="background:#fef2f2;color:#7c2d12;">${m.criticos} críticos</span>` : ''}
                            ${parseInt(m.erros)    > 0 ? `<span class="lm-stat" style="background:#fef2f2;color:#dc2626;">${m.erros} erros</span>` : ''}
                            ${parseInt(m.avisos)   > 0 ? `<span class="lm-stat" style="background:#fffbeb;color:#d97706;">${m.avisos} avisos</span>` : ''}
                            <span class="lm-stat" style="background:#eff6ff;color:#2563eb;">${m.total} total</span>
                          </div>
                          <div class="lm-ultimo">Último: ${_fmtDataHora(m.ultimo)}</div>
                        </div>`;
                    }).join('');
                }
            }

            // Erros recentes
            const erEl = document.getElementById('lf_errosRecentes');
            if (erEl) {
                if (!d.erros_recentes?.length) {
                    erEl.innerHTML = '<div style="color:#16a34a;font-size:13px;text-align:center;padding:20px;"><i class="fas fa-check-circle"></i> Nenhum erro nas últimas 24h</div>';
                } else {
                    erEl.innerHTML = d.erros_recentes.map(e => `
                      <div class="log-erro-card">
                        <div class="le-header">
                          ${_badgeNivel(e.nivel)}
                          <span style="font-size:11px;color:#64748b;">${_esc(e.modulo)} › ${_esc(e.acao || '—')}</span>
                          <span style="font-size:10px;color:#94a3b8;margin-left:auto;">${_fmtDataHora(e.criado_em)}</span>
                        </div>
                        <div class="le-msg">${_esc(e.mensagem)}</div>
                        <div class="le-meta">Usuário: ${_esc(e.usuario || 'sistema')}</div>
                      </div>`).join('');
                }
            }

            // Timeline
            const tlEl = document.getElementById('lf_timeline');
            if (tlEl) {
                if (!d.timeline?.length) {
                    tlEl.innerHTML = '<div style="color:#94a3b8;font-size:13px;">Nenhuma atividade nas últimas 24h</div>';
                } else {
                    const maxTotal = Math.max(...d.timeline.map(t => parseInt(t.total) || 0), 1);
                    tlEl.innerHTML = d.timeline.map(t => {
                        const pctE = Math.round(((parseInt(t.erros)  || 0) / maxTotal) * 100);
                        const pctA = Math.round(((parseInt(t.avisos) || 0) / maxTotal) * 100);
                        return `<div class="log-timeline-item">
                          <div class="lt-hora">${_esc(t.hora)}</div>
                          <div class="lt-bar">
                            ${pctA > 0 ? `<div class="lt-fill-a" style="height:${pctA}%;"></div>` : ''}
                            ${pctE > 0 ? `<div class="lt-fill-e" style="height:${pctE}%;"></div>` : ''}
                          </div>
                          <div class="lt-total">${t.total}</div>
                        </div>`;
                    }).join('');
                }
            }

        } catch (e) {
            console.error('[LogsFin] carregarResumo:', e);
        }
    }

    // ── Listar logs ───────────────────────────────────────────
    async function filtrar(pagina) {
        _pagina = pagina || 1;
        const modulo   = document.getElementById('lf_filtroModulo')?.value   || '';
        const nivel    = document.getElementById('lf_filtroNivel')?.value    || '';
        const data_ini = document.getElementById('lf_filtroDataIni')?.value  || '';
        const data_fim = document.getElementById('lf_filtroDataFim')?.value  || '';
        const busca    = document.getElementById('lf_filtroBusca')?.value    || '';

        const url = `${API}?acao=listar&pagina=${_pagina}&por_pagina=50`
            + (modulo   ? `&modulo=${encodeURIComponent(modulo)}`     : '')
            + (nivel    ? `&nivel=${encodeURIComponent(nivel)}`       : '')
            + (data_ini ? `&data_ini=${encodeURIComponent(data_ini)}` : '')
            + (data_fim ? `&data_fim=${encodeURIComponent(data_fim)}` : '')
            + (busca    ? `&busca=${encodeURIComponent(busca)}`       : '');

        const tbody = document.getElementById('lf_tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="empty-table"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';

        try {
            const resp = await fetch(url);
            const data = await resp.json();
            if (!data.sucesso) return;

            const d    = data.dados;
            _cache     = d.itens || [];
            _totalPag  = d.paginas || 1;
            _pagina    = d.pagina  || 1;

            const totalEl = document.getElementById('lf_totalLogs');
            if (totalEl) totalEl.textContent = `— ${_fmtNum(d.total)} registros`;

            _renderTabela(_cache);
            _renderPaginacao();

        } catch (e) {
            console.error('[LogsFin] filtrar:', e);
            if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="empty-table text-danger">Erro ao carregar logs</td></tr>';
        }
    }

    function _renderTabela(itens) {
        const tbody = document.getElementById('lf_tbody');
        if (!tbody) return;
        if (!itens.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="empty-table"><i class="fas fa-inbox"></i> Nenhum log encontrado para os filtros selecionados</td></tr>';
            return;
        }
        tbody.innerHTML = itens.map((item, idx) => {
            const rowCls = item.nivel === 'CRITICO' ? 'row-critico' : (item.nivel === 'ERRO' ? 'row-erro' : (item.nivel === 'AVISO' ? 'row-aviso' : ''));
            const temDetalhe = !!(item.detalhe || item.post_data || item.request_uri);
            return `<tr class="${rowCls}">
              <td style="white-space:nowrap;font-size:11px;">${_fmtDataHora(item.criado_em)}</td>
              <td><span style="font-size:11px;font-weight:600;color:#1e3a8a;">${_esc(item.modulo)}</span></td>
              <td>${_badgeNivel(item.nivel)}</td>
              <td style="font-size:11px;color:#64748b;">${_esc(item.acao || '—')}</td>
              <td>
                <div style="font-size:12px;font-weight:${item.nivel === 'ERRO' || item.nivel === 'CRITICO' ? '600' : '400'};">${_esc(item.mensagem)}</div>
                ${temDetalhe ? `
                  <button class="log-detalhe-btn" onclick="LogsFin.toggleDetalhe(${idx})">
                    <i class="fas fa-code"></i> ver detalhe
                  </button>
                  <div id="lf_det_${idx}" class="log-detalhe-box">${_buildDetalhe(item)}</div>` : ''}
              </td>
              <td style="font-size:11px;">${_esc(item.usuario || '—')}</td>
              <td style="font-size:11px;color:#94a3b8;">${_esc(item.ip || '—')}</td>
              <td style="text-align:right;font-size:11px;">${item.duracao_ms != null ? item.duracao_ms + 'ms' : '—'}</td>
              <td>
                <button class="btn btn-sm btn-secondary" style="padding:2px 6px;" onclick="LogsFin.copiarLog(${idx})" title="Copiar log">
                  <i class="fas fa-copy"></i>
                </button>
              </td>
            </tr>`;
        }).join('');
    }

    function _buildDetalhe(item) {
        let txt = '';
        if (item.detalhe)      txt += '=== DETALHE ===\n' + item.detalhe + '\n\n';
        if (item.post_data)    txt += '=== POST DATA ===\n' + item.post_data + '\n\n';
        if (item.request_uri)  txt += '=== URI ===\n' + item.request_method + ' ' + item.request_uri + '\n\n';
        if (item.user_agent)   txt += '=== USER AGENT ===\n' + item.user_agent;
        return _esc(txt.trim());
    }

    function toggleDetalhe(idx) {
        const el = document.getElementById('lf_det_' + idx);
        if (el) el.classList.toggle('open');
    }

    function copiarLog(idx) {
        const item = _cache[idx];
        if (!item) return;
        const txt = `[${item.criado_em}] [${item.nivel}] [${item.modulo}] ${item.acao || ''}: ${item.mensagem}${item.detalhe ? '\n' + item.detalhe : ''}`;
        navigator.clipboard?.writeText(txt).then(() => {
            const btn = document.querySelectorAll('[onclick*="copiarLog(' + idx + ')"]')[0];
            if (btn) { btn.innerHTML = '<i class="fas fa-check"></i>'; setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500); }
        });
    }

    function _renderPaginacao() {
        const el = document.getElementById('lf_paginacao');
        if (!el) return;
        if (_totalPag <= 1) { el.innerHTML = ''; return; }

        let html = `<button ${_pagina === 1 ? 'disabled' : ''} onclick="LogsFin.filtrar(${_pagina - 1})">‹ Anterior</button>`;
        const ini = Math.max(1, _pagina - 2);
        const fim = Math.min(_totalPag, _pagina + 2);
        if (ini > 1) html += `<button onclick="LogsFin.filtrar(1)">1</button>${ini > 2 ? '<span style="padding:0 4px;">…</span>' : ''}`;
        for (let i = ini; i <= fim; i++) {
            html += `<button class="${i === _pagina ? 'active' : ''}" onclick="LogsFin.filtrar(${i})">${i}</button>`;
        }
        if (fim < _totalPag) html += `${fim < _totalPag - 1 ? '<span style="padding:0 4px;">…</span>' : ''}<button onclick="LogsFin.filtrar(${_totalPag})">${_totalPag}</button>`;
        html += `<button ${_pagina === _totalPag ? 'disabled' : ''} onclick="LogsFin.filtrar(${_pagina + 1})">Próximo ›</button>`;
        html += `<span class="pag-info">Página ${_pagina} de ${_totalPag}</span>`;
        el.innerHTML = html;
    }

    // ── Limpar filtros ────────────────────────────────────────
    function limparFiltros() {
        ['lf_filtroModulo','lf_filtroNivel','lf_filtroBusca'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        filtrar(1);
    }

    // ── Limpar logs antigos ───────────────────────────────────
    async function limparLogs() {
        if (!confirm('Remover todos os logs com mais de 30 dias?')) return;
        try {
            const resp = await fetch(`${API}?acao=limpar&dias=30`);
            const data = await resp.json();
            alert(data.mensagem || (data.sucesso ? 'Logs removidos.' : 'Erro.'));
            if (data.sucesso) { carregarResumo(); filtrar(1); }
        } catch (e) { alert('Erro: ' + e.message); }
    }

    // ── Exportar CSV ──────────────────────────────────────────
    function exportarCSV() {
        if (!_cache.length) { alert('Nenhum dado para exportar.'); return; }
        const header = 'ID;Data;Módulo;Nível;Ação;Mensagem;Usuário;IP;Duração(ms)';
        const linhas = _cache.map(i => [
            i.id, i.criado_em, i.modulo, i.nivel, i.acao || '',
            i.mensagem, i.usuario || '', i.ip || '', i.duracao_ms || ''
        ].map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';'));
        const csv  = '\uFEFF' + header + '\n' + linhas.join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = 'logs_financeiro.csv'; a.click();
        URL.revokeObjectURL(url);
    }

    // ── Helpers ───────────────────────────────────────────────
    function _badgeNivel(n) {
        const map = {
            CRITICO: 'bl-critico', ERRO: 'bl-erro', AVISO: 'bl-aviso',
            INFO: 'bl-info', DEBUG: 'bl-debug'
        };
        const icons = {
            CRITICO: 'fa-skull-crossbones', ERRO: 'fa-times-circle',
            AVISO: 'fa-exclamation-triangle', INFO: 'fa-info-circle', DEBUG: 'fa-bug'
        };
        const cls  = map[n]   || 'bl-debug';
        const icon = icons[n] || 'fa-circle';
        return `<span class="badge-log ${cls}"><i class="fas ${icon}"></i> ${n || '—'}</span>`;
    }

    function _fmtDataHora(d) {
        if (!d) return '—';
        try {
            return new Date(d).toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', year:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit' });
        } catch(e) { return d; }
    }

    function _fmtNum(v) { return parseInt(v || 0).toLocaleString('pt-BR'); }

    function _setEl(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    function _esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Objeto público ────────────────────────────────────────
    window.LogsFin = {
        carregarResumo,
        filtrar,
        toggleDetalhe,
        copiarLog,
        limparFiltros,
        limparLogs,
        exportarCSV
    };

    // Auto-init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
