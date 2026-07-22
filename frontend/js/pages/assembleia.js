/**
 * assembleia.js — Módulo de Assembleia v1.0
 * ES Module com export init/destroy (padrão AppRouter)
 */

const API = window.APP_BASE_PATH
    ? window.APP_BASE_PATH + 'api/api_assembleia.php'
    : '/api/api_assembleia.php';

// ── Estado ─────────────────────────────────────────────────
const S = {
    assembleias: [],
    filtroStatus: '',
    busca: '',
    assembleiaAtual: null,
    pautas: [],
    anexos: [],
    participantes: [],
    meusVotos: {},
    dragSrcIndex: null,
};

// ── DOM refs ────────────────────────────────────────────────
let D = {};

// ══════════════════════════════════════════════════════════
// INIT / DESTROY
// ══════════════════════════════════════════════════════════
export function init() {
    _bindDOM();
    _bindEvents();
    _carregarAssembleias();
    _runMigration();
    console.debug('[Assembleia] init()');
}

export function destroy() {
    console.debug('[Assembleia] destroy()');
}

function _bindDOM() {
    D = {
        toast:          document.getElementById('ass_toast'),
        tabs:           document.querySelectorAll('#ass_tabs .tab-button'),
        tabDetalhe:     document.getElementById('ass_tabDetalhe'),
        tabVotacao:     document.getElementById('ass_tabVotacao'),
        tabDetalheNome: document.getElementById('ass_tabDetalheNome'),
        lista:          document.getElementById('ass_lista'),
        busca:          document.getElementById('ass_busca'),
        btnNova:        document.getElementById('ass_btnNova'),
        // KPIs
        kpiTotal:       document.getElementById('ass_kpiTotal'),
        kpiAndamento:   document.getElementById('ass_kpiAndamento'),
        kpiConvocadas:  document.getElementById('ass_kpiConvocadas'),
        kpiEncerradas:  document.getElementById('ass_kpiEncerradas'),
        // Detalhe
        detalheHeader:  document.getElementById('ass_detalheHeader'),
        detalheTipo:    document.getElementById('ass_detalheTipo'),
        detalheNome:    document.getElementById('ass_detalheNome'),
        detalheMeta:    document.getElementById('ass_detalheMeta'),
        btnEditar:      document.getElementById('ass_btnEditar'),
        btnStatus:      document.getElementById('ass_btnStatus'),
        statusLabel:    document.getElementById('ass_statusLabel'),
        statusMenu:     document.getElementById('ass_statusMenu'),
        // Sub-tabs
        subtabs:        document.querySelectorAll('.ass-subtab'),
        badgeAnexos:    document.getElementById('ass_badgeAnexos'),
        badgePart:      document.getElementById('ass_badgePart'),
        // Pautas
        listaPautas:    document.getElementById('ass_listaPautas'),
        btnNovaPauta:   document.getElementById('ass_btnNovaPauta'),
        // Anexos
        listaAnexos:    document.getElementById('ass_listaAnexos'),
        btnUpload:      document.getElementById('ass_btnUpload'),
        uploadForm:     document.getElementById('ass_uploadForm'),
        tipoAnexo:      document.getElementById('ass_tipoAnexo'),
        fileInput:      document.getElementById('ass_fileInput'),
        fileNome:       document.getElementById('ass_fileNome'),
        btnEnviarArq:   document.getElementById('ass_btnEnviarArquivo'),
        btnCancelUpload:document.getElementById('ass_btnCancelarUpload'),
        // Participantes
        listaPart:      document.getElementById('ass_listaParticipantes'),
        btnAddPart:     document.getElementById('ass_btnAddPart'),
        partForm:       document.getElementById('ass_partForm'),
        partNome:       document.getElementById('ass_partNome'),
        partUnidade:    document.getElementById('ass_partUnidade'),
        partTipo:       document.getElementById('ass_partTipo'),
        btnSalvarPart:  document.getElementById('ass_btnSalvarPart'),
        btnCancelPart:  document.getElementById('ass_btnCancelarPart'),
        // Resultados
        resultados:     document.getElementById('ass_resultados'),
        // Votação
        votacaoInfo:    document.getElementById('ass_votacaoInfo'),
        votacaoPautas:  document.getElementById('ass_votacaoPautas'),
        // Modal Assembleia
        modalAss:       document.getElementById('ass_modalAssembleia'),
        modalAssTitulo: document.getElementById('ass_modalAssTitulo'),
        formId:         document.getElementById('ass_formId'),
        formNome:       document.getElementById('ass_formNome'),
        formData:       document.getElementById('ass_formData'),
        formLocal:      document.getElementById('ass_formLocal'),
        formQuorum:     document.getElementById('ass_formQuorum'),
        formDesc:       document.getElementById('ass_formDesc'),
        modalAssClose:  document.getElementById('ass_modalAssClose'),
        modalAssCancelar: document.getElementById('ass_modalAssCancelar'),
        modalAssSalvar: document.getElementById('ass_modalAssSalvar'),
        // Modal Pauta
        modalPauta:     document.getElementById('ass_modalPauta'),
        modalPautaTitulo: document.getElementById('ass_modalPautaTitulo'),
        pautaId:        document.getElementById('ass_pautaId'),
        pautaTitulo:    document.getElementById('ass_pautaTitulo'),
        pautaDesc:      document.getElementById('ass_pautaDesc'),
        modalPautaClose: document.getElementById('ass_modalPautaClose'),
        modalPautaCancelar: document.getElementById('ass_modalPautaCancelar'),
        modalPautaSalvar: document.getElementById('ass_modalPautaSalvar'),
        // Modal Votar
        modalVotar:     document.getElementById('ass_modalVotar'),
        votarPautaId:   document.getElementById('ass_votarPautaId'),
        votarTitulo:    document.getElementById('ass_votarTitulo'),
        votarDesc:      document.getElementById('ass_votarDesc'),
        modalVotarClose: document.getElementById('ass_modalVotarClose'),
        modalVotarCancelar: document.getElementById('ass_modalVotarCancelar'),
        modalVotarConfirmar: document.getElementById('ass_modalVotarConfirmar'),
        votarNome:      document.getElementById('ass_votarNome'),
        votarUnidade:   document.getElementById('ass_votarUnidade'),
    };
}

