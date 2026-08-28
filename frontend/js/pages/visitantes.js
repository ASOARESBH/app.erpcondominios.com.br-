/**
 * Visitantes Page Module v3
 * Regra de negócio: cadastro puro do visitante (sem registro de acesso).
 * O controle de acesso (unidade, morador, datas) fica no módulo Lançamento Manual.
 * Suporte: RG/CPF com máscara, foto, documento digitalizado, telefone.
 */

const API_VISITANTES = '../api/api_visitantes.php';
const API_CONFIG_VISITANTES = '../api/api_config_visitantes.php';

// Import do componente de webcam (ES Module)
import { WebcamCapture } from '../components/webcam-capture.js';

let visitantesCache   = [];
let modoEdicao        = false;
let visitanteIdEdicao = null;
let fotoArquivo       = null;
let docArquivo        = null;
let fotoExistente     = false;
let documentoExistente = false;
let salvando          = false;
let relatorioAnaliticoCache = null;
let configuracaoCampos = {};
let visitantesPaginacao = { total: 0, pagina: 1, limite: 50, total_paginas: 0 };
let visitantesBuscaAtual = '';

export async function init() {
    console.log('[Visitantes] Inicializando v3...');
    _setupAbas();
    _setupMascaras();
    _setupForm();
    _setupBusca();
    _setupActions();
    _setupUploads();
    _setupRelatorios();
    _resetForm();
    await _carregarConfiguracaoCampos();
    _renderMensagemTabela(document.querySelector('#tabelaVisitantes tbody'), 'Informe um termo e clique em Buscar para consultar os visitantes.');

    window.VisitantesPage = {
        buscar:         _buscarVisitantes,
        irPagina:       _irPaginaVisitantes,
        editar:         _editarVisitante,
        excluir:        _excluirVisitante,
        cancelarEdicao: _resetForm,
        verFoto:        _verFoto,
        verDoc:         _verDoc,
        relExportarCSV: relExportarCSV,
        relGerarPDF:    relGerarPDF,
        relAtualizar:   _relAtualizar,
        relAlternarTipo: _relAlternarTipo
    };
    console.log('[Visitantes] Módulo pronto.');
}

async function _carregarConfiguracaoCampos() {
    try {
        const resposta = await fetch(API_CONFIG_VISITANTES, { credentials: 'include' });
        const dados = await resposta.json();
        if (!resposta.ok || !dados?.sucesso) throw new Error(dados?.mensagem || 'Configuração indisponível');
        configuracaoCampos = Object.fromEntries((dados.dados?.campos || []).map(campo => [campo.campo, campo]));
        _aplicarConfiguracaoCampos();
        console.debug('[Visitantes][ConfigCampos] Configuração aplicada', configuracaoCampos);
    } catch (erro) {
        console.warn('[Visitantes][ConfigCampos] Mantendo a regra padrão por indisponibilidade da configuração:', erro.message);
        configuracaoCampos = {
            nome_completo: { obrigatorio: true, rotulo: 'Nome completo' },
            tipo_documento: { obrigatorio: true, rotulo: 'Tipo de documento' },
            documento: { obrigatorio: true, rotulo: 'Número do documento' },
            telefone_contato: { obrigatorio: true, rotulo: 'Telefone de contato' },
            anexo_evidencia: { obrigatorio: true, rotulo: 'Foto ou documento digitalizado' },
            foto: { obrigatorio: false, rotulo: 'Foto do visitante' },
            documento_digitalizado: { obrigatorio: false, rotulo: 'Documento digitalizado' },
        };
        _aplicarConfiguracaoCampos();
    }
}

function _campoObrigatorio(campo) {
    return Boolean(configuracaoCampos[campo]?.obrigatorio);
}

function _aplicarConfiguracaoCampos() {
    const campos = [
        ['nome_completo', 'nomeVisitante'],
        ['tipo_documento', 'tipoDocumento'],
        ['documento', 'documento'],
        ['telefone_contato', 'telefoneContato'],
        ['celular', 'celularVisitante'],
        ['email', 'emailVisitante'],
        ['observacao', 'observacaoVisitante'],
    ];
    campos.forEach(([campo, id]) => {
        const input = document.getElementById(id);
        const label = document.querySelector(`[for="${id}"]`);
        const obrigatorio = _campoObrigatorio(campo);
        if (input) input.required = obrigatorio;
        if (label) label.innerHTML = `${_esc(configuracaoCampos[campo]?.rotulo || label.textContent.replace(/\s*\*$/, ''))}${obrigatorio ? ' <span class="campo-obrigatorio-indicador">*</span>' : ''}`;
    });

    [['foto', 'fotoPreviewBox', 'Foto do Visitante'], ['documento_digitalizado', 'docPreviewBox', 'Documento Digitalizado']].forEach(([campo, id, rotulo]) => {
        const container = document.getElementById(id)?.closest('.upload-group');
        const label = container?.querySelector('label');
        const obrigatorio = _campoObrigatorio(campo);
        if (container) container.dataset.obrigatorio = obrigatorio ? '1' : '0';
        if (label) label.innerHTML = `${rotulo}${obrigatorio ? ' <span class="campo-obrigatorio-indicador">*</span>' : ''}`;
    });

    const nota = document.querySelector('.upload-requirement-note');
    if (nota) {
        const foto = _campoObrigatorio('foto');
        const documento = _campoObrigatorio('documento_digitalizado');
        const evidencia = _campoObrigatorio('anexo_evidencia');
        const texto = foto && documento ? 'Envie obrigatoriamente a foto e o documento digitalizado.'
            : foto ? 'Envie obrigatoriamente a foto do visitante.'
            : documento ? 'Envie obrigatoriamente o documento digitalizado.'
            : evidencia ? 'Envie uma foto do visitante, um documento digitalizado ou ambos.'
            : 'Os anexos são opcionais conforme a configuração deste condomínio.';
        nota.innerHTML = `<i class="fas fa-paperclip"></i> <strong>Regra de anexos:</strong> ${_esc(texto)}`;
    }
}

// ===== CONTROLE DE ABAS =====
function _setupAbas() {
    document.querySelectorAll('.page-visitantes .vis-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => _ativarAba(btn.dataset.tab));
    });
}

/**
 * Centraliza a troca de abas. A edição também usa este fluxo para que o
 * formulário preenchido nunca permaneça invisível atrás da aba Listagem.
 */
