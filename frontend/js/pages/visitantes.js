/**
 * Visitantes Page Module v3
 * Regra de negócio: cadastro puro do visitante (sem registro de acesso).
 * O controle de acesso (unidade, morador, datas) fica no módulo Lançamento Manual.
 * Suporte: RG/CPF com máscara, foto, documento digitalizado, telefone.
 */

const API_VISITANTES = '../api/api_visitantes.php';

// Import do componente de webcam (ES Module)
import { WebcamCapture } from '../components/webcam-capture.js';

let visitantesCache   = [];
let modoEdicao        = false;
let visitanteIdEdicao = null;
let fotoArquivo       = null;
let docArquivo        = null;
let salvando          = false;

export function init() {
    console.log('[Visitantes] Inicializando v3...');
    _setupAbas();
    _setupMascaras();
    _setupForm();
    _setupBusca();
    _setupActions();
    _setupUploads();
    _resetForm();
    _carregarVisitantes();

    window.VisitantesPage = {
        buscar:         _buscarVisitantes,
        editar:         _editarVisitante,
        excluir:        _excluirVisitante,
        cancelarEdicao: _resetForm,
        verFoto:        _verFoto,
        verDoc:         _verDoc,
        relExportarCSV: relExportarCSV,
        relGerarPDF:    relGerarPDF,
        relAtualizar:   _relAtualizar
    };
    console.log('[Visitantes] Módulo pronto.');
}

// ===== CONTROLE DE ABAS =====
function _setupAbas() {
    const tabBtns = document.querySelectorAll('.page-visitantes .vis-tab-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            tabBtns.forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.page-visitantes .vis-tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            const content = document.getElementById('vis-tab-' + tab);
            if (content) content.classList.add('active');
            if (tab === 'listagem') _carregarVisitantes();
            if (tab === 'relatorios') _relAtualizar();
        });
    });
}

function _atualizarKpis(lista) {
    const total   = lista.length;
    const ativos  = lista.filter(v => v.ativo == 1 || v.ativo === true).length;
    const comFoto = lista.filter(v => v.foto).length;
    const comDoc  = lista.filter(v => v.documento_arquivo).length;
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('kpiTotalVisitantes', total);
    set('kpiAtivos', ativos);
    set('kpiComFoto', comFoto);
    set('kpiComDoc', comDoc);
}

function _relAtualizar() {
    const filtros = _relColetarFiltros();
    const dados   = _relFiltrarCache(filtros);
    const preview = document.getElementById('relPreviewVisitantes');
    const ativos   = dados.filter(v => (v.ativo ?? 1) == 1).length;
    const inativos = dados.length - ativos;
    const comFoto  = dados.filter(v => v.foto).length;
    const comDoc   = dados.filter(v => v.documento_arquivo).length;
    const kpis = document.getElementById('relKpisVisitantes');
    if (kpis) {
        kpis.style.display = 'flex';
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        set('relKpiTotal',    dados.length);
        set('relKpiAtivos',   ativos);
        set('relKpiInativos', inativos);
        set('relKpiComFoto',  comFoto);
        set('relKpiComDoc',   comDoc);
    }
    _relMostrarPreview(dados, preview);
}

export function destroy() {
    console.log('[Visitantes] Limpando...');
    delete window.VisitantesPage;
    visitantesCache   = [];
    modoEdicao        = false;
    visitanteIdEdicao = null;
    fotoArquivo       = null;
    docArquivo        = null;
    salvando          = false;
}

// ===== MÁSCARAS =====
function _setupMascaras() {
    const tipoDoc  = document.getElementById('tipoDocumento');
    const docInput = document.getElementById('documento');
    if (tipoDoc && docInput) {
        tipoDoc.addEventListener('change', () => {
            docInput.value = '';
            docInput.placeholder = tipoDoc.value === 'CPF' ? '000.000.000-00' : 'XX.XXX.XXX-X';
            _aplicarMascaraDoc(docInput, tipoDoc.value);
        });
        docInput.addEventListener('input', () => {
            _aplicarMascaraDoc(docInput, tipoDoc?.value || 'CPF');
        });
    }

    ['telefoneContato', 'celularVisitante'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => _mascaraTelefone(el));
    });
}

