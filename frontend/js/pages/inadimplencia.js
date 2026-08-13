const API = '../api/api_inadimplencia.php';
const DEBUG = true;
const log = (...args) => DEBUG && console.log('[Inadimplencia]', ...args);

let _abortController = null;
let _importacaoId = 0;
let _rankingPagina = 1;
let _rankingTimer = null;
let _dashboard = null;

export function init() {
    _abortController = new AbortController();
    _vincularEventos();
    log('Inicializando módulo financeiro de inadimplência.');
    _carregarDashboard();
}

export function destroy() {
    if (_abortController) _abortController.abort();
    clearTimeout(_rankingTimer);
    _abortController = null;
    _dashboard = null;
    log('Módulo destruído.');
}

function _vincularEventos() {
    const signal = _abortController.signal;
    document.querySelector('[data-nav-financeiro]')?.addEventListener('click', event => {
        event.preventDefault(); window.AppRouter?.navigate('financeiro');
    }, { signal });
    document.getElementById('inad-arquivo')?.addEventListener('change', event => {
        const arquivo = event.target.files?.[0];
        document.getElementById('inad-arquivo-nome').textContent = arquivo ? arquivo.name : 'Nenhum arquivo selecionado';
    }, { signal });
    document.getElementById('inad-form-importacao')?.addEventListener('submit', _importar, { signal });
    document.getElementById('inad-importacao-select')?.addEventListener('change', event => {
        _importacaoId = Number(event.target.value) || 0; _rankingPagina = 1; _carregarDashboard(_importacaoId);
    }, { signal });
    document.getElementById('inad-btn-filtrar')?.addEventListener('click', () => { _rankingPagina = 1; _carregarRanking(); }, { signal });
    document.getElementById('inad-busca')?.addEventListener('input', () => {
        clearTimeout(_rankingTimer); _rankingTimer = setTimeout(() => { _rankingPagina = 1; _carregarRanking(); }, 350);
    }, { signal });
    document.getElementById('inad-carteira-filtro')?.addEventListener('change', () => { _rankingPagina = 1; _carregarRanking(); }, { signal });
    document.getElementById('inad-ordem')?.addEventListener('change', () => { _rankingPagina = 1; _carregarRanking(); }, { signal });
    document.getElementById('inad-btn-csv')?.addEventListener('click', _exportarCsv, { signal });
    document.getElementById('inad-btn-pdf')?.addEventListener('click', () => window.print(), { signal });
    document.getElementById('inad-paginacao')?.addEventListener('click', event => {
        const btn = event.target.closest('[data-inad-pagina]'); if (!btn || btn.disabled) return;
        _rankingPagina = Number(btn.dataset.inadPagina) || 1; _carregarRanking();
    }, { signal });
    document.getElementById('inad-ranking-body')?.addEventListener('click', event => {
        const link = event.target.closest('[data-morador-id]'); if (!link) return;
        event.preventDefault(); _abrirMorador(Number(link.dataset.moradorId));
    }, { signal });
    document.getElementById('inad-historico-body')?.addEventListener('click', event => {
        const btn = event.target.closest('[data-importacao-id]'); if (!btn) return;
        _importacaoId = Number(btn.dataset.importacaoId) || 0; _rankingPagina = 1; _carregarDashboard(_importacaoId);
    }, { signal });
    document.getElementById('inad-modal')?.addEventListener('click', event => {
        if (event.target.id === 'inad-modal' || event.target.closest('[data-inad-close]')) _fecharModal();
    }, { signal });
}