function _ativarAba(tab, { atualizarDados = true } = {}) {
    const tabBtns = document.querySelectorAll('.page-visitantes .vis-tab-btn');
    const content = document.getElementById('vis-tab-' + tab);
    const botaoAtivo = Array.from(tabBtns).find(btn => btn.dataset.tab === tab);

    if (!content || !botaoAtivo) {
        console.error('[Visitantes][Abas] Aba não encontrada:', { tab, content: !!content, botao: !!botaoAtivo });
        return false;
    }

    tabBtns.forEach(btn => btn.classList.toggle('active', btn === botaoAtivo));
    document.querySelectorAll('.page-visitantes .vis-tab-content').forEach(item => {
        item.classList.toggle('active', item === content);
    });

    if (atualizarDados && tab === 'listagem') _renderMensagemTabela(document.querySelector('#tabelaVisitantes tbody'), 'Informe um termo e clique em Buscar para consultar os visitantes.');
    if (atualizarDados && tab === 'relatorios') _relAtualizar();

    console.debug('[Visitantes][Abas] Aba ativada:', { tab, atualizarDados });
    return true;
}

function _atualizarKpis(lista, totalConhecido = null) {
    const total   = Number.isFinite(Number(totalConhecido)) ? Number(totalConhecido) : lista.length;
    const ativos  = lista.filter(v => v.ativo == 1 || v.ativo === true).length;
    const comFoto = lista.filter(v => v.foto).length;
    const comDoc  = lista.filter(v => v.documento_arquivo).length;
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('kpiTotalVisitantes', total);
    set('kpiAtivos', ativos);
    set('kpiComFoto', comFoto);
    set('kpiComDoc', comDoc);
}

async function _relAtualizar() {
    if (_tipoRelatorioAtual() === 'analitico') {
        await _relCarregarAnalitico();
        return;
    }

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
    relatorioAnaliticoCache = null;
    salvando = false;
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
            _limparErroDocumento();
            _alternarCampoEstadoRg(tipoDoc.value);
        });
        docInput.addEventListener('input', () => {
            _aplicarMascaraDoc(docInput, tipoDoc?.value || 'CPF');
            _limparErroDocumento();
        });
        docInput.addEventListener('blur', () => {
            _validarDocumentoNoBlur(docInput, tipoDoc.value);
        });
        _alternarCampoEstadoRg(tipoDoc.value);
    }

    ['telefoneContato', 'celularVisitante'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => _mascaraTelefone(el));
    });
}

// Mostra/esconde o campo Estado (UF), exigido apenas quando o documento é RG.
function _alternarCampoEstadoRg(tipo) {
    const grupo = document.getElementById('grupoEstadoRg');
    const select = document.getElementById('estadoRg');
    const ehRg = tipo === 'RG';
    if (grupo) grupo.style.display = ehRg ? 'block' : 'none';
    if (select) select.required = ehRg;
    if (!ehRg && select) select.value = 'MG';
}

// Valida o documento assim que o usuário sai do campo, sem esperar o envio do
// formulário: primeiro o formato do CPF, depois se já existe cadastro com o
// mesmo número (evita descobrir a duplicidade só depois de preencher tudo).
async function _validarDocumentoNoBlur(input, tipo) {
    const valor = input.value.trim();
    if (!valor) {
        _limparErroDocumento();
        return;
    }
    if (tipo === 'CPF' && !_cpfValido(valor)) {
        _marcarErroDocumento('CPF inválido. Confira os dígitos informados.');
        return;
    }
    _limparErroDocumento();
    await _verificarDocumentoDuplicado(valor, tipo, input);
}

// Consulta se já existe visitante cadastrado com este documento neste
// condomínio. Em modo de edição, o próprio registro não conta como duplicado.
async function _verificarDocumentoDuplicado(documentoConsultado, tipo, input) {
    try {
        const resp = await fetch(`${API_VISITANTES}?documento=${encodeURIComponent(documentoConsultado)}`, { credentials: 'include' });
        const data = await resp.json();

        // Resposta atrasada: se o campo já mudou desde a consulta, ignora o resultado.
        if (input.value.trim() !== documentoConsultado) return;

        if (data.sucesso && data.dados) {
            const idEncontrado = Number(data.dados.id);
            const ehOProprioRegistro = modoEdicao && Number(visitanteIdEdicao) === idEncontrado;
            if (!ehOProprioRegistro) {
                _marcarErroDocumento(`${tipo} já cadastrado — pertence a ${data.dados.nome_completo}.`);
            }
        }
    } catch (erro) {
        console.warn('[Visitantes][Documento] Não foi possível verificar duplicidade agora:', erro);
        // Falha de rede não bloqueia o preenchimento; o servidor confirma a
        // unicidade novamente ao salvar o cadastro.
    }
}

function _marcarErroDocumento(mensagem) {
    const input = document.getElementById('documento');
    const erro  = document.getElementById('documentoErro');
    if (input) {
        input.classList.add('campo-invalido');
        input.style.borderColor = '#dc2626';
        input.style.background  = '#fef2f2';
    }
    if (erro) {
        erro.textContent = mensagem;
        erro.style.display    = 'block';
        erro.style.color      = '#dc2626';
        erro.style.fontWeight = '600';
    }
}

function _limparErroDocumento() {
    const input = document.getElementById('documento');
    const erro  = document.getElementById('documentoErro');
    if (input) {
        input.classList.remove('campo-invalido');
        input.style.borderColor = '';
        input.style.background  = '';
    }
    if (erro) { erro.textContent = ''; erro.style.display = 'none'; }
}

function _aplicarMascaraDoc(input, tipo) {
    let v = input.value.replace(/\D/g, '');
    if (tipo === 'CPF') {
        input.value = _formatarCPF(v);
        return;
    }

    // RG: XX.XXX.XXX-X
    v = v.slice(0, 9);
    if (v.length > 8)      v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{1})/, '$1.$2.$3-$4');
    else if (v.length > 5) v = v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
    else if (v.length > 2) v = v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
    input.value = v;
}

function _formatarCPF(valor) {
    const digitos = String(valor || '').replace(/\D/g, '').slice(0, 11);
    if (digitos.length <= 3) return digitos;
    if (digitos.length <= 6) return digitos.replace(/(\d{3})(\d{1,3})/, '$1.$2');
    if (digitos.length <= 9) return digitos.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
    return digitos.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
}