function _aplicarMascaraDoc(input, tipo) {
    let v = input.value.replace(/\D/g, '');
    if (tipo === 'CPF') {
        v = v.slice(0, 11);
        if (v.length > 9)      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
    } else {
        // RG: XX.XXX.XXX-X
        v = v.slice(0, 9);
        if (v.length > 8)      v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{1})/, '$1.$2.$3-$4');
        else if (v.length > 5) v = v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 2) v = v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
    }
    input.value = v;
}

function _mascaraTelefone(input) {
    let v = input.value.replace(/\D/g, '').slice(0, 11);
    if (v.length > 10)     v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
    else if (v.length > 6) v = v.replace(/(\d{2})(\d{4,5})(\d{0,4})/, '($1) $2-$3');
    else if (v.length > 2) v = v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
    input.value = v;
}

// ===== UPLOADS =====
function _setupUploads() {
    // Foto — botão de câmera (webcam) e botão de arquivo separados
    const btnFoto         = document.getElementById('btnSelecionarFoto');     // abre webcam
    const btnArquivoFoto  = document.getElementById('btnEscolherArquivoFoto'); // abre file picker
    const fotoInput       = document.getElementById('fotoInput');
    const btnRemFoto      = document.getElementById('btnRemoverFoto');

    // Função auxiliar: aplica preview de foto
    function _aplicarPreviewFoto(file, dataUrl) {
        fotoArquivo = file;
        const img = document.getElementById('fotoPreview');
        const ph  = document.getElementById('fotoPlaceholder');
        if (img) { img.src = dataUrl; img.style.display = 'block'; }
        if (ph)  ph.style.display = 'none';
        if (btnRemFoto) btnRemFoto.style.display = 'inline-flex';
        console.log('[Visitantes] Foto definida:', file.name, Math.round(file.size / 1024) + 'KB');
    }

    // Botão CÂMERA → abre WebcamCapture
    if (btnFoto) {
        btnFoto.addEventListener('click', () => {
            const cam = new WebcamCapture({
                targetWidth:  800,
                targetHeight: 600,
                quality:      0.88,
                maxSizeKB:    500,
                onCapture: (file, dataUrl) => {
                    _aplicarPreviewFoto(file, dataUrl);
                    _mostrarAlerta('success', 'Foto capturada com sucesso!');
                },
                onCancel: () => {
                    console.log('[Visitantes] Captura de foto cancelada.');
                }
            });
            cam.open();
        });
    }

    // Botão ARQUIVO → abre file picker (fallback)
    if (btnArquivoFoto && fotoInput) {
        btnArquivoFoto.addEventListener('click', () => fotoInput.click());
        fotoInput.addEventListener('change', () => {
            const file = fotoInput.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                _mostrarAlerta('error', 'Foto muito grande. Máximo: 5MB');
                fotoInput.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = e => _aplicarPreviewFoto(file, e.target.result);
            reader.readAsDataURL(file);
        });
    }
    if (btnRemFoto) {
        btnRemFoto.addEventListener('click', () => {
            fotoArquivo = null;
            if (fotoInput) fotoInput.value = '';
            const img = document.getElementById('fotoPreview');
            const ph  = document.getElementById('fotoPlaceholder');
            if (img) { img.src = ''; img.style.display = 'none'; }
            if (ph)  ph.style.display = 'flex';
            btnRemFoto.style.display = 'none';
        });
    }

    // Documento
    const btnDoc    = document.getElementById('btnSelecionarDoc');
    const docInput  = document.getElementById('docInput');
    const btnRemDoc = document.getElementById('btnRemoverDoc');

    if (btnDoc && docInput) {
        btnDoc.addEventListener('click', () => docInput.click());
        docInput.addEventListener('change', () => {
            const file = docInput.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                _mostrarAlerta('error', 'Documento muito grande. Máximo: 5MB');
                docInput.value = '';
                return;
            }
            docArquivo = file;
            const preview = document.getElementById('docPreview');
            const ph      = document.getElementById('docPlaceholder');
            const nome    = document.getElementById('docNomeArquivo');
            if (preview) preview.style.display = 'block';
            if (ph)      ph.style.display = 'none';
            if (nome)    nome.textContent = file.name;
            if (btnRemDoc) btnRemDoc.style.display = 'inline-flex';
        });
    }
    if (btnRemDoc) {
        btnRemDoc.addEventListener('click', () => {
            docArquivo = null;
            if (docInput) docInput.value = '';
            const preview = document.getElementById('docPreview');
            const ph      = document.getElementById('docPlaceholder');
            if (preview) preview.style.display = 'none';
            if (ph)      ph.style.display = 'flex';
            btnRemDoc.style.display = 'none';
        });
    }
}

