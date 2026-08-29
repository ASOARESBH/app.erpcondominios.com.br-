const API = '/api/api_rondas_vigilante.php';
const state = { tenantId: 0, rotas: [], colaboradores: [], report: [], timer: null, tab: 'dashboard', salvandoRota: false, carregandoDashboard: false };
const $ = (selector) => document.querySelector(selector);
const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[c]));
const hoje = () => new Date().toISOString().slice(0, 10);
const log = (...dados) => console.debug('[Vigilante]', ...dados);
const fmtDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('pt-BR') : '—';

function toast(message, type = 'success') {
    const existing = document.getElementById('rv-toast');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.id = 'rv-toast';
    el.textContent = message;
    el.style.cssText = `position:fixed;right:22px;bottom:22px;z-index:11000;padding:.8rem 1rem;border-radius:9px;color:#fff;font:600 .86rem system-ui;background:${type==='error'?'#dc2626':type==='warn'?'#d97706':'#059669'};box-shadow:0 8px 25px rgba(15,23,42,.2)`;
    document.body.appendChild(el); setTimeout(() => el.remove(), 4200);
}

async function request(action, data = null, method = 'GET') {
    log('API', method, action, data || {});
    const url = method === 'GET' ? `${API}?${new URLSearchParams({ acao: action, ...(data || {}) })}` : `${API}?acao=${encodeURIComponent(action)}`;
    try {
        const response = await fetch(url, { method, credentials:'include', headers: method === 'POST' ? {'Content-Type':'application/json'} : {}, body: method === 'POST' ? JSON.stringify(data || {}) : null });
        const text = await response.text(); let json;
        try { json = text ? JSON.parse(text) : null; } catch (_) { return { sucesso:false, mensagem:`Erro HTTP ${response.status}: resposta inválida.` }; }
        return json || { sucesso:false, mensagem:'A API não retornou dados.' };
    } catch (error) { console.error('[Vigilante]', action, error); return { sucesso:false, mensagem:'Falha de comunicação com o módulo Vigilante.' }; }
}
function modal(id, open) { const el = document.getElementById(id); if (el) { el.classList.toggle('open', open); el.setAttribute('aria-hidden', open ? 'false' : 'true'); } }
function diasNome(dias) { const n=['Dom','Seg','Ter','Qua','Qui','Sex','Sáb']; return (dias || []).map((d)=>n[Number(d)]).filter(Boolean).join(', ') || 'Todos os dias'; }
function qrUrl(token) { return `${window.location.origin}/frontend/ronda_checkin.html?token=${encodeURIComponent(token)}`; }