function _bindEvents() {
    // Tabs principais
    D.tabs.forEach(btn => btn.addEventListener('click', () => _mudarTab(btn.dataset.tab)));

    // Sub-tabs
    D.subtabs.forEach(btn => btn.addEventListener('click', () => _mudarSubtab(btn.dataset.subtab)));

    // Filtros
    document.querySelectorAll('.ass-filtro-btn').forEach(btn =>
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ass-filtro-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            S.filtroStatus = btn.dataset.status;
            _carregarAssembleias();
        })
    );

    // Busca
    D.busca && D.busca.addEventListener('input', _debounce(() => {
        S.busca = D.busca.value.trim();
        _renderLista();
    }, 300));

    // Nova assembleia
    D.btnNova && D.btnNova.addEventListener('click', () => _abrirModalAssembleia());

    // Modal assembleia
    D.modalAssClose    && D.modalAssClose.addEventListener('click',    () => _fecharModal(D.modalAss));
    D.modalAssCancelar && D.modalAssCancelar.addEventListener('click', () => _fecharModal(D.modalAss));
    D.modalAssSalvar   && D.modalAssSalvar.addEventListener('click',   _salvarAssembleia);

    // Editar
    D.btnEditar && D.btnEditar.addEventListener('click', () => {
        if (S.assembleiaAtual) _abrirModalAssembleia(S.assembleiaAtual);
    });

    // Status dropdown
    D.btnStatus && D.btnStatus.addEventListener('click', (e) => {
        e.stopPropagation();
        D.statusMenu.classList.toggle('open');
    });
    document.addEventListener('click', () => D.statusMenu && D.statusMenu.classList.remove('open'));
    document.querySelectorAll('.ass-status-item').forEach(item =>
        item.addEventListener('click', () => _alterarStatus(item.dataset.status))
    );

    // Nova pauta
    D.btnNovaPauta && D.btnNovaPauta.addEventListener('click', () => _abrirModalPauta());

    // Modal pauta
    D.modalPautaClose    && D.modalPautaClose.addEventListener('click',    () => _fecharModal(D.modalPauta));
    D.modalPautaCancelar && D.modalPautaCancelar.addEventListener('click', () => _fecharModal(D.modalPauta));
    D.modalPautaSalvar   && D.modalPautaSalvar.addEventListener('click',   _salvarPauta);

    // Anexos
    D.btnUpload && D.btnUpload.addEventListener('click', () => {
        D.uploadForm.style.display = D.uploadForm.style.display === 'none' ? 'flex' : 'none';
    });
    D.btnCancelUpload && D.btnCancelUpload.addEventListener('click', () => {
        D.uploadForm.style.display = 'none';
        D.fileInput.value = '';
        D.fileNome.textContent = 'Clique para selecionar o arquivo';
    });
    D.fileInput && D.fileInput.addEventListener('change', () => {
        D.fileNome.textContent = D.fileInput.files[0]?.name || 'Clique para selecionar o arquivo';
    });
    D.fileNome && D.fileNome.parentElement && D.fileNome.parentElement.addEventListener('click', () => D.fileInput.click());
    D.btnEnviarArq && D.btnEnviarArq.addEventListener('click', _enviarAnexo);

    // Participantes
    D.btnAddPart && D.btnAddPart.addEventListener('click', () => {
        D.partForm.style.display = D.partForm.style.display === 'none' ? 'flex' : 'none';
    });
    D.btnCancelPart && D.btnCancelPart.addEventListener('click', () => { D.partForm.style.display = 'none'; });
    D.btnSalvarPart && D.btnSalvarPart.addEventListener('click', _salvarParticipante);

    // Modal votar
    D.modalVotarClose    && D.modalVotarClose.addEventListener('click',    () => _fecharModal(D.modalVotar));
    D.modalVotarCancelar && D.modalVotarCancelar.addEventListener('click', () => _fecharModal(D.modalVotar));
    D.modalVotarConfirmar && D.modalVotarConfirmar.addEventListener('click', _confirmarVoto);

    // Breadcrumb
    document.querySelectorAll('.ass-bc-link[data-page]').forEach(el =>
        el.addEventListener('click', () => {
            if (window.AppRouter) window.AppRouter.navigate(el.dataset.page);
        })
    );

    // Fechar modal ao clicar fora
    [D.modalAss, D.modalPauta, D.modalVotar].forEach(m => {
        m && m.addEventListener('click', (e) => { if (e.target === m) _fecharModal(m); });
    });
}

// ══════════════════════════════════════════════════════════
// TABS
// ══════════════════════════════════════════════════════════
function _mudarTab(tabId) {
    document.querySelectorAll('#ass_tabs .tab-button').forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === tabId));
}

function _mudarSubtab(subtabId) {
    D.subtabs.forEach(b => b.classList.toggle('active', b.dataset.subtab === subtabId));
    document.querySelectorAll('.ass-subcontent').forEach(c => c.classList.toggle('active', c.id === subtabId));
    if (subtabId === 'sub-resultado') _renderResultados();
}