// ===== FORM =====
function _setupForm() {
    const form = document.getElementById('visitanteForm');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await _salvarVisitante();
    });
}

function _setupBusca() {
    const input = document.getElementById('searchVisitante');
    if (!input) return;
    input.addEventListener('input', () => _filtrarVisitantes(input.value));
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); _buscarVisitantes(); }
    });
}

function _setupActions() {
    const btnBuscar   = document.getElementById('btnBuscarVisitante');
    const btnCancelar = document.getElementById('btnCancelarEdicaoVisitante');
    if (btnBuscar)   btnBuscar.addEventListener('click', _buscarVisitantes);
    if (btnCancelar) btnCancelar.addEventListener('click', _resetForm);
}

// ===== CARREGAR VISITANTES =====
async function _carregarVisitantes() {
    const tbody = document.querySelector('#tabelaVisitantes tbody');
    _setLoading(true);
    try {
        const response = await fetch(API_VISITANTES);
        const data = await response.json();
        console.log('[Visitantes] Resposta da API:', data);
        if (!data.sucesso) {
            _renderMensagemTabela(tbody, data.mensagem || 'Erro ao carregar visitantes.');
            return;
        }
        visitantesCache = Array.isArray(data.dados) ? data.dados : [];
        _renderVisitantes(visitantesCache);
    } catch (error) {
        console.error('[Visitantes] Erro ao carregar:', error);
        _renderMensagemTabela(tbody, 'Erro de conexão ao carregar dados.');
    } finally {
        _setLoading(false);
    }
}

function _buscarVisitantes() {
    const termo = document.getElementById('searchVisitante')?.value || '';
    _filtrarVisitantes(termo);
}

function _filtrarVisitantes(termo) {
    if (!termo?.trim()) { _renderVisitantes(visitantesCache); return; }
    const q = termo.toLowerCase().trim();
    const filtrados = visitantesCache.filter(v =>
        (v.nome_completo || '').toLowerCase().includes(q)
        || (v.documento || '').toLowerCase().includes(q)
        || (v.telefone_contato || '').toLowerCase().includes(q)
        || (v.celular || '').toLowerCase().includes(q)
    );
    _renderVisitantes(filtrados);
}

function _renderVisitantes(visitantes) {
    const tbody = document.querySelector('#tabelaVisitantes tbody');
    if (!tbody) return;
    if (!visitantes?.length) {
        _renderMensagemTabela(tbody, 'Nenhum visitante cadastrado.');
        return;
    }
    tbody.innerHTML = visitantes.map(v => {
        const id        = v.id || '-';
        const nome      = _esc(v.nome_completo || '-');
        const tipoDoc   = _esc(v.tipo_documento || 'CPF');
        const doc       = _esc(v.documento || '-');
        const telefone  = _esc(v.telefone_contato || v.telefone || '-');
        const fotoUrl   = v.foto || '';
        const docUrl    = v.documento_arquivo || '';
        const ativo     = v.ativo == 1 ? '<span style="color:#27ae60;font-weight:600;">Ativo</span>' : '<span style="color:#e74c3c;">Inativo</span>';

        const fotoHtml = fotoUrl
            ? `<img src="${_esc(fotoUrl)}" class="foto-thumb" alt="Foto" onclick="window.VisitantesPage.verFoto('${_esc(fotoUrl)}')" style="cursor:pointer">`
            : `<span style="color:#ccc;font-size:20px;"><i class="fas fa-user-circle"></i></span>`;

        const docHtml = docUrl
            ? `<a href="#" class="doc-link" onclick="window.VisitantesPage.verDoc('${_esc(docUrl)}');return false;"><i class="fas fa-file-alt"></i> Ver</a>`
            : '<span style="color:#bbb">—</span>';

        return `
            <tr>
                <td>${id}</td>
                <td style="text-align:center">${fotoHtml}</td>
                <td><strong>${nome}</strong></td>
                <td>${tipoDoc}</td>
                <td>${doc}</td>
                <td>${telefone}</td>
                <td style="text-align:center">${docHtml}</td>
                <td style="text-align:center">${ativo}</td>
                <td>
                    <button class="action-btn edit" type="button" onclick="window.VisitantesPage.editar(${id})" title="Editar">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="action-btn delete" type="button" onclick="window.VisitantesPage.excluir(${id})" title="Excluir">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`;
    }).join('');
}