async function _api(acao, options = {}) {
    log('API solicitada:', acao, options.method || 'GET', options.params || {});
    const url = new URL(API, window.location.href);
    if (!options.method || options.method === 'GET') {
        url.searchParams.set('acao', acao);
        Object.entries(options.params || {}).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, value);
        });
    }
    const response = await fetch(url.toString(), {
        method: options.method || 'GET', body: options.body, credentials: 'include', signal: _abortController?.signal,
        headers: options.headers || {}
    });
    const texto = await response.text();
    let data; try { data = JSON.parse(texto); } catch { throw new Error('A API respondeu em formato inválido.'); }
    if (!response.ok || !data.sucesso) {
        log('API com falha:', acao, response.status, data.mensagem || 'sem mensagem');
        throw new Error(data.mensagem || `Falha HTTP ${response.status}`);
    }
    log('API concluída:', acao);
    return data.dados;
}

async function _carregarDashboard(id = 0) {
    _setLoading(true);
    try {
        _dashboard = await _api('dashboard', { params: id ? { importacao_id: id } : {} });
        log('Dashboard carregado:', { tem_dados: _dashboard.tem_dados, importacao: _dashboard.importacao_atual?.id || null });
        if (!_dashboard.tem_dados) { _mostrarVazio(true); return; }
        _mostrarVazio(false);
        _importacaoId = Number(_dashboard.importacao_atual.id);
        _renderDashboard(_dashboard);
        await _carregarRanking();
    } catch (erro) {
        if (erro.name !== 'AbortError') _flash(erro.message, 'error');
    } finally { _setLoading(false); }
}

async function _importar(event) {
    event.preventDefault();
    const input = document.getElementById('inad-arquivo');
    const arquivo = input?.files?.[0];
    if (!arquivo) { _flash('Selecione o PDF de Inadimplência Detalhado antes de importar.', 'error'); return; }
    const botao = document.getElementById('inad-btn-importar');
    botao.disabled = true; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importando e analisando...';
    try {
        const form = new FormData(); form.append('arquivo', arquivo);
        const dados = await _api('importar', { method: 'POST', body: form });
        input.value = ''; document.getElementById('inad-arquivo-nome').textContent = 'Nenhum arquivo selecionado';
        const feedback = document.getElementById('inad-import-feedback');
        feedback.hidden = false;
        feedback.innerHTML = `<strong>Importação concluída.</strong> Data base: ${_esc(_fmtData(dados.data_base))} · ${_fmtNumero(dados.quantidade_unidades)} unidades · ${_fmtMoeda(dados.total_projetado)} projetados · ${_fmtNumero(dados.total_lancamentos)} lançamentos.${dados.totais_reconciliam ? ' Totais conciliados.' : ' Atenção: os totais do Resumo divergiram e foram sinalizados.'}`;
        _flash('Relatório processado e salvo como novo snapshot.', 'success');
        _importacaoId = Number(dados.importacao_id); _rankingPagina = 1;
        await _carregarDashboard(_importacaoId);
    } catch (erro) { _flash(erro.message, 'error'); }
    finally { botao.disabled = false; botao.innerHTML = '<i class="fas fa-upload"></i> Importar e analisar'; }
}

function _renderDashboard(dados) {
    const atual = dados.importacao_atual;
    _setText('inad-kpi-total', _fmtMoeda(dados.kpis.total_projetado));
    _setText('inad-kpi-data', `Snapshot de ${_fmtData(atual.data_base)}`);
    _setText('inad-kpi-unidades', _fmtNumero(dados.kpis.unidades));
    _setText('inad-kpi-judicial', _fmtNumero(dados.kpis.judiciais));
    const variacao = Number(dados.kpis.variacao || 0); const pct = dados.kpis.variacao_pct;
    _setText('inad-kpi-variacao', `${variacao >= 0 ? '+' : ''}${_fmtMoeda(variacao)}`);
    _setText('inad-kpi-variacao-desc', pct === null ? 'Primeiro snapshot importado' : `${variacao >= 0 ? 'Aumento' : 'Redução'} de ${Math.abs(Number(pct)).toFixed(1).replace('.', ',')}%`);
    document.getElementById('inad-kpi-variacao-icone').className = variacao > 1 ? 'fas fa-arrow-trend-up' : variacao < -1 ? 'fas fa-arrow-trend-down' : 'fas fa-minus';
    _renderAlertas(atual, dados.kpis.sem_vinculo);
    _renderCarteiras(dados.carteiras || []); _renderHistoricoGrafico(dados.historico || []); _renderMudancas(dados.mudancas || {});
    _renderHeuristica(dados.heuristica || {}); _renderSelectImportacoes(dados.historico || [], atual.id); _renderHistorico(dados.historico || [], atual.id);
    _setText('inad-comparacao-data', dados.importacao_anterior ? `Comparado a ${_fmtData(dados.importacao_anterior.data_base)}` : 'Aguardando o próximo snapshot');
}