// ══════════════════════════════════════════════════════════
// CARREGAR ASSEMBLEIAS
// ══════════════════════════════════════════════════════════
async function _carregarAssembleias() {
    D.lista.innerHTML = '<div class="ass-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    const params = new URLSearchParams({ acao: 'listar' });
    if (S.filtroStatus) params.set('status', S.filtroStatus);
    if (S.busca)        params.set('busca',  S.busca);
    const res = await _get(params.toString());
    if (!res?.ok) { D.lista.innerHTML = '<div class="ass-empty">Erro ao carregar assembleias.</div>'; return; }
    S.assembleias = res.dados || [];
    _renderLista();
    _atualizarKPIs();
}

function _renderLista() {
    const busca = S.busca.toLowerCase();
    const lista = busca
        ? S.assembleias.filter(a => a.nome.toLowerCase().includes(busca) || (a.local_realizacao||'').toLowerCase().includes(busca))
        : S.assembleias;

    if (!lista.length) {
        D.lista.innerHTML = '<div class="ass-empty">Nenhuma assembleia encontrada.</div>';
        return;
    }

    D.lista.innerHTML = lista.map(a => `
        <div class="ass-card" data-id="${a.id}" onclick="window.AssembleiaPage.abrirDetalhe(${a.id})">
            <div class="ass-card-icon ${a.tipo}">
                <i class="fas ${_iconeTipo(a.tipo)}"></i>
            </div>
            <div class="ass-card-body">
                <div class="ass-card-nome">${_esc(a.nome)}</div>
                <div class="ass-card-meta">
                    <span><i class="fas fa-calendar"></i> ${_formatarData(a.data_assembleia)}</span>
                    ${a.local_realizacao ? `<span><i class="fas fa-map-marker-alt"></i> ${_esc(a.local_realizacao)}</span>` : ''}
                    ${a.criador_nome ? `<span><i class="fas fa-user"></i> ${_esc(a.criador_nome)}</span>` : ''}
                </div>
                <div class="ass-card-tags">
                    <span class="ass-tag ass-tag-pautas"><i class="fas fa-list-ol"></i> ${a.total_pautas} pauta(s)</span>
                    <span class="ass-tag ass-tag-part"><i class="fas fa-users"></i> ${a.total_participantes} participante(s)</span>
                    ${a.total_votos > 0 ? `<span class="ass-tag ass-tag-votos"><i class="fas fa-vote-yea"></i> ${a.total_votos} voto(s)</span>` : ''}
                </div>
            </div>
            <div class="ass-card-status">
                <span class="ass-status-badge ${a.status}">${_labelStatus(a.status)}</span>
            </div>
        </div>
    `).join('');
}

function _atualizarKPIs() {
    const all  = S.assembleias;
    D.kpiTotal.textContent      = all.length;
    D.kpiAndamento.textContent  = all.filter(a => a.status === 'em_andamento').length;
    D.kpiConvocadas.textContent = all.filter(a => a.status === 'convocada').length;
    D.kpiEncerradas.textContent = all.filter(a => a.status === 'encerrada').length;
}

// ══════════════════════════════════════════════════════════
// ABRIR DETALHE
// ══════════════════════════════════════════════════════════
async function _abrirDetalhe(id) {
    const res = await _get(`acao=obter&id=${id}`);
    if (!res?.ok) { _toast('Erro ao carregar assembleia', 'error'); return; }
    const a = res.dados;
    S.assembleiaAtual = a;
    S.pautas          = a.pautas || [];
    S.anexos          = a.anexos || [];
    S.participantes   = a.participantes || [];

    // Atualizar header
    D.detalheTipo.textContent  = _labelTipo(a.tipo);
    D.detalheTipo.className    = `ass-detalhe-tipo-badge ${a.tipo}`;
    D.detalheNome.textContent  = a.nome;
    D.tabDetalheNome.textContent = a.nome.length > 20 ? a.nome.substring(0,20)+'…' : a.nome;
    D.statusLabel.textContent  = _labelStatus(a.status);
    D.detalheMeta.innerHTML = `
        <span><i class="fas fa-calendar"></i> ${_formatarData(a.data_assembleia)}</span>
        ${a.local_realizacao ? `<span><i class="fas fa-map-marker-alt"></i> ${_esc(a.local_realizacao)}</span>` : ''}
        ${a.quorum_minimo > 0 ? `<span><i class="fas fa-users"></i> Quórum: ${a.quorum_minimo}</span>` : ''}
        ${a.criador_nome ? `<span><i class="fas fa-user-edit"></i> Criado por: ${_esc(a.criador_nome)}</span>` : ''}
    `;

    // Badges
    D.badgeAnexos.textContent = S.anexos.length;
    D.badgePart.textContent   = S.participantes.length;

    // Mostrar tab detalhe
    D.tabDetalhe.style.display = '';
    D.tabVotacao.style.display = '';

    // Renderizar sub-tabs
    _renderPautas();
    _renderAnexos();
    _renderParticipantes();

    // Carregar meus votos
    _carregarMeusVotos(id);

    // Navegar para tab detalhe
    _mudarTab('ass-detalhe');
    _mudarSubtab('sub-pautas');
}