function _cpfValido(valor) {
    const cpf = String(valor || '').replace(/\D/g, '');
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;

    for (let posicao = 9; posicao < 11; posicao += 1) {
        let soma = 0;
        for (let indice = 0; indice < posicao; indice += 1) {
            soma += Number(cpf[indice]) * ((posicao + 1) - indice);
        }
        const digito = (soma % 11) < 2 ? 0 : 11 - (soma % 11);
        if (Number(cpf[posicao]) !== digito) return false;
    }
    return true;
}

function _formatarDocumentoParaExibicao(documento, tipo) {
    if (String(tipo || '').toUpperCase() !== 'CPF') return String(documento || '');
    return _formatarCPF(documento);
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
        fotoExistente = false;
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
            fotoExistente = false;
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
            documentoExistente = false;
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
            documentoExistente = false;
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
async function _carregarVisitantes(termoBusca = '', pagina = 1) {
    const tbody = document.querySelector('#tabelaVisitantes tbody');
    _setLoading(true);

    try {
        const params = new URLSearchParams();
        const termo = String(termoBusca || '').trim();
        if (termo) params.set('busca', termo);
        params.set('pagina', String(Math.max(1, Number(pagina) || 1)));
        params.set('limite', '50');
        const data = await _requisitarJsonComRetry(`${API_VISITANTES}?${params.toString()}`, {
            credentials: 'include'
        });

        console.log('[Visitantes] Resposta da API:', data);
        if (!data.sucesso) {
            _renderMensagemTabela(tbody, data.mensagem || 'Erro ao carregar visitantes.');
            return;
        }

        const dados = Array.isArray(data.dados) ? {
            itens: data.dados,
            total: data.dados.length,
            pagina: 1,
            limite: data.dados.length || 50,
            total_paginas: 1
        } : (data.dados || {});
        visitantesCache = Array.isArray(dados.itens) ? dados.itens : [];
        visitantesBuscaAtual = termo;
        visitantesPaginacao = {
            total: Number(dados.total) || 0,
            pagina: Number(dados.pagina) || 1,
            limite: Number(dados.limite) || 50,
            total_paginas: Number(dados.total_paginas) || 0
        };
        _atualizarKpis(visitantesCache, visitantesPaginacao.total);
        _renderVisitantes(visitantesCache);
        _renderPaginacaoVisitantes();
    } catch (error) {
        const detalhe = error?.message || 'Falha desconhecida';
        console.error('[Visitantes][Carga] Falha ao carregar visitantes:', {
            mensagem: detalhe,
            url: API_VISITANTES,
            tenantId: localStorage.getItem('tenant_id') || null,
            horario: new Date().toISOString()
        });

        const indisponivel = /^HTTP 503\b/.test(detalhe);
            visitantesPaginacao = { total: 0, pagina: 1, limite: 50, total_paginas: 0 };
        _renderPaginacaoVisitantes();
        _renderMensagemTabela(
            tbody,
            indisponivel
                ? 'Serviço temporariamente indisponível. Aguarde alguns segundos e atualize a lista.'
                : 'Não foi possível carregar os visitantes. Tente novamente.'
        );
    } finally {
        _setLoading(false);
    }
}

/**
 * Faz requisição JSON autenticada. Reintenta apenas HTTP 503, que representa
 * indisponibilidade temporária do servidor e não falha de sessão do usuário.
 */
async function _requisitarJsonComRetry(url, options = {}, tentativa = 1) {
    const maxTentativas = 2;
    let response;

    try {
        response = await fetch(url, {
            credentials: 'include',
            ...options
        });
    } catch (erroRede) {
        throw new Error(`Falha de rede: ${erroRede?.message || 'conexão indisponível'}`);
    }

    const corpo = await response.text();
    let dados;
    try {
        dados = corpo ? JSON.parse(corpo) : null;
    } catch (_) {
        dados = null;
    }

    if (response.status === 503 && tentativa < maxTentativas) {
        console.warn(`[Visitantes][Carga] HTTP 503; nova tentativa ${tentativa + 1}/${maxTentativas}.`);
        await new Promise(resolve => setTimeout(resolve, 1200 * tentativa));
        return _requisitarJsonComRetry(url, options, tentativa + 1);
    }

    if (!response.ok) {
        const mensagemApi = dados?.mensagem ? ` — ${dados.mensagem}` : '';
        throw new Error(`HTTP ${response.status}${mensagemApi}`);
    }

    if (!dados || typeof dados !== 'object') {
        throw new Error('Resposta inválida da API de visitantes');
    }

    return dados;
}

async function _lerRespostaJson(response, operacao) {
    const corpo = await response.text();
    let dados = null;
    try {
        dados = corpo ? JSON.parse(corpo) : null;
    } catch (_) {
        dados = null;
    }

    if (!dados || typeof dados !== 'object') {
        console.error('[Visitantes][API] Resposta JSON inválida:', {
            operacao,
            status: response.status,
            corpo: corpo.slice(0, 500)
        });
        throw new Error(`A API não retornou uma resposta válida ao ${operacao}.`);
    }

    if (response.ok === false && dados.sucesso !== false) {
        throw new Error(dados.mensagem || `Falha HTTP ${response.status} ao ${operacao}.`);
    }

    return dados;
}

async function _buscarVisitantes() {
    const input = document.getElementById('searchVisitante');
    const botao = document.getElementById('btnBuscarVisitante');
    const termo = input?.value?.trim() || '';
    if (botao) { botao.disabled = true; botao.dataset.originalText = botao.innerHTML; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando...'; }
    try { await _carregarVisitantes(termo, 1); }
    finally { if (botao) { botao.disabled = false; botao.innerHTML = botao.dataset.originalText || '<i class="fas fa-search"></i> Buscar'; } }
}

async function _irPaginaVisitantes(pagina) {
    const paginaAlvo = Math.max(1, Number(pagina) || 1);
    if (paginaAlvo === visitantesPaginacao.pagina || paginaAlvo > visitantesPaginacao.total_paginas) return;
    const termo = visitantesBuscaAtual || document.getElementById('searchVisitante')?.value?.trim() || '';
    await _carregarVisitantes(termo, paginaAlvo);
}

function _renderPaginacaoVisitantes() {
    const container = document.getElementById('paginacaoVisitantes');
    if (!container) return;
    const { total, pagina, limite, total_paginas: totalPaginas } = visitantesPaginacao;
    if (!total || !totalPaginas) { container.innerHTML = ''; container.style.display = 'none'; return; }
    const inicio = ((pagina - 1) * limite) + 1;
    const fim = Math.min(pagina * limite, total);
    const anterior = pagina > 1 ? `<button type="button" class="btn-secondary-modern" data-pagina="${pagina - 1}"><i class="fas fa-chevron-left"></i> Anterior</button>` : '';
    const proxima = pagina < totalPaginas ? `<button type="button" class="btn-secondary-modern" data-pagina="${pagina + 1}">Próxima <i class="fas fa-chevron-right"></i></button>` : '';
    const paginas = [];
    const inicioPagina = Math.max(1, Math.min(pagina - 2, totalPaginas - 4));
    const fimPagina = Math.min(totalPaginas, inicioPagina + 4);
    for (let numero = inicioPagina; numero <= fimPagina; numero += 1) {
        paginas.push(`<button type="button" class="btn-secondary-modern ${numero === pagina ? 'pagina-atual' : ''}" data-pagina="${numero}" ${numero === pagina ? 'disabled' : ''}>${numero}</button>`);
    }
    const termoInfo = visitantesBuscaAtual ? ` para “${_esc(visitantesBuscaAtual)}”` : '';
    container.innerHTML = `<span>Exibindo ${inicio}–${fim} de ${total} visitantes${termoInfo}</span><div class="visitantes-paginacao-acoes">${anterior}${paginas.join('')}${proxima}</div>`;
    container.style.display = 'flex';
    container.querySelectorAll('[data-pagina]').forEach(btn => btn.addEventListener('click', () => _irPaginaVisitantes(btn.dataset.pagina)));
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
        const doc       = _esc(_formatarDocumentoParaExibicao(v.documento, v.tipo_documento) || '-');
        const telefone  = _esc(v.telefone_contato || v.telefone || '-');
        const fotoUrl   = v.foto || '';
        const docUrl    = v.documento_arquivo || '';
        const autoria   = _obterAutoriaCadastro(v);
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
                <td><span class="cadastro-autor" title="${_esc(autoria.nome)}">${_esc(autoria.nome)}</span></td>
                <td style="text-align:center"><span class="cadastro-tipo ${autoria.classe}">${_esc(autoria.rotulo)}</span></td>
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
    if (tbody) tbody.innerHTML = `<tr><td colspan="11" class="empty-state">${_esc(msg)}</td></tr>`;
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
        let documento         = document.getElementById('documento')?.value.trim() || '';
        const telefoneContato = document.getElementById('telefoneContato')?.value.trim() || '';
        const celular         = document.getElementById('celularVisitante')?.value.trim() || '';
        const email           = document.getElementById('emailVisitante')?.value.trim() || '';
        const observacao      = document.getElementById('observacaoVisitante')?.value.trim() || '';
        const estadoRg        = document.getElementById('estadoRg')?.value || '';

        // A mesma regra configurada em Sistema > Visitantes é antecipada na tela;
        // o backend a confirma usando o tenant da sessão antes de gravar qualquer dado.
        const valores = {
            nome_completo: nome,
            tipo_documento: tipoDoc,
            documento,
            telefone_contato: telefoneContato,
            celular,
            email,
            observacao,
        };
        for (const [campo, valor] of Object.entries(valores)) {
            if (_campoObrigatorio(campo) && !String(valor || '').trim()) {
                _mostrarAlerta('error', `O campo "${configuracaoCampos[campo]?.rotulo || campo}" é obrigatório para este condomínio.`);
                document.querySelector(`[data-config-campo="${campo}"] input, [data-config-campo="${campo}"] select`)?.focus();
                return;
            }
        }
        const temFoto = Boolean(fotoArquivo || fotoExistente);
        const temDocumentoDigitalizado = Boolean(docArquivo || documentoExistente);
        if (_campoObrigatorio('foto') && !temFoto) {
            _mostrarAlerta('error', 'A foto do visitante é obrigatória para este condomínio.');
            document.getElementById('btnSelecionarFoto')?.focus();
            return;
        }
        if (_campoObrigatorio('documento_digitalizado') && !temDocumentoDigitalizado) {
            _mostrarAlerta('error', 'O documento digitalizado é obrigatório para este condomínio.');
            document.getElementById('btnSelecionarDoc')?.focus();
            return;
        }
        if (_campoObrigatorio('anexo_evidencia') && !temFoto && !temDocumentoDigitalizado) {
            _mostrarAlerta('error', 'Anexe ao menos uma foto ou um documento digitalizado para concluir o cadastro.');
            document.getElementById('btnSelecionarFoto')?.focus();
            return;
        }
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            _mostrarAlerta('error', 'Informe um e-mail válido.');
            document.getElementById('emailVisitante')?.focus();
            return;
        }

        // CPF é validado apenas quando foi informado, respeitando a configuração
        // que pode tornar o número de documento opcional.
        if (tipoDoc === 'CPF' && documento) {
            documento = _formatarCPF(documento);
            document.getElementById('documento').value = documento;
            if (!_cpfValido(documento)) {
                console.warn('[Visitantes][CPF] Cadastro bloqueado: CPF inválido.');
                _marcarErroDocumento('CPF inválido. Confira os dígitos informados.');
                _mostrarAlerta('error', 'CPF inválido. Confira os dígitos informados.');
                document.getElementById('documento')?.focus();
                return;
            }
        }

        if (tipoDoc === 'RG' && !estadoRg) {
            _mostrarAlerta('error', 'Selecione o estado (UF) do documento RG.');
            document.getElementById('estadoRg')?.focus();
            return;
        }

        const payload = {
            nome_completo:    nome,
            documento,
            tipo_documento:   tipoDoc,
            telefone_contato: telefoneContato,
            celular,
            email,
            observacao,
            estado: tipoDoc === 'RG' ? estadoRg : ''
        };

        let visitanteId = visitanteIdEdicao;
        const method = modoEdicao ? 'PUT' : 'POST';
        if (modoEdicao) payload.id = visitanteIdEdicao;

        // Cadastro inicial é multipart: a foto, o documento ou ambos seguem com os dados.
        // Edições continuam enviando JSON e só carregam novos arquivos quando necessário.
        let resp;
        if (modoEdicao) {
            resp = await fetch(API_VISITANTES, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
        } else {
            const formularioComAnexos = new FormData();
            formularioComAnexos.append('dados', JSON.stringify(payload));
            if (fotoArquivo) formularioComAnexos.append('foto', fotoArquivo);
            if (docArquivo) formularioComAnexos.append('documento', docArquivo);
            resp = await fetch(API_VISITANTES, {
                method: 'POST',
                body: formularioComAnexos
            });
        }
        const data = await _lerRespostaJson(resp, modoEdicao ? 'atualizar o visitante' : 'cadastrar o visitante');
        console.log('[Visitantes] Resposta salvar:', data);

        // Um documento já existente não cria novo cadastro nem substitui anexos.
        if (!data.sucesso && data.dados?.duplicado) {
            console.warn('[Visitantes] Cadastro bloqueado: documento já vinculado a outro visitante.', data.dados);
            _mostrarAlerta('warning', 'Já existe um visitante cadastrado com este documento. Abra-o pela listagem para revisar os anexos.');
            return;
        } else if (!data.sucesso) {
            _mostrarAlerta('error', data.mensagem || 'Erro ao salvar visitante.');
            return;
        } else {
            visitanteId = data.dados?.id || visitanteIdEdicao;
        }

        // Upload de foto (se selecionada)
        if (modoEdicao && fotoArquivo && visitanteId) {
            const fd = new FormData();
            fd.append('foto', fotoArquivo);
            const r = await fetch(`${API_VISITANTES}?acao=upload&tipo=foto&visitante_id=${visitanteId}`, {
                method: 'POST', body: fd
            });
            const d = await _lerRespostaJson(r, 'enviar a foto do visitante');
            if (!d.sucesso) console.warn('[Visitantes] Aviso upload foto:', d.mensagem);
        }

        // Upload de documento (se selecionado)
        if (modoEdicao && docArquivo && visitanteId) {
            const fd = new FormData();
            fd.append('documento', docArquivo);
            const r = await fetch(`${API_VISITANTES}?acao=upload&tipo=documento&visitante_id=${visitanteId}`, {
                method: 'POST', body: fd
            });
            const d = await _lerRespostaJson(r, 'enviar o documento do visitante');
            if (!d.sucesso) console.warn('[Visitantes] Aviso upload doc:', d.mensagem);
        }

        _mostrarAlerta('success', modoEdicao ? 'Visitante atualizado com sucesso!' : 'Visitante cadastrado com sucesso!');
        _resetForm();
        await _carregarVisitantes(visitantesBuscaAtual, visitantesPaginacao.pagina);

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
    const idNormalizado = Number(id);
    const visitante = visitantesCache.find(item => Number(item.id) === idNormalizado);

    if (!Number.isInteger(idNormalizado) || idNormalizado <= 0 || !visitante) {
        console.warn('[Visitantes][Edição] Registro não localizado no cache:', {
            idRecebido: id,
            totalEmCache: visitantesCache.length
        });
        _mostrarAlerta('warning', 'Não foi possível localizar o visitante para edição. Atualize a listagem e tente novamente.');
        return;
    }

    const camposObrigatorios = [
        'visitanteId', 'nomeVisitante', 'tipoDocumento', 'documento',
        'telefoneContato', 'celularVisitante', 'emailVisitante',
        'observacaoVisitante', 'visitanteForm'
    ];
    const camposAusentes = camposObrigatorios.filter(campo => !document.getElementById(campo));
    if (camposAusentes.length) {
        console.error('[Visitantes][Edição] Formulário incompleto:', { id: idNormalizado, camposAusentes });
        _mostrarAlerta('error', 'O formulário de edição não está disponível. Recarregue a página e tente novamente.');
        return;
    }

    // A aba Cadastro ficava oculta após o clique na listagem. Ativá-la antes
    // de preencher os campos torna a edição imediatamente visível ao usuário.
    if (!_ativarAba('cadastro', { atualizarDados: false })) {
        _mostrarAlerta('error', 'Não foi possível abrir o formulário de edição.');
        return;
    }

    modoEdicao        = true;
    visitanteIdEdicao = idNormalizado;

    document.getElementById('visitanteId').value        = String(idNormalizado);
    document.getElementById('nomeVisitante').value       = visitante.nome_completo || '';
    document.getElementById('tipoDocumento').value       = visitante.tipo_documento || 'CPF';
    document.getElementById('documento').value           = _formatarDocumentoParaExibicao(visitante.documento, visitante.tipo_documento);
    document.getElementById('telefoneContato').value     = visitante.telefone_contato || visitante.telefone || '';
    document.getElementById('celularVisitante').value    = visitante.celular || '';
    document.getElementById('emailVisitante').value      = visitante.email || '';
    document.getElementById('observacaoVisitante').value = visitante.observacao || '';
    _limparErroDocumento();
    _alternarCampoEstadoRg(visitante.tipo_documento || 'CPF');
    const estadoSel = document.getElementById('estadoRg');
    if (estadoSel) estadoSel.value = visitante.estado || 'MG';

    // Foto existente
    fotoExistente = Boolean(visitante.foto);
    documentoExistente = Boolean(visitante.documento_arquivo);

    if (visitante.foto) {
        const img    = document.getElementById('fotoPreview');
        const ph     = document.getElementById('fotoPlaceholder');
        const btnRem = document.getElementById('btnRemoverFoto');
        if (img) { img.src = visitante.foto; img.style.display = 'block'; }
        if (ph)  ph.style.display = 'none';
        if (btnRem) btnRem.style.display = 'inline-flex';
    }

    // Documento existente
    if (visitante.documento_arquivo) {
        const preview = document.getElementById('docPreview');
        const ph      = document.getElementById('docPlaceholder');
        const nome    = document.getElementById('docNomeArquivo');
        const btnRem  = document.getElementById('btnRemoverDoc');
        if (preview) preview.style.display = 'block';
        if (ph)      ph.style.display = 'none';
        if (nome)    nome.textContent = visitante.documento_arquivo.split('/').pop();
        if (btnRem)  btnRem.style.display = 'inline-flex';
    }

    const titulo = document.getElementById('tituloFormVisitante');
    if (titulo) titulo.innerHTML = '<i class="fas fa-user-edit"></i> Editar Visitante';

    const btnSalvar   = document.getElementById('btnSalvarVisitante');
    const btnCancelar = document.getElementById('btnCancelarEdicaoVisitante');
    if (btnSalvar)   btnSalvar.innerHTML = '<i class="fas fa-sync"></i> Atualizar Visitante';
    if (btnCancelar) btnCancelar.style.display = 'inline-flex';

    console.info('[Visitantes][Edição] Formulário carregado:', { id: idNormalizado, nome: visitante.nome_completo || '' });
    requestAnimationFrame(() => {
        const form = document.getElementById('visitanteForm');
        form?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        document.getElementById('nomeVisitante')?.focus({ preventScroll: true });
    });
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
        await _carregarVisitantes(visitantesBuscaAtual, visitantesPaginacao.pagina);
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
    fotoArquivo        = null;
    docArquivo         = null;
    fotoExistente      = false;
    documentoExistente = false;
    modoEdicao         = false;
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

    _limparErroDocumento();
    _alternarCampoEstadoRg(document.getElementById('tipoDocumento')?.value || 'CPF');

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

function _obterAutoriaCadastro(visitante) {
    const tipo = String(visitante?.cadastrado_por_tipo || 'LEGADO').toUpperCase();
    if (tipo === 'FUNCIONARIO') {
        return { nome: visitante?.cadastrado_por_nome || 'Usuário não identificado', rotulo: 'Funcionário', classe: 'funcionario', tipo };
    }
    if (tipo === 'MORADOR') {
        return { nome: visitante?.cadastrado_por_nome || 'Morador não identificado', rotulo: 'Morador', classe: 'morador', tipo };
    }
    return { nome: visitante?.cadastrado_por_nome || 'Cadastro legado', rotulo: 'Legado', classe: 'legado', tipo: 'LEGADO' };
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

function _setupRelatorios() {
    const tipo = document.getElementById('relTipoRelatorio');
    const inicio = document.getElementById('relDataInicio');
    const fim = document.getElementById('relDataFim');
    const hoje = new Date();
    const trintaDias = new Date();
    trintaDias.setDate(hoje.getDate() - 29);
    const formatarData = (data) => {
        const ano = data.getFullYear();
        const mes = String(data.getMonth() + 1).padStart(2, '0');
        const dia = String(data.getDate()).padStart(2, '0');
        return `${ano}-${mes}-${dia}`;
    };
    if (inicio && !inicio.value) inicio.value = formatarData(trintaDias);
    if (fim && !fim.value) fim.value = formatarData(hoje);
    tipo?.addEventListener('change', () => _relAlternarTipo(true));
    _relAlternarTipo(false);
}

function _tipoRelatorioAtual() {
    return document.getElementById('relTipoRelatorio')?.value === 'analitico' ? 'analitico' : 'padrao';
}

function _relAlternarTipo(atualizar = true) {
    const analitico = _tipoRelatorioAtual() === 'analitico';
    const filtrosCadastro = document.getElementById('relFiltrosCadastro');
    const filtrosAnalitico = document.getElementById('relAnaliticoFiltros');
    const kpisCadastro = document.getElementById('relKpisVisitantes');
    const preview = document.getElementById('relPreviewVisitantes');
    // O seletor de tipo permanece visível; somente os filtros próprios de cadastros
    // são ocultados quando o administrador escolhe a visão analítica de acessos.
    ['relNome', 'relCpf', 'relEmail', 'relTemFoto', 'relTemDoc', 'relOrigemCadastro', 'relAtivo'].forEach((id) => {
        const grupo = document.getElementById(id)?.closest('.form-group');
        if (grupo) grupo.style.display = analitico ? 'none' : '';
    });
    if (filtrosCadastro) filtrosCadastro.style.display = 'grid';
    if (filtrosAnalitico) filtrosAnalitico.style.display = analitico ? 'grid' : 'none';
    if (kpisCadastro && analitico) kpisCadastro.style.display = 'none';
    if (preview) preview.innerHTML = analitico
        ? '<p style="padding:12px;color:#64748b"><i class="fas fa-chart-line"></i> Atualize para carregar os indicadores analíticos de acesso.</p>'
        : '';
    console.debug('[Visitantes][Relatórios] tipo alterado', { tipo: analitico ? 'analitico' : 'padrao' });
    if (atualizar) _relAtualizar();
}

async function _relCarregarAnalitico() {
    const preview = document.getElementById('relPreviewVisitantes');
    const dataInicio = document.getElementById('relDataInicio')?.value || '';
    const dataFim = document.getElementById('relDataFim')?.value || '';
    if (!dataInicio || !dataFim) {
        if (preview) preview.innerHTML = '<p style="color:#b91c1c;padding:10px"><i class="fas fa-exclamation-circle"></i> Informe as datas inicial e final do relatório analítico.</p>';
        return;
    }
    if (dataInicio > dataFim) {
        if (preview) preview.innerHTML = '<p style="color:#b91c1c;padding:10px"><i class="fas fa-exclamation-circle"></i> A data inicial não pode ser posterior à data final.</p>';
        return;
    }

    if (preview) preview.innerHTML = '<p style="padding:18px;color:#2563eb"><i class="fas fa-spinner fa-spin"></i> Consolidando acessos do condomínio...</p>';
    const params = new URLSearchParams({ acao: 'analitico_acessos', data_inicio: dataInicio, data_fim: dataFim });
    try {
        const response = await fetch(`${API_VISITANTES}?${params.toString()}`, { credentials: 'include' });
        const texto = await response.text();
        let data = null;
        try { data = texto ? JSON.parse(texto) : null; } catch (_) {}
        if (!response.ok || !data?.sucesso) {
            throw new Error(data?.mensagem || `Erro HTTP ${response.status} ao carregar o relatório analítico.`);
        }
        relatorioAnaliticoCache = data.dados;
        _relMostrarAnalitico(data.dados, preview);
        console.debug('[Visitantes][Relatórios] análise carregada', { periodo: data.dados?.periodo, resumo: data.dados?.resumo });
    } catch (erro) {
        relatorioAnaliticoCache = null;
        console.error('[Visitantes][Relatórios] falha no analítico', erro);
        if (preview) preview.innerHTML = `<p style="color:#b91c1c;padding:12px"><i class="fas fa-exclamation-circle"></i> ${_esc(erro.message || 'Não foi possível gerar a análise.')}</p>`;
    }
}

function _relMostrarAnalitico(analise, container) {
    if (!container) return;
    const resumo = analise?.resumo || {};
    const total = Number(resumo.total_acessos || 0);
    const visitantes = Number(resumo.visitantes || 0);
    const prestadores = Number(resumo.prestadores || 0);
    const liberados = Number(resumo.liberados || 0);
    const taxaLiberacao = total ? Math.round((liberados / total) * 100) : 0;
    const pico = analise?.horario_pico_24h || { rotulo: 'Sem registros', total: 0 };
    const periodo = analise?.periodo || {};
    const rankingUnidades = Array.isArray(analise?.ranking_unidades) ? analise.ranking_unidades : [];
    const rankingPessoas = Array.isArray(analise?.ranking_pessoas) ? analise.ranking_pessoas : [];
    const histograma = Array.isArray(analise?.histograma_24h) ? analise.histograma_24h : [];
    const tendencia = Array.isArray(analise?.tendencia_diaria) ? analise.tendencia_diaria : [];
    const maxUnidade = Math.max(1, ...rankingUnidades.map(item => Number(item.total || 0)));
    const maxHora = Math.max(1, ...histograma.map(item => Number(item.total || 0)));
    const mapaHora = new Map(histograma.map(item => [Number(item.hora), Number(item.total || 0)]));

    const cards = `
        <div class="vis-analytics-grid">
            <div class="vis-analytics-card"><div class="valor">${total}</div><div class="rotulo">Acessos no período</div></div>
            <div class="vis-analytics-card visitor"><div class="valor">${visitantes}</div><div class="rotulo">Visitantes</div></div>
            <div class="vis-analytics-card provider"><div class="valor">${prestadores}</div><div class="rotulo">Prestadores de serviço</div></div>
            <div class="vis-analytics-card peak"><div class="valor">${_esc(pico.rotulo)}</div><div class="rotulo">Pico nas últimas 24h (${Number(pico.total || 0)} acesso(s))</div></div>
            <div class="vis-analytics-card success"><div class="valor">${taxaLiberacao}%</div><div class="rotulo">Taxa de liberação (${liberados}/${total})</div></div>
        </div>`;

    const rankingGlebas = rankingUnidades.length ? rankingUnidades.map((item, indice) => {
        const percentual = Math.round((Number(item.total || 0) / maxUnidade) * 100);
        return `<div class="vis-ranking-row"><span class="vis-ranking-pos">${indice + 1}º</span><span>${_esc(item.unidade)}</span><span class="vis-bar"><span style="width:${percentual}%"></span></span><span class="vis-ranking-total">${Number(item.total || 0)}</span></div>`;
    }).join('') : '<p style="color:#64748b;font-size:12px">Não há acessos de visitantes ou prestadores no período selecionado.</p>';

    const horas = Array.from({ length: 24 }, (_, hora) => {
        const quantidade = mapaHora.get(hora) || 0;
        const altura = Math.max(2, Math.round((quantidade / maxHora) * 100));
        return `<div class="vis-hour-item" title="${String(hora).padStart(2, '0')}:00 — ${quantidade} acesso(s)"><span class="vis-hour-total">${quantidade || ''}</span><span class="vis-hour-bar" style="height:${altura}%"></span><span class="vis-hour-label">${String(hora).padStart(2, '0')}</span></div>`;
    }).join('');

    const pessoas = rankingPessoas.length ? rankingPessoas.map((item, indice) => `<div class="vis-ranking-row"><span class="vis-ranking-pos">${indice + 1}º</span><span>${_esc(item.nome)}</span><span class="cadastro-tipo ${item.tipo === 'Prestador' ? 'funcionario' : 'morador'}">${_esc(item.tipo)}</span><span class="vis-ranking-total">${Number(item.total || 0)}</span></div>`).join('') : '<p style="color:#64748b;font-size:12px">Sem recorrências no período.</p>';

    const tendenciaLinhas = tendencia.length ? tendencia.map(item => `<tr><td>${_esc(item.data)}</td><td>${Number(item.total || 0)}</td><td>${Number(item.visitantes || 0)}</td><td>${Number(item.prestadores || 0)}</td></tr>`).join('') : '<tr><td colspan="4" style="text-align:center;color:#64748b">Sem movimentos no período.</td></tr>';

    container.innerHTML = `<p style="font-size:12px;color:#64748b;margin:0 0 12px"><i class="fas fa-calendar-alt"></i> Período analisado: <strong>${_esc(periodo.data_inicio || '')}</strong> até <strong>${_esc(periodo.data_fim || '')}</strong>. Dados consolidados exclusivamente para o condomínio atual.</p>${cards}
        <div class="vis-analytics-sections">
            <section class="vis-analytics-section"><h3><i class="fas fa-trophy"></i> Ranking de Glebas / Unidades</h3>${rankingGlebas}</section>
            <section class="vis-analytics-section"><h3><i class="fas fa-clock"></i> Distribuição de horários — últimas 24h</h3><div class="vis-hour-bars">${horas}</div></section>
            <section class="vis-analytics-section"><h3><i class="fas fa-user-clock"></i> Pessoas mais registradas</h3>${pessoas}</section>
            <section class="vis-analytics-section"><h3><i class="fas fa-chart-area"></i> Tendência diária</h3><table class="vis-tendencia-table"><thead><tr><th>Data</th><th>Total</th><th>Visitantes</th><th>Prestadores</th></tr></thead><tbody>${tendenciaLinhas}</tbody></table></section>
        </div>`;
}

function _relColetarFiltros() {
    return {
        nome:     (document.getElementById('relNome')?.value     || '').trim(),
        cpf:      (document.getElementById('relCpf')?.value      || '').trim(),
        email:    (document.getElementById('relEmail')?.value    || '').trim(),
        tem_foto: (document.getElementById('relTemFoto')?.value  || ''),
        tem_doc:  (document.getElementById('relTemDoc')?.value   || ''),
        origem:   (document.getElementById('relOrigemCadastro')?.value || ''),
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
        const origem = _obterAutoriaCadastro(v).tipo;

        if (filtros.nome    && !nome.includes(filtros.nome.toLowerCase()))  return false;
        if (filtros.cpf     && !doc.includes(filtros.cpf.replace(/\D/g, ''))) return false;
        if (filtros.email   && !email.includes(filtros.email.toLowerCase())) return false;
        if (filtros.tem_foto === 'sim' && !foto)  return false;
        if (filtros.tem_foto === 'nao' &&  foto)  return false;
        if (filtros.tem_doc  === 'sim' && !docA)  return false;
        if (filtros.tem_doc  === 'nao' &&  docA)  return false;
        if (filtros.origem && origem !== filtros.origem) return false;
        if (filtros.ativo === '1' && ativo !== '1') return false;
        if (filtros.ativo === '0' && ativo !== '0') return false;
        return true;
    });
}

async function relExportarCSV() {
    if (_tipoRelatorioAtual() === 'analitico') {
        if (!relatorioAnaliticoCache) await _relCarregarAnalitico();
        if (relatorioAnaliticoCache) _relExportarCSVAnalitico(relatorioAnaliticoCache);
        return;
    }
    const filtros    = _relColetarFiltros();
    const dados      = _relFiltrarCache(filtros);
    const preview    = document.getElementById('relPreviewVisitantes');

    if (!dados.length) {
        if (preview) preview.innerHTML = '<p style="color:#e74c3c;padding:10px;"><i class="fas fa-exclamation-circle"></i> Nenhum visitante encontrado com os filtros aplicados.</p>';
        return;
    }

    // Cabecalho CSV
    const cabecalho = ['ID','Nome Completo','Tipo Documento','Documento','E-mail','Telefone','Celular','Placa Veiculo','Possui Foto','Possui Documento','Cadastrado Por','Tipo de Cadastro','Status','Data Cadastro'];
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
        _obterAutoriaCadastro(v).nome,
        _obterAutoriaCadastro(v).rotulo,
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

function _relExportarCSVAnalitico(analise) {
    const resumo = analise?.resumo || {};
    const periodo = analise?.periodo || {};
    const pico = analise?.horario_pico_24h || {};
    const linhas = [
        ['RELATÓRIO ANALÍTICO DE ACESSOS — VISITANTES'],
        ['Período', `${periodo.data_inicio || ''} até ${periodo.data_fim || ''}`],
        [],
        ['INDICADOR', 'VALOR'],
        ['Total de acessos', resumo.total_acessos || 0],
        ['Visitantes', resumo.visitantes || 0],
        ['Prestadores de serviço', resumo.prestadores || 0],
        ['Entradas', resumo.entradas || 0],
        ['Saídas', resumo.saidas || 0],
        ['Acessos liberados', resumo.liberados || 0],
        ['Registros pendentes', resumo.pendentes || 0],
        ['Horário de pico — últimas 24h', `${pico.rotulo || 'Sem registros'} (${pico.total || 0})`],
        [],
        ['RANKING GLEBAS / UNIDADES', 'TOTAL', 'VISITANTES', 'PRESTADORES'],
        ...(analise?.ranking_unidades || []).map(item => [item.unidade || 'Não informado', item.total || 0, item.visitantes || 0, item.prestadores || 0]),
        [],
        ['PESSOAS MAIS REGISTRADAS', 'TIPO', 'TOTAL'],
        ...(analise?.ranking_pessoas || []).map(item => [item.nome || 'Não identificado', item.tipo || '', item.total || 0]),
        [],
        ['TENDÊNCIA DIÁRIA', 'TOTAL', 'VISITANTES', 'PRESTADORES'],
        ...(analise?.tendencia_diaria || []).map(item => [item.data || '', item.total || 0, item.visitantes || 0, item.prestadores || 0]),
    ];
    const csv = linhas.map(linha => linha.map(campo => `"${String(campo ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `relatorio_analitico_acessos_${periodo.data_inicio || 'periodo'}_${periodo.data_fim || 'periodo'}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function relGerarPDF() {
    const filtros = _relColetarFiltros();
    const base    = window.location.origin + '/api/api_relatorio_visitantes_pdf.php';
    const params  = new URLSearchParams();
    if (_tipoRelatorioAtual() === 'analitico') {
        params.set('tipo_relatorio', 'analitico');
        params.set('data_inicio', document.getElementById('relDataInicio')?.value || '');
        params.set('data_fim', document.getElementById('relDataFim')?.value || '');
        window.open(base + '?' + params.toString(), '_blank');
        return;
    }
    if (filtros.nome)     params.set('nome',     filtros.nome);
    if (filtros.cpf)      params.set('cpf',      filtros.cpf);
    if (filtros.email)    params.set('email',    filtros.email);
    if (filtros.tem_foto) params.set('tem_foto', filtros.tem_foto);
    if (filtros.tem_doc)  params.set('tem_doc',  filtros.tem_doc);
    if (filtros.origem)   params.set('origem',   filtros.origem);
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

    const linhas = dados.slice(0, 50).map(v => {
        const autoria = _obterAutoriaCadastro(v);
        return `
        <tr>
            <td>${_esc(v.nome_completo || '—')}</td>
            <td>${_esc(v.tipo_documento || 'CPF')}</td>
            <td>${_esc(v.documento || '—')}</td>
            <td>${_esc(v.email || '—')}</td>
            <td>${_esc(v.telefone_contato || v.celular || '—')}</td>
            <td style="text-align:center"><span class="rel-badge ${v.foto ? 'rel-badge-sim' : 'rel-badge-nao'}">${v.foto ? 'Sim' : 'Nao'}</span></td>
            <td style="text-align:center"><span class="rel-badge ${v.documento_arquivo ? 'rel-badge-sim' : 'rel-badge-nao'}">${v.documento_arquivo ? 'Sim' : 'Nao'}</span></td>
            <td>${_esc(autoria.nome)}</td>
            <td><span class="cadastro-tipo ${autoria.classe}">${_esc(autoria.rotulo)}</span></td>
            <td style="text-align:center"><span class="rel-badge ${(v.ativo==1) ? 'rel-badge-ativo' : 'rel-badge-inativo'}">${(v.ativo==1) ? 'Ativo' : 'Inativo'}</span></td>
        </tr>`;
    }).join('');

    const aviso = total > 50 ? `<p style="font-size:11px;color:#64748b;margin-top:6px;">Exibindo 50 de ${total} registros. O CSV/PDF contera todos.</p>` : '';

    container.innerHTML = kpis + `
        <table>
            <thead><tr>
                <th>Nome</th><th>Tipo</th><th>Documento</th><th>E-mail</th>
                <th>Telefone</th><th>Foto</th><th>Doc.</th><th>Cadastrado por</th><th>Tipo</th><th>Status</th>
            </tr></thead>
            <tbody>${linhas}</tbody>
        </table>` + aviso;
}