function _renderMensagemTabela(tbody, msg) {
    if (tbody) tbody.innerHTML = `<tr><td colspan="9" class="empty-state">${_esc(msg)}</td></tr>`;
}

// ===== SALVAR =====
async function _salvarVisitante() {
    if (salvando) return;
    salvando = true;
    const btn = document.getElementById('btnSalvarVisitante');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    try {
        const nome            = document.getElementById('nomeVisitante')?.value.trim() || '';
        const tipoDoc         = document.getElementById('tipoDocumento')?.value || 'CPF';
        const documento       = document.getElementById('documento')?.value.trim() || '';
        const telefoneContato = document.getElementById('telefoneContato')?.value.trim() || '';
        const celular         = document.getElementById('celularVisitante')?.value.trim() || '';
        const email           = document.getElementById('emailVisitante')?.value.trim() || '';
        const observacao      = document.getElementById('observacaoVisitante')?.value.trim() || '';

        // Validações
        if (!nome)            { _mostrarAlerta('error', 'Nome completo é obrigatório.'); return; }
        if (!documento)       { _mostrarAlerta('error', 'Documento é obrigatório.'); return; }
        if (!telefoneContato) { _mostrarAlerta('error', 'Telefone de contato é obrigatório.'); return; }

        // Validar CPF
        if (tipoDoc === 'CPF') {
            const cpfLimpo = documento.replace(/\D/g, '');
            if (cpfLimpo.length !== 11) { _mostrarAlerta('error', 'CPF inválido — deve ter 11 dígitos.'); return; }
        }

        const payload = {
            nome_completo:    nome,
            documento,
            tipo_documento:   tipoDoc,
            telefone_contato: telefoneContato,
            celular,
            email,
            observacao
        };

        let visitanteId = visitanteIdEdicao;
        const method = modoEdicao ? 'PUT' : 'POST';
        if (modoEdicao) payload.id = visitanteIdEdicao;

        // Salvar/atualizar visitante
        const resp = await fetch(API_VISITANTES, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        console.log('[Visitantes] Resposta salvar:', data);

        // Se duplicado no POST, usar o ID existente
        if (!data.sucesso && data.dados?.duplicado) {
            visitanteId = data.dados.id;
            console.log(`[Visitantes] Visitante já existe (ID ${visitanteId}), usando cadastro existente.`);
        } else if (!data.sucesso) {
            _mostrarAlerta('error', data.mensagem || 'Erro ao salvar visitante.');
            return;
        } else {
            visitanteId = data.dados?.id || visitanteIdEdicao;
        }

        // Upload de foto (se selecionada)
        if (fotoArquivo && visitanteId) {
            const fd = new FormData();
            fd.append('foto', fotoArquivo);
            const r = await fetch(`${API_VISITANTES}?acao=upload&tipo=foto&visitante_id=${visitanteId}`, {
                method: 'POST', body: fd
            });
            const d = await r.json();
            if (!d.sucesso) console.warn('[Visitantes] Aviso upload foto:', d.mensagem);
        }

        // Upload de documento (se selecionado)
        if (docArquivo && visitanteId) {
            const fd = new FormData();
            fd.append('documento', docArquivo);
            const r = await fetch(`${API_VISITANTES}?acao=upload&tipo=documento&visitante_id=${visitanteId}`, {
                method: 'POST', body: fd
            });
            const d = await r.json();
            if (!d.sucesso) console.warn('[Visitantes] Aviso upload doc:', d.mensagem);
        }

        _mostrarAlerta('success', modoEdicao ? 'Visitante atualizado com sucesso!' : 'Visitante cadastrado com sucesso!');
        _resetForm();
        await _carregarVisitantes();

    } catch (error) {
        console.error('[Visitantes] Erro ao salvar:', error);
        _mostrarAlerta('error', 'Erro interno ao salvar visitante.');
    } finally {
        salvando = false;
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar Visitante'; }
    }
}

// ===== EDITAR =====
function _editarVisitante(id) {
    const v = visitantesCache.find(x => Number(x.id) === Number(id));
    if (!v) return;

    modoEdicao        = true;
    visitanteIdEdicao = Number(id);

    document.getElementById('visitanteId').value         = String(id);
    document.getElementById('nomeVisitante').value        = v.nome_completo || '';
    document.getElementById('tipoDocumento').value        = v.tipo_documento || 'CPF';
    document.getElementById('documento').value            = v.documento || '';
    document.getElementById('telefoneContato').value      = v.telefone_contato || v.telefone || '';
    document.getElementById('celularVisitante').value     = v.celular || '';
    document.getElementById('emailVisitante').value       = v.email || '';
    document.getElementById('observacaoVisitante').value  = v.observacao || '';

    // Foto existente
    if (v.foto) {
        const img    = document.getElementById('fotoPreview');
        const ph     = document.getElementById('fotoPlaceholder');
        const btnRem = document.getElementById('btnRemoverFoto');
        if (img) { img.src = v.foto; img.style.display = 'block'; }
        if (ph)  ph.style.display = 'none';
        if (btnRem) btnRem.style.display = 'inline-flex';
    }

    // Documento existente
    if (v.documento_arquivo) {
        const preview = document.getElementById('docPreview');
        const ph      = document.getElementById('docPlaceholder');
        const nome    = document.getElementById('docNomeArquivo');
        const btnRem  = document.getElementById('btnRemoverDoc');
        if (preview) preview.style.display = 'block';
        if (ph)      ph.style.display = 'none';
        if (nome)    nome.textContent = v.documento_arquivo.split('/').pop();
        if (btnRem)  btnRem.style.display = 'inline-flex';
    }

    const titulo = document.getElementById('tituloFormVisitante');
    if (titulo) titulo.innerHTML = '<i class="fas fa-user-edit"></i> Editar Visitante';

    const btnSalvar   = document.getElementById('btnSalvarVisitante');
    const btnCancelar = document.getElementById('btnCancelarEdicaoVisitante');
    if (btnSalvar)   btnSalvar.innerHTML = '<i class="fas fa-sync"></i> Atualizar Visitante';
    if (btnCancelar) btnCancelar.style.display = 'inline-flex';

    document.getElementById('visitanteForm')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ===== EXCLUIR =====
async function _excluirVisitante(id) {
    if (!confirm('Deseja realmente excluir este visitante?')) return;
    try {
        const response = await fetch(API_VISITANTES, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: Number(id) })
        });
        const data = await response.json();
        if (!data.sucesso) {
            _mostrarAlerta('error', data.mensagem || 'Falha ao excluir visitante.');
            return;
        }
        if (modoEdicao && Number(visitanteIdEdicao) === Number(id)) _resetForm();
        await _carregarVisitantes();
    } catch (error) {
        console.error('[Visitantes] Erro ao excluir:', error);
        _mostrarAlerta('error', 'Erro de conexão ao excluir visitante.');
    }
}