// ══════════════════════════════════════════════════════════
// PAUTAS
// ══════════════════════════════════════════════════════════
function _renderPautas() {
    if (!S.pautas.length) {
        D.listaPautas.innerHTML = '<div class="ass-empty">Nenhuma pauta cadastrada. Clique em "Adicionar Pauta" para começar.</div>';
        return;
    }

    D.listaPautas.innerHTML = S.pautas.map((p, idx) => {
        const totalVotos = (p.votos_aprovado|0) + (p.votos_reprovado|0) + (p.votos_anulado|0);
        const pctAp = totalVotos ? Math.round((p.votos_aprovado/totalVotos)*100) : 0;
        const pctRp = totalVotos ? Math.round((p.votos_reprovado/totalVotos)*100) : 0;
        const pctAn = totalVotos ? 100 - pctAp - pctRp : 0;

        const votosBar = p.tipo === 'votacao' && totalVotos > 0 ? `
            <div class="ass-votos-bar">
                <div class="ass-votos-bar-ap" style="width:${pctAp}%"></div>
                <div class="ass-votos-bar-rp" style="width:${pctRp}%"></div>
                <div class="ass-votos-bar-an" style="width:${pctAn}%"></div>
            </div>
            <div class="ass-votos-legenda">
                <span><span class="ass-votos-dot dot-ap"></span> Aprovado: ${p.votos_aprovado}</span>
                <span><span class="ass-votos-dot dot-rp"></span> Não Aprovou: ${p.votos_reprovado}</span>
                <span><span class="ass-votos-dot dot-an"></span> Anulado: ${p.votos_anulado}</span>
            </div>
        ` : '';

        const acoesBotoes = p.tipo === 'votacao' ? `
            ${p.status === 'pendente'   ? `<button class="btn-icon" onclick="window.AssembleiaPage.iniciarVotacao(${p.id})"><i class="fas fa-play"></i> Iniciar</button>` : ''}
            ${p.status === 'em_votacao' ? `<button class="btn-icon" onclick="window.AssembleiaPage.encerrarVotacao(${p.id})"><i class="fas fa-stop"></i> Encerrar</button>` : ''}
        ` : '';

        return `
        <div class="ass-pauta-item" draggable="true" data-idx="${idx}" data-id="${p.id}"
             ondragstart="window.AssembleiaPage._dragStart(event,${idx})"
             ondragover="window.AssembleiaPage._dragOver(event)"
             ondrop="window.AssembleiaPage._dragDrop(event,${idx})">
            <div class="ass-pauta-drag"><i class="fas fa-grip-vertical"></i></div>
            <div class="ass-pauta-ordem">${p.ordem}</div>
            <div class="ass-pauta-body">
                <div class="ass-pauta-titulo">${_esc(p.titulo)}</div>
                ${p.descricao ? `<div class="ass-pauta-desc">${_esc(p.descricao)}</div>` : ''}
                <div class="ass-pauta-footer">
                    <span class="ass-pauta-tipo-badge ${p.tipo}">${_labelTipoPauta(p.tipo)}</span>
                    <span class="ass-pauta-status-badge ${p.status}">${_labelStatusPauta(p.status)}</span>
                    ${p.resultado && p.resultado !== 'pendente' ? `<span class="ass-pauta-resultado-badge ${p.resultado}">${_labelResultado(p.resultado)}</span>` : ''}
                </div>
                ${votosBar}
            </div>
            <div class="ass-pauta-acoes">
                ${acoesBotoes}
                <button class="btn-icon" onclick="window.AssembleiaPage.editarPauta(${p.id})"><i class="fas fa-edit"></i></button>
                <button class="btn-danger" onclick="window.AssembleiaPage.excluirPauta(${p.id})"><i class="fas fa-trash"></i></button>
            </div>
        </div>`;
    }).join('');
}

// Drag & Drop para reordenar pautas
function _dragStart(e, idx) {
    S.dragSrcIndex = idx;
    e.dataTransfer.effectAllowed = 'move';
}
function _dragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
async function _dragDrop(e, toIdx) {
    e.preventDefault();
    if (S.dragSrcIndex === null || S.dragSrcIndex === toIdx) return;
    const moved = S.pautas.splice(S.dragSrcIndex, 1)[0];
    S.pautas.splice(toIdx, 0, moved);
    S.pautas.forEach((p, i) => p.ordem = i + 1);
    _renderPautas();
    const ids = S.pautas.map(p => p.id);
    await _post({ acao: 'reordenar_pautas', ids });
    S.dragSrcIndex = null;
}

// ══════════════════════════════════════════════════════════
// MODAL ASSEMBLEIA
// ══════════════════════════════════════════════════════════
function _abrirModalAssembleia(ass = null) {
    D.formId.value    = ass?.id    || '';
    D.formNome.value  = ass?.nome  || '';
    D.formLocal.value = ass?.local_realizacao || '';
    D.formQuorum.value = ass?.quorum_minimo || 0;
    D.formDesc.value  = ass?.descricao || '';

    if (ass?.data_assembleia) {
        // Converter para formato datetime-local
        const d = new Date(ass.data_assembleia.replace(' ', 'T'));
        const pad = n => String(n).padStart(2,'0');
        D.formData.value = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } else {
        D.formData.value = '';
    }

    // Tipo
    const tipo = ass?.tipo || 'ordinaria';
    document.querySelectorAll('input[name="ass_tipo"]').forEach(r => r.checked = r.value === tipo);

    D.modalAssTitulo.innerHTML = ass
        ? '<i class="fas fa-edit"></i> Editar Assembleia'
        : '<i class="fas fa-landmark"></i> Nova Assembleia';

    D.modalAss.style.display = 'flex';
}

async function _salvarAssembleia() {
    const id    = D.formId.value;
    const nome  = D.formNome.value.trim();
    const tipo  = document.querySelector('input[name="ass_tipo"]:checked')?.value || 'ordinaria';
    const data  = D.formData.value;
    const local = D.formLocal.value.trim();
    const desc  = D.formDesc.value.trim();
    const quorum = parseInt(D.formQuorum.value) || 0;

    if (!nome) { _toast('Informe o nome da assembleia', 'warning'); return; }
    if (!data) { _toast('Informe a data da assembleia', 'warning'); return; }

    const payload = { acao: id ? 'atualizar' : 'criar', nome, tipo, data_assembleia: data.replace('T',' '), local_realizacao: local, descricao: desc, quorum_minimo: quorum };
    if (id) payload.id = id;

    const res = await _post(payload);
    if (!res?.ok) { _toast(res?.erro || 'Erro ao salvar', 'error'); return; }

    _toast(id ? 'Assembleia atualizada!' : 'Assembleia criada!', 'success');
    _fecharModal(D.modalAss);
    await _carregarAssembleias();

    if (!id && res.id) {
        await _abrirDetalhe(res.id);
    } else if (id) {
        await _abrirDetalhe(parseInt(id));
    }
}