function _renderAlertas(atual, semVinculo) {
    const rec = document.getElementById('inad-alerta-reconciliacao'); const vin = document.getElementById('inad-alerta-vinculos');
    rec.hidden = Boolean(Number(atual.totais_reconciliam));
    _setText('inad-alerta-reconciliacao-texto', atual.alerta_reconciliacao || 'Revise os lançamentos antes de usar estes indicadores.');
    vin.hidden = !Number(semVinculo); _setText('inad-alerta-vinculos-texto', `${_fmtNumero(semVinculo)} lançamento(s) ainda não foram associados a morador ou unidade.`);
}
function _renderCarteiras(items) {
    const el = document.getElementById('inad-carteiras'); const max = Math.max(...items.map(i => Number(i.total)), 1);
    el.innerHTML = items.length ? items.map(i => `<div class="inad-bar-row"><span class="inad-bar-label" title="${_esc(i.carteira)}">${_esc(i.carteira)}</span><span class="inad-bar-track"><span class="inad-bar-fill" style="width:${Math.max(2,(Number(i.total)/max)*100)}%"></span></span><span class="inad-bar-value">${_fmtMoeda(i.total)}</span></div>`).join('') : '<p class="inad-chart-empty">Sem dados de carteira.</p>';
}
function _renderHistoricoGrafico(historico) {
    const el = document.getElementById('inad-historico-grafico');
    if (!historico.length) { el.innerHTML = '<p class="inad-chart-empty">Importe relatórios em períodos diferentes para visualizar a evolução.</p>'; return; }
    const w=620,h=180,p=24; const vals=historico.map(i=>Number(i.total_projetado)); const min=Math.min(...vals),max=Math.max(...vals),span=max-min||1;
    const pts=historico.map((item,index)=>{const x=p+(historico.length===1?(w-2*p)/2:index*(w-2*p)/(historico.length-1));const y=h-p-((Number(item.total_projetado)-min)/span)*(h-2*p);return{x,y,item};});
    const path=pts.map((q,i)=>`${i?'L':'M'}${q.x.toFixed(1)},${q.y.toFixed(1)}`).join(' '); const area=`M${pts[0].x},${h-p} ${pts.map(q=>`L${q.x.toFixed(1)},${q.y.toFixed(1)}`).join(' ')} L${pts[pts.length-1].x},${h-p} Z`;
    const labels=pts.map(q=>`<text class="inad-chart-label" x="${q.x}" y="${h-4}" text-anchor="middle">${_esc(_fmtData(q.item.data_base).slice(0,5))}</text>`).join(''); const dots=pts.map(q=>`<circle class="inad-chart-dot" cx="${q.x}" cy="${q.y}" r="4"><title>${_esc(_fmtData(q.item.data_base))}: ${_fmtMoeda(q.item.total_projetado)}</title></circle>`).join('');
    el.innerHTML=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Evolução do total projetado"><line class="inad-chart-grid" x1="${p}" y1="${p}" x2="${w-p}" y2="${p}"></line><line class="inad-chart-grid" x1="${p}" y1="${h/2}" x2="${w-p}" y2="${h/2}"></line><line class="inad-chart-grid" x1="${p}" y1="${h-p}" x2="${w-p}" y2="${h-p}"></line><path class="inad-chart-area" d="${area}"></path><path class="inad-chart-line" d="${path}"></path>${dots}${labels}<text class="inad-chart-label" x="${p}" y="14">${_fmtMoeda(max)}</text></svg>`;
}
function _renderMudancas(mudancas) { _renderListaMudancas('inad-mudancas-novo', mudancas.NOVO, 'Nenhuma nova unidade'); _renderListaMudancas('inad-mudancas-evoluindo', mudancas.EVOLUINDO, 'Nenhuma piora relevante'); _renderListaMudancas('inad-mudancas-quitado', [...(mudancas.QUITADO||[]),...(mudancas.CORRIGIDO||[])], 'Nenhuma regularização identificada'); }
function _renderListaMudancas(id, lista, vazio) { const el=document.getElementById(id); const itens=(lista||[]).slice(0,5); el.innerHTML=itens.length?itens.map(i=>`<li><strong>Gleba ${_esc(i.gleba_numero)}</strong><span>${i.delta>=0?'+':''}${_fmtMoeda(i.delta)}</span></li>`).join(''):`<li><span>${_esc(vazio)}</span></li>`; }
function _renderHeuristica(h) { _setText('inad-heuristica-texto', h.mensagem || '—'); const el=document.getElementById('inad-risco-alto'); const itens=h.risco_alto||[]; el.innerHTML=itens.length?itens.map(i=>`<span class="inad-risk-chip"><i class="fas fa-exclamation-circle"></i> Gleba ${_esc(i.gleba_numero)} · ${_fmtMoeda(i.delta)}</span>`).join(''):'<span class="inad-risk-empty">Nenhuma unidade atingiu o critério de duas evoluções consecutivas.</span>'; }
function _renderSelectImportacoes(itens, atualId) { const select=document.getElementById('inad-importacao-select'); select.innerHTML=itens.slice().reverse().map(i=>`<option value="${i.id}">${_esc(_fmtData(i.data_base))} · ${_fmtMoeda(i.total_projetado)}</option>`).join(''); select.value=String(atualId); }
function _renderHistorico(itens, atualId) { const el=document.getElementById('inad-historico-body'); const rows=itens.slice().reverse(); el.innerHTML=rows.map(i=>`<tr${Number(i.id)===Number(atualId)?' class="row-active"':''}><td>${_esc(_fmtData(i.data_base))}</td><td>${_esc(i.nome_arquivo||'Relatório BRCondos')}</td><td>${_fmtNumero(i.quantidade_unidades)}</td><td>${_fmtMoeda(i.total_lancado)}</td><td><strong>${_fmtMoeda(i.total_projetado)}</strong></td><td>${Number(i.totais_reconciliam)?'<span class="inad-validation-ok"><i class="fas fa-check-circle"></i> Conciliado</span>':'<span class="inad-validation-warn"><i class="fas fa-exclamation-triangle"></i> Revisar</span>'}</td><td><button type="button" class="inad-history-btn" data-importacao-id="${i.id}">Revisar</button></td></tr>`).join('')||'<tr><td colspan="7" class="empty-table">Nenhum snapshot encontrado.</td></tr>'; }

