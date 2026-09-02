'use strict';

const API_ALERTAS = '/api/api_alertas_acesso.php';
let _listeners = [];
let _criterioSeq = 0;

export function init() {
    _setupBtnBack();
    _setupAlertas();
}

export function destroy() {
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
}

function on(el, ev, fn) { if (!el) return; el.addEventListener(ev, fn); _listeners.push({ el, ev, fn }); }
function _setupBtnBack() {
    document.querySelectorAll('[data-page]').forEach(el => on(el, 'click', () => window.AppRouter?.loadPage(el.dataset.page)));
}
function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
async function api(acao, options = {}) {
    const sep = API_ALERTAS.includes('?') ? '&' : '?';
    const response = await fetch(`${API_ALERTAS}${sep}acao=${encodeURIComponent(acao)}`, { cache: 'no-store', ...options, headers: { 'Content-Type': 'application/json', ...(options.headers || {}) } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.sucesso === false) throw new Error(data.mensagem || 'Não foi possível concluir a operação.');
    return data;
}
function _setupAlertas() {
    const panel = document.getElementById('alertas-acesso-panel');
    if (!panel) return;
    on(document.querySelector('[data-open-alertas]'), 'click', () => { panel.hidden = false; panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); carregarAlertas(); carregarEventos(); });
    on(panel.querySelector('[data-close-alertas]'), 'click', () => { panel.hidden = true; });
    on(document.getElementById('btn-add-alerta-criterio'), 'click', () => adicionarCriterio());
    on(document.getElementById('btn-cancelar-alerta'), 'click', limparFormulario);
    on(document.getElementById('btn-atualizar-alertas'), 'click', carregarAlertas);
    on(document.getElementById('btn-atualizar-alerta-eventos'), 'click', carregarEventos);
    on(document.getElementById('form-alerta-acesso'), 'submit', salvarAlerta);
    adicionarCriterio();
}
function adicionarCriterio(valor = {}) {
    const wrap = document.getElementById('alerta-acesso-criterios'); if (!wrap) return;
    const id = ++_criterioSeq;
    const row = document.createElement('div'); row.className = 'sistema-alerta-criterio'; row.dataset.criterioId = id;
    row.innerHTML = `<select class="alerta-criterio-tipo" aria-label="Tipo"><option value="veiculo">Veículo</option><option value="pessoa">Pessoa</option><option value="contexto">Contexto</option></select><select class="alerta-criterio-campo" aria-label="Campo"></select><select class="alerta-criterio-operador" aria-label="Operador"><option value="igual">é igual a</option><option value="contem">contém</option><option value="comeca_com">começa com</option></select><input class="alerta-criterio-valor" required maxlength="255" placeholder="Valor do critério"><button type="button" class="btn-danger alerta-remover-criterio" title="Remover critério"><i class="fas fa-trash"></i></button>`;
    wrap.appendChild(row);
    const tipo = row.querySelector('.alerta-criterio-tipo'); tipo.value = valor.tipo || 'veiculo';
    const campo = row.querySelector('.alerta-criterio-campo'); preencherCampos(campo, tipo.value, valor.campo); tipo.addEventListener('change', () => preencherCampos(campo, tipo.value));
    row.querySelector('.alerta-criterio-operador').value = valor.operador || 'igual'; row.querySelector('.alerta-criterio-valor').value = valor.valor || '';
    row.querySelector('.alerta-remover-criterio').addEventListener('click', () => { if (wrap.children.length > 1) row.remove(); });
}
function preencherCampos(select, tipo, escolhido = '') {
    const grupos = { veiculo: [['placa','Placa'],['modelo','Modelo'],['cor','Cor']], pessoa: [['pessoa_nome','Nome'],['pessoa_cpf','CPF']], contexto: [['telefone','Telefone'],['unidade','Unidade'],['observacao','Observação']] };
    select.innerHTML = (grupos[tipo] || grupos.contexto).map(([v,t]) => `<option value="${v}">${t}</option>`).join(''); select.value = escolhido && select.querySelector(`option[value="${CSS.escape(escolhido)}"]`) ? escolhido : select.options[0]?.value;
}
function coletarCriterios() { return [...document.querySelectorAll('#alerta-acesso-criterios .sistema-alerta-criterio')].map(row => ({ tipo: row.querySelector('.alerta-criterio-tipo').value, campo: row.querySelector('.alerta-criterio-campo').value, operador: row.querySelector('.alerta-criterio-operador').value, valor: row.querySelector('.alerta-criterio-valor').value.trim() })).filter(x => x.valor); }
async function salvarAlerta(ev) { ev.preventDefault(); const btn = document.getElementById('btn-salvar-alerta'); const payload = { id: Number(document.getElementById('alerta-acesso-id').value || 0), nome: document.getElementById('alerta-acesso-nome').value.trim(), descricao: document.getElementById('alerta-acesso-descricao').value.trim(), severidade: document.getElementById('alerta-acesso-severidade').value, ativo: true, canais: [...document.querySelectorAll('input[name="alerta-canal"]:checked')].map(x => x.value), criterios: coletarCriterios() }; btn.disabled = true; btn.classList.add('is-loading'); try { await api('salvar', { method: 'POST', body: JSON.stringify(payload) }); alert('Alerta salvo com sucesso.'); limparFormulario(); await carregarAlertas(); } catch (e) { alert(e.message); } finally { btn.disabled = false; btn.classList.remove('is-loading'); } }
function limparFormulario() { const form = document.getElementById('form-alerta-acesso'); if (!form) return; form.reset(); document.getElementById('alerta-acesso-id').value = ''; const wrap = document.getElementById('alerta-acesso-criterios'); wrap.innerHTML = ''; adicionarCriterio(); }
async function carregarAlertas() { const el = document.getElementById('alertas-acesso-lista'); if (!el) return; el.innerHTML = '<div class="sistema-alerta-vazio">Carregando alertas...</div>'; try { const data = await api('listar'); const lista = Array.isArray(data.dados) ? data.dados : []; el.innerHTML = lista.length ? lista.map(renderAlerta).join('') : '<div class="sistema-alerta-vazio">Nenhum alerta cadastrado.</div>'; el.querySelectorAll('[data-desativar-alerta]').forEach(btn => on(btn, 'click', async () => { if (!confirm('Desativar este alerta?')) return; await api('excluir', { method: 'POST', body: JSON.stringify({ id: btn.dataset.desativarAlerta }) }); carregarAlertas(); })); } catch (e) { el.innerHTML = `<div class="sistema-alerta-erro">${esc(e.message)}</div>`; } }
function renderAlerta(a) { const canais = Array.isArray(a.canais) ? a.canais.join(', ') : 'sistema'; const criterios = Array.isArray(a.criterios) ? a.criterios.map(c => `${c.campo} ${c.operador === 'igual' ? '=' : c.operador === 'contem' ? 'contém' : 'começa com'} ${c.valor}`).join(' · ') : ''; return `<article class="sistema-alerta-item ${a.ativo == 1 ? '' : 'is-inativo'}"><div><strong>${esc(a.nome)}</strong><span class="sistema-alerta-severidade ${esc(a.severidade)}">${esc(a.severidade)}</span><p>${esc(a.descricao || 'Sem descrição')}</p><small>Critérios: ${esc(criterios)}<br>Canais: ${esc(canais)}</small></div><button type="button" class="btn-danger" data-desativar-alerta="${esc(a.id)}" ${a.ativo == 1 ? '' : 'disabled'}>${a.ativo == 1 ? 'Desativar' : 'Inativo'}</button></article>`; }
async function carregarEventos() { const el = document.getElementById('alertas-acesso-eventos'); if (!el) return; try { const data = await api('eventos'); const lista = Array.isArray(data.dados) ? data.dados : []; el.innerHTML = lista.length ? lista.map(e => `<article class="sistema-alerta-evento"><strong>${esc(e.alerta_nome)}</strong><span>${esc(e.severidade)} · ${esc(e.origem)} · ${esc(e.detectado_em)}</span><p>${esc(e.dados?.evento?.placa || e.dados?.evento?.pessoa_nome || 'Evento identificado')}</p>${e.status !== 'reconhecido' ? `<button type="button" class="btn-primary" data-reconhecer-alerta="${esc(e.id)}">Reconhecer</button>` : '<em>Reconhecido</em>'}</article>`).join('') : '<div class="sistema-alerta-vazio">Nenhum alerta disparado.</div>'; el.querySelectorAll('[data-reconhecer-alerta]').forEach(btn => on(btn, 'click', async () => { await api('reconhecer', { method: 'POST', body: JSON.stringify({ evento_id: btn.dataset.reconhecerAlerta }) }); carregarEventos(); })); } catch (e) { el.innerHTML = `<div class="sistema-alerta-erro">${esc(e.message)}</div>`; } }