// ══════════════════════════════════════════════════════════
// MODAL PAUTA
// ══════════════════════════════════════════════════════════
function _abrirModalPauta(pauta = null) {
    D.pautaId.value     = pauta?.id    || '';
    D.pautaTitulo.value = pauta?.titulo || '';
    D.pautaDesc.value   = pauta?.descricao || '';
    const tipo = pauta?.tipo || 'informativo';
    document.querySelectorAll('input[name="ass_pautaTipo"]').forEach(r => r.checked = r.value === tipo);
    D.modalPautaTitulo.innerHTML = pauta
        ? '<i class="fas fa-edit"></i> Editar Pauta'
        : '<i class="fas fa-list-ol"></i> Nova Pauta';
    D.modalPauta.style.display = 'flex';
}

async function _salvarPauta() {
    const id     = D.pautaId.value;
    const titulo = D.pautaTitulo.value.trim();
    const desc   = D.pautaDesc.value.trim();
    const tipo   = document.querySelector('input[name="ass_pautaTipo"]:checked')?.value || 'informativo';

    if (!titulo) { _toast('Informe o título da pauta', 'warning'); return; }
    if (!S.assembleiaAtual) { _toast('Nenhuma assembleia selecionada', 'error'); return; }

    const payload = { acao: id ? 'atualizar_pauta' : 'criar_pauta', titulo, descricao: desc, tipo };
    if (id) payload.id = id;
    else    payload.assembleia_id = S.assembleiaAtual.id;

    const res = await _post(payload);
    if (!res?.ok) { _toast(res?.erro || 'Erro ao salvar pauta', 'error'); return; }

    _toast(id ? 'Pauta atualizada!' : 'Pauta adicionada!', 'success');
    _fecharModal(D.modalPauta);
    await _recarregarPautas();
}

async function _recarregarPautas() {
    const res = await _get(`acao=listar_pautas&assembleia_id=${S.assembleiaAtual.id}`);
    if (res?.ok) {
        S.pautas = res.dados;
        _renderPautas();
    }
}

async function _editarPauta(id) {
    const pauta = S.pautas.find(p => p.id == id);
    if (pauta) _abrirModalPauta(pauta);
}

async function _excluirPauta(id) {
    if (!confirm('Excluir esta pauta? Esta ação não pode ser desfeita.')) return;
    const res = await _post({ acao: 'excluir_pauta', id });
    if (!res?.ok) { _toast(res?.erro || 'Erro ao excluir', 'error'); return; }
    _toast('Pauta excluída', 'success');
    await _recarregarPautas();
}

// ══════════════════════════════════════════════════════════
// VOTAÇÃO
// ══════════════════════════════════════════════════════════
async function _iniciarVotacao(pautaId) {
    if (!confirm('Iniciar votação para esta pauta?')) return;
    const res = await _post({ acao: 'iniciar_votacao', pauta_id: pautaId });
    if (!res?.ok) { _toast(res?.erro || 'Erro', 'error'); return; }
    _toast('Votação iniciada!', 'success');
    await _recarregarPautas();
    _renderPortalVotacao();
}

async function _encerrarVotacao(pautaId) {
    if (!confirm('Encerrar votação e calcular resultado?')) return;
    const res = await _post({ acao: 'encerrar_votacao', pauta_id: pautaId });
    if (!res?.ok) { _toast(res?.erro || 'Erro', 'error'); return; }
    const r = res.resultado;
    const v = res.votos;
    _toast(`Votação encerrada! Resultado: ${_labelResultado(r)} (${v.aprovado} ✓ / ${v.reprovado} ✗ / ${v.anulado} ∅)`, 'success');
    await _recarregarPautas();
    _renderPortalVotacao();
}

function _abrirModalVotar(pautaId) {
    const pauta = S.pautas.find(p => p.id == pautaId);
    if (!pauta) return;
    if (pauta.status !== 'em_votacao') { _toast('Esta pauta não está em votação', 'warning'); return; }
    if (S.meusVotos[pautaId]) { _toast('Você já votou nesta pauta', 'warning'); return; }

    D.votarPautaId.value = pautaId;
    D.votarTitulo.textContent = pauta.titulo;
    D.votarDesc.textContent   = pauta.descricao || '';
    document.querySelectorAll('input[name="ass_votoOpcao"]').forEach(r => r.checked = false);
    D.votarNome.value    = '';
    D.votarUnidade.value = '';
    D.modalVotar.style.display = 'flex';
}

async function _confirmarVoto() {
    const pautaId = parseInt(D.votarPautaId.value);
    const voto    = document.querySelector('input[name="ass_votoOpcao"]:checked')?.value;
    if (!voto) { _toast('Selecione uma opção de voto', 'warning'); return; }

    const res = await _post({
        acao: 'registrar_voto',
        pauta_id: pautaId,
        voto,
        tipo_participacao: 'online',
        nome_votante: D.votarNome.value.trim(),
        unidade: D.votarUnidade.value.trim(),
    });

    if (!res?.ok) { _toast(res?.erro || 'Erro ao registrar voto', 'error'); return; }

    S.meusVotos[pautaId] = { voto, criado_em: new Date().toISOString() };
    _toast('Voto registrado com sucesso!', 'success');
    _fecharModal(D.modalVotar);
    await _recarregarPautas();
    _renderPortalVotacao();
}