async function _carregarRanking() {
    if (!_importacaoId) return; const busca=document.getElementById('inad-busca')?.value||''; const carteira=document.getElementById('inad-carteira-filtro')?.value||''; const ordem=document.getElementById('inad-ordem')?.value||'divida_desc';
    const body=document.getElementById('inad-ranking-body'); body.innerHTML='<tr><td colspan="7" class="empty-table"><i class="fas fa-spinner fa-spin"></i> Carregando ranking...</td></tr>';
    try { const dados=await _api('ranking',{params:{importacao_id:_importacaoId,busca,carteira,ordem,pagina:_rankingPagina,por_pagina:25}}); log('Ranking carregado:', { total: dados.total, pagina: dados.pagina }); _renderRanking(dados); }
    catch(erro){if(erro.name!=='AbortError'){body.innerHTML=`<tr><td colspan="7" class="empty-table">${_esc(erro.message)}</td></tr>`;}}
}
function _renderRanking(dados) { const body=document.getElementById('inad-ranking-body'); const itens=dados.itens||[]; body.innerHTML=itens.length?itens.map(i=>`<tr><td><strong>Gleba ${_esc(i.gleba_numero)}</strong></td><td>${i.morador_id?`<a href="#" class="inad-link-morador" data-morador-id="${i.morador_id}">${_esc(i.morador_nome||i.proprietario_nome||'Morador vinculado')}</a>`:_esc(i.proprietario_nome||'Não identificado')}<br><small>${_esc(i.proprietario_cpf||'CPF não informado')}</small></td><td><span class="inad-status ${(Number(i.permite_receber)===0||String(i.carteira_status).includes('JUDICIAL'))?'judicial':''}">${_esc(i.carteira_status||'RECEBER')}</span></td><td>${_fmtNumero(i.meses_aberto)}</td><td><strong>${_fmtMoeda(i.total_projetado)}</strong></td><td>${i.morador_id||i.unidade_id?'<span class="inad-validation-ok"><i class="fas fa-link"></i> Vinculado</span>':'<span class="inad-flag-sem-vinculo"><i class="fas fa-unlink"></i> Revisar</span>'}</td><td>${i.morador_id?`<a href="#" class="inad-link-morador" data-morador-id="${i.morador_id}">Abrir ficha</a>`:'—'}</td></tr>`).join(''):'<tr><td colspan="7" class="empty-table">Nenhuma unidade encontrada para os filtros atuais.</td></tr>'; const p=Number(dados.pagina||1),t=Number(dados.total_paginas||1); document.getElementById('inad-paginacao').innerHTML=`<button type="button" data-inad-pagina="${p-1}" ${p<=1?'disabled':''}>Anterior</button><span>Página ${p} de ${t} · ${_fmtNumero(dados.total||0)} unidades</span><button type="button" data-inad-pagina="${p+1}" ${p>=t?'disabled':''}>Próxima</button>`; }
function _exportarCsv(){ if(!_importacaoId){_flash('Importe ou selecione um snapshot antes de exportar.','info');return;} window.location.href=`${API}?acao=exportar_csv&importacao_id=${encodeURIComponent(_importacaoId)}`; }
function _abrirMorador(id){ if(!id)return; log('Abrindo ficha do morador vinculado:',id); window.AppRouter?.navigate('moradores'); let tentativas=0; const abrir=()=>{ if(window.MoradoresPage?.editar){window.MoradoresPage.editar(id);return;} if(++tentativas<20)setTimeout(abrir,120); else _flash('A página de Moradores foi aberta, mas a ficha não respondeu a tempo.','info'); }; setTimeout(abrir,180); }
function _fecharModal(){const m=document.getElementById('inad-modal');m.hidden=true;document.getElementById('inad-modal-body').innerHTML='';}
function _mostrarVazio(vazio){document.getElementById('inad-empty').hidden=!vazio;document.getElementById('inad-dashboard').hidden=vazio;}
function _setLoading(loading){document.querySelector('.page-inadimplencia')?.classList.toggle('is-loading',loading);}
function _flash(msg,tipo='info'){const el=document.getElementById('inad-flash');if(!el)return;el.hidden=false;el.className=`inad-flash ${tipo}`;el.textContent=msg;clearTimeout(el._timer);el._timer=setTimeout(()=>{el.hidden=true;},6500);}
function _setText(id,text){const e=document.getElementById(id);if(e)e.textContent=text;}
function _fmtMoeda(v){return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v||0));}
function _fmtNumero(v){return new Intl.NumberFormat('pt-BR').format(Number(v||0));}
function _fmtData(v){if(!v)return'—';const p=String(v).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:String(v);}
function _esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML;}