// ===== VER FOTO / DOCUMENTO =====
function _verFoto(url) { window.open(url, '_blank'); }
function _verDoc(url)  { window.open(url, '_blank'); }

// ===== RESET FORM =====
function _resetForm() {
    const form = document.getElementById('visitanteForm');
    if (form) form.reset();

    document.getElementById('visitanteId').value = '';
    fotoArquivo       = null;
    docArquivo        = null;
    modoEdicao        = false;
    visitanteIdEdicao = null;

    // Reset foto preview
    const img        = document.getElementById('fotoPreview');
    const fph        = document.getElementById('fotoPlaceholder');
    const btnRemFoto = document.getElementById('btnRemoverFoto');
    if (img) { img.src = ''; img.style.display = 'none'; }
    if (fph) fph.style.display = 'flex';
    if (btnRemFoto) btnRemFoto.style.display = 'none';

    // Reset doc preview
    const docPrev   = document.getElementById('docPreview');
    const dph       = document.getElementById('docPlaceholder');
    const btnRemDoc = document.getElementById('btnRemoverDoc');
    if (docPrev) docPrev.style.display = 'none';
    if (dph)     dph.style.display = 'flex';
    if (btnRemDoc) btnRemDoc.style.display = 'none';

    const titulo = document.getElementById('tituloFormVisitante');
    if (titulo) titulo.innerHTML = '<i class="fas fa-user-plus"></i> Cadastro de Visitante';

    const btnSalvar   = document.getElementById('btnSalvarVisitante');
    const btnCancelar = document.getElementById('btnCancelarEdicaoVisitante');
    if (btnSalvar)   { btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Visitante'; btnSalvar.disabled = false; }
    if (btnCancelar) btnCancelar.style.display = 'none';
}

// ===== UTILITÁRIOS =====
function _setLoading(ativo) {
    const el = document.getElementById('loadingVisitantes');
    if (el) el.style.display = ativo ? 'block' : 'none';
}

function _mostrarAlerta(tipo, mensagem) {
    const box = document.getElementById('alertBoxVisitante');
    if (!box) { alert(mensagem); return; }
    const classe = tipo === 'success' ? 'alert-success' : tipo === 'warning' ? 'alert-warning' : 'alert-error';
    const icone  = tipo === 'success' ? 'fa-check-circle' : tipo === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle';
    box.innerHTML = `<div class="alert ${classe}"><i class="fas ${icone}"></i> ${_esc(mensagem)}</div>`;
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    setTimeout(() => { box.innerHTML = ''; }, 6000);
}

function _esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ===== RELATORIOS =====

function _relColetarFiltros() {
    return {
        nome:     (document.getElementById('relNome')?.value     || '').trim(),
        cpf:      (document.getElementById('relCpf')?.value      || '').trim(),
        email:    (document.getElementById('relEmail')?.value    || '').trim(),
        tem_foto: (document.getElementById('relTemFoto')?.value  || ''),
        tem_doc:  (document.getElementById('relTemDoc')?.value   || ''),
        ativo:    (document.getElementById('relAtivo')?.value    || '')
    };
}

function _relFiltrarCache(filtros) {
    return visitantesCache.filter(v => {
        const nome  = (v.nome_completo || '').toLowerCase();
        const doc   = (v.documento || '').toLowerCase().replace(/\D/g, '');
        const email = (v.email || '').toLowerCase();
        const foto  = !!(v.foto);
        const docA  = !!(v.documento_arquivo);
        const ativo = String(v.ativo ?? '1');

        if (filtros.nome    && !nome.includes(filtros.nome.toLowerCase()))  return false;
        if (filtros.cpf     && !doc.includes(filtros.cpf.replace(/\D/g, ''))) return false;
        if (filtros.email   && !email.includes(filtros.email.toLowerCase())) return false;
        if (filtros.tem_foto === 'sim' && !foto)  return false;
        if (filtros.tem_foto === 'nao' &&  foto)  return false;
        if (filtros.tem_doc  === 'sim' && !docA)  return false;
        if (filtros.tem_doc  === 'nao' &&  docA)  return false;
        if (filtros.ativo === '1' && ativo !== '1') return false;
        if (filtros.ativo === '0' && ativo !== '0') return false;
        return true;
    });
}

function relExportarCSV() {
    const filtros    = _relColetarFiltros();
    const dados      = _relFiltrarCache(filtros);
    const preview    = document.getElementById('relPreviewVisitantes');

    if (!dados.length) {
        if (preview) preview.innerHTML = '<p style="color:#e74c3c;padding:10px;"><i class="fas fa-exclamation-circle"></i> Nenhum visitante encontrado com os filtros aplicados.</p>';
        return;
    }

    // Cabecalho CSV
    const cabecalho = ['ID','Nome Completo','Tipo Documento','Documento','E-mail','Telefone','Celular','Placa Veiculo','Possui Foto','Possui Documento','Status','Data Cadastro'];
    const linhas    = dados.map(v => [
        v.id || '',
        v.nome_completo || '',
        v.tipo_documento || 'CPF',
        v.documento || '',
        v.email || '',
        v.telefone_contato || '',
        v.celular || '',
        v.placa_veiculo || '',
        v.foto ? 'Sim' : 'Nao',
        v.documento_arquivo ? 'Sim' : 'Nao',
        (v.ativo == 1) ? 'Ativo' : 'Inativo',
        v.data_cadastro_formatada || ''
    ].map(c => '"' + String(c).replace(/"/g, '""') + '"'));

    const csv = [cabecalho.join(','), ...linhas.map(l => l.join(','))].join('\n');
    const bom = '\uFEFF';
    const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'relatorio_visitantes_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    // Exibir preview
    _relMostrarPreview(dados, preview);
}

function relGerarPDF() {
    const filtros = _relColetarFiltros();
    const base    = window.location.origin + '/api/api_relatorio_visitantes_pdf.php';
    const params  = new URLSearchParams();
    if (filtros.nome)     params.set('nome',     filtros.nome);
    if (filtros.cpf)      params.set('cpf',      filtros.cpf);
    if (filtros.email)    params.set('email',    filtros.email);
    if (filtros.tem_foto) params.set('tem_foto', filtros.tem_foto);
    if (filtros.tem_doc)  params.set('tem_doc',  filtros.tem_doc);
    if (filtros.ativo)    params.set('ativo',    filtros.ativo);
    window.open(base + '?' + params.toString(), '_blank');
}

function _relMostrarPreview(dados, container) {
    if (!container) return;
    const total    = dados.length;
    const comFoto  = dados.filter(v => v.foto).length;
    const comDoc   = dados.filter(v => v.documento_arquivo).length;
    const ativos   = dados.filter(v => (v.ativo ?? 1) == 1).length;

    const kpis = `<div class="rel-kpis">
        <div class="rel-kpi"><div class="rel-kpi-valor">${total}</div><div class="rel-kpi-label">Total</div></div>
        <div class="rel-kpi"><div class="rel-kpi-valor">${ativos}</div><div class="rel-kpi-label">Ativos</div></div>
        <div class="rel-kpi"><div class="rel-kpi-valor">${comFoto}</div><div class="rel-kpi-label">Com Foto</div></div>
        <div class="rel-kpi"><div class="rel-kpi-valor">${comDoc}</div><div class="rel-kpi-label">Com Documento</div></div>
    </div>`;

    const linhas = dados.slice(0, 50).map(v => `
        <tr>
            <td>${_esc(v.nome_completo || '—')}</td>
            <td>${_esc(v.tipo_documento || 'CPF')}</td>
            <td>${_esc(v.documento || '—')}</td>
            <td>${_esc(v.email || '—')}</td>
            <td>${_esc(v.telefone_contato || v.celular || '—')}</td>
            <td style="text-align:center"><span class="rel-badge ${v.foto ? 'rel-badge-sim' : 'rel-badge-nao'}">${v.foto ? 'Sim' : 'Nao'}</span></td>
            <td style="text-align:center"><span class="rel-badge ${v.documento_arquivo ? 'rel-badge-sim' : 'rel-badge-nao'}">${v.documento_arquivo ? 'Sim' : 'Nao'}</span></td>
            <td style="text-align:center"><span class="rel-badge ${(v.ativo==1) ? 'rel-badge-ativo' : 'rel-badge-inativo'}">${(v.ativo==1) ? 'Ativo' : 'Inativo'}</span></td>
        </tr>`).join('');

    const aviso = total > 50 ? `<p style="font-size:11px;color:#64748b;margin-top:6px;">Exibindo 50 de ${total} registros. O CSV/PDF contera todos.</p>` : '';

    container.innerHTML = kpis + `
        <table>
            <thead><tr>
                <th>Nome</th><th>Tipo</th><th>Documento</th><th>E-mail</th>
                <th>Telefone</th><th>Foto</th><th>Doc.</th><th>Status</th>
            </tr></thead>
            <tbody>${linhas}</tbody>
        </table>` + aviso;
}