async function _carregarMeusVotos(assembleiaId) {
    const res = await _get(`acao=meus_votos&assembleia_id=${assembleiaId}`);
    if (res?.ok) S.meusVotos = res.dados || {};
    _renderPortalVotacao();
}

function _renderPortalVotacao() {
    if (!S.assembleiaAtual) return;
    const a = S.assembleiaAtual;

    D.votacaoInfo.innerHTML = `
        <strong><i class="fas fa-landmark"></i> ${_esc(a.nome)}</strong> &nbsp;·&nbsp;
        ${_formatarData(a.data_assembleia)} &nbsp;·&nbsp;
        <span class="ass-status-badge ${a.status}">${_labelStatus(a.status)}</span>
        ${a.descricao ? `<div style="margin-top:8px;color:#1e40af;">${_esc(a.descricao)}</div>` : ''}
    `;

    const pautasVotacao = S.pautas.filter(p => p.tipo === 'votacao');
    if (!pautasVotacao.length) {
        D.votacaoPautas.innerHTML = '<div class="ass-empty">Nenhuma pauta de votação cadastrada.</div>';
        return;
    }

    D.votacaoPautas.innerHTML = pautasVotacao.map(p => {
        const jaVotou = S.meusVotos[p.id];
        const totalVotos = (p.votos_aprovado|0) + (p.votos_reprovado|0) + (p.votos_anulado|0);
        const pctAp = totalVotos ? Math.round((p.votos_aprovado/totalVotos)*100) : 0;
        const pctRp = totalVotos ? Math.round((p.votos_reprovado/totalVotos)*100) : 0;
        const pctAn = totalVotos ? 100 - pctAp - pctRp : 0;

        let acoes = '';
        if (p.status === 'em_votacao') {
            if (jaVotou) {
                acoes = `<div class="ass-voto-ja-registrado"><i class="fas fa-check-circle"></i> Você votou: <strong>${_labelVoto(jaVotou.voto)}</strong></div>`;
            } else {
                acoes = `<button class="btn-votar btn-votar-registrar" onclick="window.AssembleiaPage.abrirModalVotar(${p.id})"><i class="fas fa-vote-yea"></i> Votar Agora</button>`;
            }
            // Admin pode encerrar
            acoes += `<button class="btn-votar btn-votar-encerrar" onclick="window.AssembleiaPage.encerrarVotacao(${p.id})"><i class="fas fa-stop"></i> Encerrar Votação</button>`;
        } else if (p.status === 'pendente') {
            acoes = `<button class="btn-votar btn-votar-iniciar" onclick="window.AssembleiaPage.iniciarVotacao(${p.id})"><i class="fas fa-play"></i> Iniciar Votação</button>`;
        } else if (p.status === 'encerrado') {
            acoes = `<span class="ass-pauta-resultado-badge ${p.resultado}">${_labelResultado(p.resultado)}</span>`;
        }

        const votosBar = totalVotos > 0 ? `
            <div class="ass-votos-bar" style="height:12px;margin-top:12px;">
                <div class="ass-votos-bar-ap" style="width:${pctAp}%"></div>
                <div class="ass-votos-bar-rp" style="width:${pctRp}%"></div>
                <div class="ass-votos-bar-an" style="width:${pctAn}%"></div>
            </div>
            <div class="ass-votos-legenda">
                <span><span class="ass-votos-dot dot-ap"></span> Aprovado: ${p.votos_aprovado} (${pctAp}%)</span>
                <span><span class="ass-votos-dot dot-rp"></span> Não Aprovou: ${p.votos_reprovado} (${pctRp}%)</span>
                <span><span class="ass-votos-dot dot-an"></span> Anulado: ${p.votos_anulado} (${pctAn}%)</span>
                <span><strong>Total: ${totalVotos} voto(s)</strong></span>
            </div>
        ` : '<div style="font-size:.8rem;color:#94a3b8;margin-top:8px;">Nenhum voto registrado ainda.</div>';

        return `
        <div class="ass-votacao-pauta-card ${p.status} ${jaVotou ? 'votado' : ''}">
            <div class="ass-votacao-pauta-header">
                <div class="ass-votacao-pauta-titulo">${_esc(p.titulo)}</div>
                <span class="ass-pauta-status-badge ${p.status}">${_labelStatusPauta(p.status)}</span>
            </div>
            ${p.descricao ? `<div class="ass-votacao-pauta-desc">${_esc(p.descricao)}</div>` : ''}
            ${votosBar}
            <div class="ass-votacao-acoes" style="margin-top:14px;">${acoes}</div>
        </div>`;
    }).join('');
}