async function carregarContexto() {
    const ctx = await request('contexto');
    if (!ctx.sucesso) { $('#rv-contexto-nome').textContent = ctx.mensagem || 'Não foi possível identificar o condomínio da sessão.'; toast(ctx.mensagem, 'error'); return; }
    state.tenantId = Number(ctx.dados.id);
    $('#rv-contexto-nome').textContent = ctx.dados.nome_fantasia || ctx.dados.razao_social || 'Condomínio ativo';
    await carregarTudo();
}
async function carregarTudo() { await Promise.all([carregarDashboard(), carregarColaboradores()]); }
async function carregarColaboradores() { const res = await request('listar_colaboradores'); state.colaboradores = res.sucesso ? (res.dados || []) : []; preencherFiltros(); }
async function carregarDashboard() {
    if (state.carregandoDashboard) { log('Dashboard já está em carregamento; requisição ignorada.'); return; }
    state.carregandoDashboard = true;
    try {
        const res = await request('dashboard');
        if (!res.sucesso) { toast(res.mensagem, 'error'); return; }
        state.rotas = res.dados.rotas || []; const k = res.dados.kpis || {};
        log('Dashboard carregado', { rotas: state.rotas.length, alertas: (res.dados.alertas || []).length, kpis: k });
        $('#rv-kpi-rotas').textContent = k.rotas_ativas ?? 0; $('#rv-kpi-vigilantes').textContent = k.vigilantes ?? 0; $('#rv-kpi-leituras').textContent = k.leituras_hoje ?? 0; $('#rv-kpi-atrasos').textContent = k.atrasos_hoje ?? 0;
        renderAlertas(res.dados.alertas || []); renderRotasDashboard(); renderRotas(); renderQrs(); preencherFiltros();
    } finally {
        state.carregandoDashboard = false;
    }
}
function renderAlertas(alertas) {
    const el = $('#rv-alertas'); if (!el) return;
    if (!alertas.length) { el.innerHTML = '<div class="rv-ok"><i class="fas fa-circle-check"></i> Nenhum atraso de SLA identificado nas rotas ativas.</div>'; return; }
    el.innerHTML = alertas.map((a)=>`<div class="rv-alert"><i class="fas fa-triangle-exclamation"></i><div><strong>${esc(a.rota)} — ${esc(a.vigilante)}</strong><span>${Number(a.pontos_feitos)}/${Number(a.pontos_total)} ponto(s) no ciclo. Atraso atual: ${Number(a.atraso_minutos)} min. Previsto: ${fmtDate(a.previsto_em)}.</span></div></div>`).join('');
}
function renderRotasDashboard() {
    const el=$('#rv-dashboard-rotas'); if (!el) return;
    el.innerHTML = state.rotas.length ? state.rotas.map((r)=>`<div class="rv-route-row"><div class="rv-route-row-top"><div><h3>${esc(r.nome)}</h3><div class="rv-route-meta"><span class="rv-pill"><i class="fas fa-clock"></i> ${esc(String(r.hora_inicio).slice(0,5))} · a cada ${Number(r.intervalo_minutos)} min</span><span class="rv-pill gray">${Number(r.total_pontos)} ponto(s)</span><span class="rv-pill green">${Number(r.total_vigilantes)} vigilante(s)</span></div></div><span class="rv-pill ${Number(r.ativo)?'green':'red'}">${Number(r.ativo)?'Ativa':'Inativa'}</span></div></div>`).join('') : '<div class="rv-empty"><i class="fas fa-route"></i><p>Nenhuma rota cadastrada para este condomínio.</p></div>';
}
function renderRotas() {
    const el=$('#rv-rotas-lista'); if (!el) return;
    el.innerHTML = state.rotas.length ? state.rotas.map((r)=>`<article class="rv-route-row" data-rota="${Number(r.id)}"><div class="rv-route-row-top"><div><h3>${esc(r.nome)}</h3><p class="rv-muted">${esc(r.descricao || 'Sem descrição')}</p><div class="rv-route-meta"><span class="rv-pill"><i class="fas fa-clock"></i> ${esc(String(r.hora_inicio).slice(0,5))}${r.hora_fim ? ' — '+esc(String(r.hora_fim).slice(0,5)) : ''}</span><span class="rv-pill">Ciclo ${Number(r.intervalo_minutos)} min</span><span class="rv-pill gray">SLA ${Number(r.tolerancia_minutos)} min</span><span class="rv-pill gray">${esc(diasNome(r.dias))}</span><span class="rv-pill ${Number(r.ativo)?'green':'red'}">${Number(r.ativo)?'Ativa':'Inativa'}</span></div></div><div class="rv-route-actions"><button class="rv-btn mini secondary" data-action="edit-rota" data-id="${Number(r.id)}"><i class="fas fa-pen"></i> Editar</button><button class="rv-btn mini secondary" data-action="add-ponto" data-id="${Number(r.id)}"><i class="fas fa-qrcode"></i> Ponto</button><button class="rv-btn mini secondary" data-action="add-vig" data-id="${Number(r.id)}"><i class="fas fa-user-plus"></i> Vigilante</button><button class="rv-btn mini danger" data-action="remove-rota" data-id="${Number(r.id)}" title="Remover rota"><i class="fas fa-trash"></i></button></div></div><div class="rv-team">${(r.vigilantes||[]).map((v)=>`<span class="rv-team-chip"><i class="fas fa-user-shield"></i>${esc(v.nome)}<button data-action="remove-vig" data-id="${Number(v.vinculo_id)}" title="Remover vigilante">×</button></span>`).join('') || '<span class="rv-muted">Nenhum vigilante vinculado.</span>'}</div><div class="rv-points">${(r.pontos||[]).map((p)=>`<div class="rv-point"><div><strong>${Number(p.ordem)}. ${esc(p.nome)}</strong><small>${esc(p.localizacao || 'Localização não informada')}${p.instrucoes?' · '+esc(p.instrucoes):''}</small></div><div class="rv-route-actions"><button class="rv-btn mini secondary" data-action="edit-ponto" data-rota="${Number(r.id)}" data-id="${Number(p.id)}"><i class="fas fa-pen"></i></button><button class="rv-btn mini secondary" data-action="regen-qr" data-id="${Number(p.id)}"><i class="fas fa-arrows-rotate"></i></button><button class="rv-btn mini danger" data-action="remove-ponto" data-id="${Number(p.id)}"><i class="fas fa-trash"></i></button></div></div>`).join('') || '<div class="rv-muted">Adicione pontos físicos com QR Code para iniciar esta rota.</div>'}</div></article>`).join('') : '<div class="rv-empty"><i class="fas fa-route"></i><p>Crie a primeira rota para configurar as rondas.</p></div>';
}
async function ensureQRCode() { if (window.QRCode) return true; return new Promise((resolve)=>{ const s=document.createElement('script'); s.src='/js/qrcode.min.js'; s.onload=()=>resolve(!!window.QRCode); s.onerror=()=>resolve(false); document.head.appendChild(s); }); }
async function renderQrs() {
    const el=$('#rv-qrcodes-lista'); if (!el) return; const points = state.rotas.flatMap((r)=>(r.pontos||[]).map((p)=>({...p,rota_nome:r.nome})));
    if (!points.length) { el.innerHTML='<div class="rv-empty"><i class="fas fa-qrcode"></i><p>Crie uma rota e seus pontos para gerar QR Codes.</p></div>'; return; }
    el.innerHTML=points.map((p)=>`<article class="rv-qr-card" data-qr="${Number(p.id)}"><div class="rv-qr-canvas" id="rv-qr-${Number(p.id)}"></div><div><h3>${esc(p.nome)}</h3><p><strong>${esc(p.rota_nome)}</strong>${p.localizacao?' · '+esc(p.localizacao):''}</p><p>${esc(p.instrucoes || 'Escaneie para registrar a passagem da ronda.')}</p><div class="rv-qr-actions"><button class="rv-btn mini secondary" data-action="download-qr" data-id="${Number(p.id)}"><i class="fas fa-download"></i> Baixar</button><button class="rv-btn mini secondary" data-action="print-qr" data-id="${Number(p.id)}"><i class="fas fa-print"></i> Imprimir</button><button class="rv-btn mini danger" data-action="regen-qr" data-id="${Number(p.id)}"><i class="fas fa-arrows-rotate"></i></button></div></div></article>`).join('');
    if (!await ensureQRCode()) { el.querySelectorAll('.rv-qr-canvas').forEach((c)=>c.textContent='QR indisponível'); return; }
    points.forEach((p)=>{ const target=document.getElementById(`rv-qr-${Number(p.id)}`); if(target) new window.QRCode(target,{text:qrUrl(p.token_qr),width:94,height:94,correctLevel:window.QRCode.CorrectLevel.H}); });
}
function preencherFiltros() {
    const rotaSel=$('#rv-rel-rota'), vigSel=$('#rv-rel-vigilante'), vigForm=$('#rv-vig-colaborador');
    if(rotaSel) rotaSel.innerHTML='<option value="">Todas as rotas</option>'+state.rotas.map((r)=>`<option value="${Number(r.id)}">${esc(r.nome)}</option>`).join('');
    const guardas = Object.values([...state.colaboradores, ...state.rotas.flatMap((r)=>r.vigilantes||[])].reduce((a,v)=>{a[v.id||v.colaborador_id]=v;return a;},{}));
    if(vigSel) vigSel.innerHTML='<option value="">Todos os vigilantes</option>'+guardas.map((v)=>`<option value="${Number(v.id||v.colaborador_id)}">${esc(v.nome)}</option>`).join('');
    if(vigForm) vigForm.innerHTML='<option value="">Selecione o colaborador</option>'+state.colaboradores.map((v)=>`<option value="${Number(v.id)}">${esc(v.nome)}${v.cargo?' — '+esc(v.cargo):''}</option>`).join('');
}
function abrirRota(rota=null) { $('#rv-form-rota').reset(); $('#rv-rota-id').value=rota?.id||''; $('#rv-rota-modal-titulo').textContent=rota?'Editar rota':'Nova rota'; if(rota){$('#rv-rota-nome').value=rota.nome||'';$('#rv-rota-descricao').value=rota.descricao||'';$('#rv-rota-inicio').value=String(rota.hora_inicio||'').slice(0,5);$('#rv-rota-fim').value=String(rota.hora_fim||'').slice(0,5);$('#rv-rota-intervalo').value=rota.intervalo_minutos;$('#rv-rota-repeticoes').value=rota.repeticoes_por_dia;$('#rv-rota-tolerancia').value=rota.tolerancia_minutos;$('#rv-rota-ativo').checked=Number(rota.ativo)===1;document.querySelectorAll('.rv-weekdays input').forEach((i)=>i.checked=(rota.dias||[]).map(Number).includes(Number(i.value)));} modal('rv-modal-rota',true); }
function abrirPonto(rotaId,ponto=null){$('#rv-form-ponto').reset();$('#rv-ponto-rota-id').value=rotaId;$('#rv-ponto-id').value=ponto?.id||'';$('#rv-ponto-modal-titulo').textContent=ponto?'Editar ponto QR':'Novo ponto QR';if(ponto){$('#rv-ponto-nome').value=ponto.nome||'';$('#rv-ponto-local').value=ponto.localizacao||'';$('#rv-ponto-instrucoes').value=ponto.instrucoes||'';$('#rv-ponto-ordem').value=ponto.ordem||1;$('#rv-ponto-ativo').checked=Number(ponto.ativo)===1;}modal('rv-modal-ponto',true);}
function abrirVigilante(rotaId){$('#rv-vig-rota-id').value=rotaId;preencherFiltros();modal('rv-modal-vigilante',true);}
async function salvarRota(event){
    event.preventDefault();
    if (state.salvandoRota) { log('Tentativa de salvar rota ignorada: gravação já em andamento.'); return; }
    const form = $('#rv-form-rota');
    const botao = document.getElementById('rv-btn-salvar-rota') || form.querySelector('button[type="submit"]');
    const textoOriginal = botao.innerHTML;
    const dias=[...document.querySelectorAll('.rv-weekdays input:checked')].map((i)=>Number(i.value));
    const d={id:Number($('#rv-rota-id').value)||0,nome:$('#rv-rota-nome').value.trim(),descricao:$('#rv-rota-descricao').value.trim(),hora_inicio:$('#rv-rota-inicio').value,hora_fim:$('#rv-rota-fim').value,intervalo_minutos:Number($('#rv-rota-intervalo').value),repeticoes_por_dia:Number($('#rv-rota-repeticoes').value),tolerancia_minutos:Number($('#rv-rota-tolerancia').value),dias_semana:dias,ativo:$('#rv-rota-ativo').checked};
    if (!d.nome || !d.hora_inicio) { toast('Informe o nome e a hora inicial da rota.', 'warn'); return; }
    state.salvandoRota = true;
    botao.disabled = true; botao.classList.add('is-loading'); botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    try {
        const r=await request('salvar_rota',d,'POST');
        if(!r.sucesso){
            botao.classList.add('is-error');
            botao.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Não foi salvo';
            toast(r.mensagem,'error');
            await new Promise((resolve) => setTimeout(resolve, 1300));
            return;
        }
        botao.classList.remove('is-loading');
        botao.classList.add('is-success');
        botao.innerHTML = '<i class="fas fa-check"></i> Rota salva';
        toast(r.mensagem, 'success');
        await carregarDashboard();
        await new Promise((resolve) => setTimeout(resolve, 750));
        modal('rv-modal-rota',false);
    } finally {
        state.salvandoRota = false;
        botao.disabled = false;
        botao.classList.remove('is-loading', 'is-success', 'is-error');
        botao.innerHTML = textoOriginal;
    }
}
async function salvarPonto(event){event.preventDefault();const d={id:Number($('#rv-ponto-id').value)||0,rota_id:Number($('#rv-ponto-rota-id').value),nome:$('#rv-ponto-nome').value,localizacao:$('#rv-ponto-local').value,instrucoes:$('#rv-ponto-instrucoes').value,ordem:Number($('#rv-ponto-ordem').value),ativo:$('#rv-ponto-ativo').checked};const r=await request('salvar_ponto',d,'POST');if(!r.sucesso){toast(r.mensagem,'error');return;}modal('rv-modal-ponto',false);toast(r.mensagem);carregarDashboard();}
async function salvarVigilante(event){event.preventDefault();const d={rota_id:Number($('#rv-vig-rota-id').value),colaborador_id:Number($('#rv-vig-colaborador').value)};const r=await request('vincular_vigilante',d,'POST');if(!r.sucesso){toast(r.mensagem,'error');return;}modal('rv-modal-vigilante',false);toast(r.mensagem);carregarDashboard();}
async function gerarRelatorio(){const r=await request('relatorio',{data_de:$('#rv-rel-de').value,data_ate:$('#rv-rel-ate').value,rota_id:$('#rv-rel-rota').value,colaborador_id:$('#rv-rel-vigilante').value});if(!r.sucesso){toast(r.mensagem,'error');return;}state.report=r.dados.linhas||[];const s=r.dados.resumo||{};$('#rv-rel-total').textContent=s.total??0;$('#rv-rel-prazo').textContent=s.no_prazo??0;$('#rv-rel-atrasado').textContent=s.atrasado??0;$('#rv-rel-tbody').innerHTML=state.report.length?state.report.map((l)=>`<tr><td>${fmtDate(l.registrado_em)}</td><td>${esc(l.rota_nome)}</td><td>${esc(l.ponto_nome)}</td><td>${esc(l.vigilante_nome)}</td><td><span class="rv-sla ${l.status_sla==='atrasado'?'atrasado':'prazo'}">${l.status_sla==='atrasado'?'Atrasado':'No prazo'}</span></td><td>${Number(l.atraso_minutos)||0} min</td></tr>`).join(''):'<tr><td colspan="6" class="rv-empty">Nenhuma leitura encontrada no período.</td></tr>';}
function downloadQr(id){const c=document.querySelector(`#rv-qr-${id} canvas`);if(!c){toast('QR Code ainda não está pronto.','warn');return;}const a=document.createElement('a');a.href=c.toDataURL('image/png');a.download=`ponto-ronda-${id}.png`;a.click();}
function imprimirQr(id=null){const cards=[...document.querySelectorAll('.rv-qr-card')].filter((c)=>!id||Number(c.dataset.qr)===Number(id));if(!cards.length)return;const area=document.createElement('div');area.id='rv-print-area';area.style.display='none';cards.forEach((card)=>{const clone=card.cloneNode(true);const canvas=card.querySelector('canvas');const alvo=clone.querySelector('canvas');if(canvas&&alvo){const img=document.createElement('img');img.src=canvas.toDataURL('image/png');img.alt='QR Code do ponto de ronda';alvo.replaceWith(img);}area.appendChild(clone);});document.body.appendChild(area);window.print();setTimeout(()=>area.remove(),350);}
function exportarCsv(){if(!state.report.length){toast('Gere um relatório antes de exportar.','warn');return;}const cols=['registrado_em','rota_nome','ponto_nome','vigilante_nome','status_sla','atraso_minutos'];const csv=[cols.join(';'),...state.report.map((l)=>cols.map((c)=>`"${String(l[c]??'').replace(/"/g,'""')}"`).join(';'))].join('\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));a.download=`relatorio-rondas-${hoje()}.csv`;a.click();URL.revokeObjectURL(a.href);}
async function actionClick(event){const b=event.target.closest('[data-action]');if(!b)return;const action=b.dataset.action,id=Number(b.dataset.id),rotaId=Number(b.dataset.rota);const rota=state.rotas.find((r)=>Number(r.id)===(rotaId||id));if(action==='edit-rota')abrirRota(rota);if(action==='add-ponto')abrirPonto(id);if(action==='add-vig')abrirVigilante(id);if(action==='edit-ponto'){const r=state.rotas.find((x)=>Number(x.id)===rotaId);abrirPonto(rotaId,(r?.pontos||[]).find((p)=>Number(p.id)===id));}if(action==='remove-rota'&&confirm('Arquivar esta rota e seus pontos? Ela será removida da lista, mas os registros históricos serão preservados.')){const r=await request('remover_rota',{id},'POST');toast(r.sucesso?'Rota removida da lista. Os registros históricos foram preservados.':r.mensagem,r.sucesso?'success':'error');if(r.sucesso)await carregarDashboard();}if(action==='remove-ponto'&&confirm('Remover este ponto QR?')){const r=await request('remover_ponto',{id},'POST');toast(r.mensagem,r.sucesso?'success':'error');if(r.sucesso)carregarDashboard();}if(action==='remove-vig'){const r=await request('remover_vigilante',{id},'POST');toast(r.mensagem,r.sucesso?'success':'error');if(r.sucesso)carregarDashboard();}if(action==='regen-qr'&&confirm('Regenerar o QR Code? O adesivo anterior ficará inválido.')){const r=await request('regenerar_qr',{id},'POST');toast(r.mensagem,r.sucesso?'success':'error');if(r.sucesso)carregarDashboard();}if(action==='download-qr')downloadQr(id);if(action==='print-qr')imprimirQr(id);}
function trocarTab(tab){state.tab=tab;document.querySelectorAll('.rv-tab').forEach((b)=>b.classList.toggle('active',b.dataset.tab===tab));document.querySelectorAll('.rv-panel').forEach((p)=>p.classList.toggle('active',p.id===`rv-panel-${tab}`));}
export async function init(){log('Inicializando módulo de rondas no tenant da sessão');$('#rv-rel-de').value=new Date(Date.now()-6*86400000).toISOString().slice(0,10);$('#rv-rel-ate').value=hoje();document.querySelectorAll('.rv-tab').forEach((b)=>b.addEventListener('click',()=>trocarTab(b.dataset.tab)));$('#rv-btn-atualizar').addEventListener('click',carregarTudo);$('#rv-btn-nova-rota').addEventListener('click',()=>abrirRota());$('#rv-btn-imprimir-qrs').addEventListener('click',()=>imprimirQr());$('#rv-btn-gerar-relatorio').addEventListener('click',gerarRelatorio);$('#rv-btn-exportar-csv').addEventListener('click',exportarCsv);$('#rv-form-rota').addEventListener('submit',salvarRota);$('#rv-form-ponto').addEventListener('submit',salvarPonto);$('#rv-form-vigilante').addEventListener('submit',salvarVigilante);document.addEventListener('click',actionClick);document.querySelectorAll('[data-rv-close]').forEach((b)=>b.addEventListener('click',()=>modal(b.dataset.rvClose,false)));await carregarContexto();state.timer=setInterval(()=>{if(state.tab==='dashboard')carregarDashboard();},60000);}
export function destroy(){if(state.timer)clearInterval(state.timer);document.removeEventListener('click',actionClick);document.getElementById('rv-print-area')?.remove();}