// ══════════════════════════════════════════════════════════
// RESULTADOS
// ══════════════════════════════════════════════════════════
function _renderResultados() {
    const pautasEncerradas = S.pautas.filter(p => p.tipo === 'votacao' && p.status === 'encerrado');
    if (!pautasEncerradas.length) {
        D.resultados.innerHTML = '<div class="ass-empty">Nenhuma votação encerrada ainda.</div>';
        return;
    }
    D.resultados.innerHTML = pautasEncerradas.map(p => {
        const total = (p.votos_aprovado|0) + (p.votos_reprovado|0) + (p.votos_anulado|0);
        const pctAp = total ? Math.round((p.votos_aprovado/total)*100) : 0;
        const pctRp = total ? Math.round((p.votos_reprovado/total)*100) : 0;
        const pctAn = total ? 100 - pctAp - pctRp : 0;
        return `
        <div class="ass-resultado-card">
            <div class="ass-resultado-titulo">${_esc(p.titulo)}</div>
            <div class="ass-resultado-final ${p.resultado}">
                <i class="fas ${p.resultado==='aprovado'?'fa-check-circle':p.resultado==='reprovado'?'fa-times-circle':'fa-ban'}"></i>
                ${_labelResultado(p.resultado)}
            </div>
            <div class="ass-resultado-numeros">
                <div class="ass-resultado-num">
                    <span class="ass-resultado-num-valor num-ap">${p.votos_aprovado}</span>
                    <span class="ass-resultado-num-label">Aprovado</span>
                </div>
                <div class="ass-resultado-num">
                    <span class="ass-resultado-num-valor num-rp">${p.votos_reprovado}</span>
                    <span class="ass-resultado-num-label">Não Aprovou</span>
                </div>
                <div class="ass-resultado-num">
                    <span class="ass-resultado-num-valor num-an">${p.votos_anulado}</span>
                    <span class="ass-resultado-num-label">Anulado</span>
                </div>
                <div class="ass-resultado-num">
                    <span class="ass-resultado-num-valor" style="color:#1e293b;">${total}</span>
                    <span class="ass-resultado-num-label">Total</span>
                </div>
            </div>
            <div class="ass-votos-bar" style="height:12px;">
                <div class="ass-votos-bar-ap" style="width:${pctAp}%"></div>
                <div class="ass-votos-bar-rp" style="width:${pctRp}%"></div>
                <div class="ass-votos-bar-an" style="width:${pctAn}%"></div>
            </div>
            <div class="ass-votos-legenda">
                <span><span class="ass-votos-dot dot-ap"></span> ${pctAp}% aprovação</span>
                <span><span class="ass-votos-dot dot-rp"></span> ${pctRp}% reprovação</span>
                <span><span class="ass-votos-dot dot-an"></span> ${pctAn}% anulados</span>
            </div>
        </div>`;
    }).join('');
}

// ══════════════════════════════════════════════════════════
// ANEXOS
// ══════════════════════════════════════════════════════════
function _renderAnexos() {
    D.badgeAnexos.textContent = S.anexos.length;
    if (!S.anexos.length) {
        D.listaAnexos.innerHTML = '<div class="ass-empty">Nenhum documento anexado.</div>';
        return;
    }
    D.listaAnexos.innerHTML = S.anexos.map(a => `
        <div class="ass-anexo-item">
            <div class="ass-anexo-icon"><i class="fas ${_iconeAnexo(a.mime_type)}"></i></div>
            <div class="ass-anexo-info">
                <div class="ass-anexo-nome">${_esc(a.nome_arquivo)}</div>
                <div class="ass-anexo-meta">${_formatarTamanho(a.tamanho_bytes)} · ${_formatarData(a.enviado_em)}</div>
            </div>
            <span class="ass-anexo-tipo-badge">${_labelTipoAnexo(a.tipo_anexo)}</span>
            <a href="${window.APP_BASE_PATH || '/'}${a.caminho_arquivo}" target="_blank" class="btn-icon"><i class="fas fa-download"></i></a>
            <button class="btn-danger" onclick="window.AssembleiaPage.excluirAnexo(${a.id})"><i class="fas fa-trash"></i></button>
        </div>
    `).join('');
}

async function _enviarAnexo() {
    if (!S.assembleiaAtual) { _toast('Nenhuma assembleia selecionada', 'error'); return; }
    if (!D.fileInput.files[0]) { _toast('Selecione um arquivo', 'warning'); return; }

    const form = new FormData();
    form.append('acao', 'upload_anexo');
    form.append('assembleia_id', S.assembleiaAtual.id);
    form.append('tipo_anexo', D.tipoAnexo.value);
    form.append('arquivo', D.fileInput.files[0]);

    D.btnEnviarArq.disabled = true;
    D.btnEnviarArq.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

    try {
        const res = await fetch(API, { method: 'POST', body: form, credentials: 'include' });
        const data = await res.json();
        if (!data?.ok) throw new Error(data?.erro || 'Erro no upload');
        _toast('Arquivo enviado com sucesso!', 'success');
        D.uploadForm.style.display = 'none';
        D.fileInput.value = '';
        D.fileNome.textContent = 'Clique para selecionar o arquivo';
        // Recarregar anexos
        const r2 = await _get(`acao=listar_anexos&assembleia_id=${S.assembleiaAtual.id}`);
        if (r2?.ok) { S.anexos = r2.dados; _renderAnexos(); }
    } catch(e) {
        _toast(e.message, 'error');
    } finally {
        D.btnEnviarArq.disabled = false;
        D.btnEnviarArq.innerHTML = '<i class="fas fa-upload"></i> Enviar';
    }
}

async function _excluirAnexo(id) {
    if (!confirm('Excluir este documento?')) return;
    const res = await _post({ acao: 'excluir_anexo', id });
    if (!res?.ok) { _toast(res?.erro || 'Erro', 'error'); return; }
    _toast('Documento excluído', 'success');
    S.anexos = S.anexos.filter(a => a.id != id);
    _renderAnexos();
}

// ══════════════════════════════════════════════════════════
// PARTICIPANTES
// ══════════════════════════════════════════════════════════
function _renderParticipantes() {
    D.badgePart.textContent = S.participantes.length;
    if (!S.participantes.length) {
        D.listaPart.innerHTML = '<div class="ass-empty">Nenhum participante registrado.</div>';
        return;
    }
    D.listaPart.innerHTML = S.participantes.map(p => `
        <div class="ass-part-item">
            <div class="ass-part-avatar">${(p.nome||'?')[0].toUpperCase()}</div>
            <div class="ass-part-info">
                <div class="ass-part-nome">${_esc(p.nome)}</div>
                <div class="ass-part-meta">${p.unidade ? _esc(p.unidade) + ' · ' : ''}${_formatarData(p.confirmado_em)}</div>
            </div>
            <span class="ass-part-tipo-badge ${p.tipo_participacao}">${_labelTipoPart(p.tipo_participacao)}</span>
        </div>
    `).join('');
}

async function _salvarParticipante() {
    const nome  = D.partNome.value.trim();
    const unid  = D.partUnidade.value.trim();
    const tipo  = D.partTipo.value;
    if (!nome) { _toast('Informe o nome do participante', 'warning'); return; }
    if (!S.assembleiaAtual) { _toast('Nenhuma assembleia selecionada', 'error'); return; }

    const res = await _post({ acao: 'registrar_participante', assembleia_id: S.assembleiaAtual.id, nome, unidade: unid, tipo_participacao: tipo });
    if (!res?.ok) { _toast(res?.erro || 'Erro', 'error'); return; }

    _toast('Participante registrado!', 'success');
    D.partForm.style.display = 'none';
    D.partNome.value = ''; D.partUnidade.value = '';

    const r2 = await _get(`acao=listar_participantes&assembleia_id=${S.assembleiaAtual.id}`);
    if (r2?.ok) { S.participantes = r2.dados; _renderParticipantes(); }
}

// ══════════════════════════════════════════════════════════
// STATUS
// ══════════════════════════════════════════════════════════
async function _alterarStatus(status) {
    if (!S.assembleiaAtual) return;
    const res = await _post({ acao: 'alterar_status', id: S.assembleiaAtual.id, status });
    if (!res?.ok) { _toast(res?.erro || 'Erro', 'error'); return; }
    S.assembleiaAtual.status = status;
    D.statusLabel.textContent = _labelStatus(status);
    _toast(`Status alterado para: ${_labelStatus(status)}`, 'success');
    _carregarAssembleias();
}

// ══════════════════════════════════════════════════════════
// MIGRATION
// ══════════════════════════════════════════════════════════
async function _runMigration() {
    await _post({ acao: 'migration' });
}

// ══════════════════════════════════════════════════════════
// HELPERS HTTP
// ══════════════════════════════════════════════════════════
async function _get(query) {
    try {
        const res = await fetch(`${API}?${query}`, { credentials: 'include' });
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { console.error('[Assembleia] Resposta não-JSON:', text.substring(0,200)); return null; }
    } catch(e) { console.error('[Assembleia] GET error:', e); return null; }
}

async function _post(data) {
    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(data),
        });
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { console.error('[Assembleia] Resposta não-JSON:', text.substring(0,200)); return null; }
    } catch(e) { console.error('[Assembleia] POST error:', e); return null; }
}

// ══════════════════════════════════════════════════════════
// HELPERS UI
// ══════════════════════════════════════════════════════════
function _fecharModal(modal) { if (modal) modal.style.display = 'none'; }

function _toast(msg, tipo = 'success') {
    if (!D.toast) return;
    D.toast.textContent = msg;
    D.toast.className   = `ass-toast ${tipo}`;
    D.toast.style.display = 'flex';
    clearTimeout(D.toast._t);
    D.toast._t = setTimeout(() => { D.toast.style.display = 'none'; }, 4000);
}

function _debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

function _esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _formatarData(str) {
    if (!str) return '—';
    const d = new Date(str.replace(' ','T'));
    if (isNaN(d)) return str;
    return d.toLocaleDateString('pt-BR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

function _formatarTamanho(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1048576).toFixed(1) + ' MB';
}

function _iconeTipo(tipo) {
    return { ordinaria:'fa-calendar-check', extraordinaria:'fa-exclamation-circle', deliberacao:'fa-gavel' }[tipo] || 'fa-landmark';
}

function _labelTipo(tipo) {
    return { ordinaria:'Ordinária', extraordinaria:'Extraordinária', deliberacao:'Deliberação' }[tipo] || tipo;
}

function _labelStatus(s) {
    return { rascunho:'Rascunho', convocada:'Convocada', em_andamento:'Em Andamento', encerrada:'Encerrada', cancelada:'Cancelada' }[s] || s;
}

function _labelTipoPauta(t) {
    return { informativo:'Informativo', votacao:'Votação', tema:'Tema Livre' }[t] || t;
}

function _labelStatusPauta(s) {
    return { pendente:'Pendente', em_votacao:'Em Votação', encerrado:'Encerrado' }[s] || s;
}

function _labelResultado(r) {
    return { aprovado:'Aprovado', reprovado:'Reprovado', anulado:'Anulado', sem_quorum:'Sem Quórum', pendente:'Pendente' }[r] || r;
}

function _labelVoto(v) {
    return { aprovado:'Aprovei', reprovado:'Não Aprovei', anulado:'Anulei' }[v] || v;
}

function _labelTipoAnexo(t) {
    return { convocacao:'Convocação', ata_encerramento:'Ata', documento:'Documento', outro:'Outro' }[t] || t;
}

function _labelTipoPart(t) {
    return { presencial:'Presencial', online:'Online', procuracao:'Procuração' }[t] || t;
}

function _iconeAnexo(mime) {
    if (!mime) return 'fa-file';
    if (mime.includes('pdf')) return 'fa-file-pdf';
    if (mime.includes('word') || mime.includes('doc')) return 'fa-file-word';
    if (mime.includes('image')) return 'fa-file-image';
    return 'fa-file-alt';
}

// ══════════════════════════════════════════════════════════
// API PÚBLICA (chamada pelo HTML via onclick)
// ══════════════════════════════════════════════════════════
window.AssembleiaPage = {
    abrirDetalhe:   _abrirDetalhe,
    editarPauta:    _editarPauta,
    excluirPauta:   _excluirPauta,
    iniciarVotacao: _iniciarVotacao,
    encerrarVotacao: _encerrarVotacao,
    abrirModalVotar: _abrirModalVotar,
    excluirAnexo:   _excluirAnexo,
    _dragStart,
    _dragOver,
    _dragDrop,
};
