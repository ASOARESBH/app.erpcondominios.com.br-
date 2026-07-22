/**
 * documentos.js — GED (Gestão Eletrônica de Documentos)
 * Módulo completo: documentos, departamentos, tipos, grupos, pastas,
 * compartilhamentos, relatórios e rastreabilidade.
 */

const DocumentosPage = (() => {
  const API = '../api/api_documentos.php';

  let _departamentos = [];
  let _tipos         = [];
  let _grupos        = [];
  let _pastas        = [];
  let _unidades      = [];
  let _usuariosSistema = [];
  let _docPagina     = 1;
  let _rastroPagina  = 1;
  let _relDados      = null;

  const $ = id => document.getElementById(id);
  const toast = (msg, tipo) => {
    tipo = tipo || 'success';
    if (typeof window.mostrarToast === 'function') { window.mostrarToast(msg, tipo); return; }
    const d = document.createElement('div');
    d.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:8px;background:' + (tipo === 'success' ? '#059669' : '#dc2626') + ';color:#fff;font-size:14px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.15)';
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(() => d.remove(), 3500);
  };
  const fmt = n => Number(n || 0).toLocaleString('pt-BR');
  const visLabel = v => ({ todos: 'Todos', moradores: 'Moradores', usuarios: 'Usuários', unidades_especificas: 'Unidades Espec.' }[v] || v);
  const visBadge = v => {
    const map = { todos: 'badge-blue', moradores: 'badge-green', usuarios: 'badge-purple', unidades_especificas: 'badge-orange' };
    return '<span class="badge ' + (map[v] || 'badge-gray') + '">' + visLabel(v) + '</span>';
  };
  const statusBadge = s => {
    const map = { ativo: 'badge-green', rascunho: 'badge-orange', inativo: 'badge-gray', expirado: 'badge-red' };
    const label = { ativo: 'Ativo', rascunho: 'Rascunho', inativo: 'Inativo', expirado: 'Expirado' };
    return '<span class="badge ' + (map[s] || 'badge-gray') + '">' + (label[s] || s) + '</span>';
  };
  const iconeArquivo = mime => {
    if (!mime) return 'fas fa-link';
    if (mime.includes('pdf')) return 'fas fa-file-pdf';
    if (mime.includes('word') || mime.includes('docx')) return 'fas fa-file-word';
    if (mime.includes('excel') || mime.includes('xlsx')) return 'fas fa-file-excel';
    if (mime.includes('powerpoint') || mime.includes('pptx')) return 'fas fa-file-powerpoint';
    if (mime.includes('image')) return 'fas fa-file-image';
    if (mime.includes('zip')) return 'fas fa-file-archive';
    return 'fas fa-file-alt';
  };
  const corArquivo = mime => {
    if (!mime) return '#64748b';
    if (mime.includes('pdf')) return '#dc2626';
    if (mime.includes('word') || mime.includes('docx')) return '#2563eb';
    if (mime.includes('excel') || mime.includes('xlsx')) return '#059669';
    if (mime.includes('powerpoint') || mime.includes('pptx')) return '#d97706';
    if (mime.includes('image')) return '#7c3aed';
    return '#64748b';
  };

  async function _api(params, method) {
    method = method || 'GET';
    try {
      let url = API, opts = {};
      if (method === 'GET') {
        url += '?' + new URLSearchParams(params).toString();
        opts = { credentials: 'include' };
      } else {
        const fd = new FormData();
        Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null) fd.append(k, v); });
        opts = { method: 'POST', body: fd, credentials: 'include' };
      }
      const r = await fetch(url, opts);
      const raw = await r.text();
      try { return JSON.parse(raw); } catch(e) {
        const preview = raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,200);
        return { sucesso: false, mensagem: preview || 'Resposta inválida do servidor' };
      }
    } catch (e) {
      return { sucesso: false, mensagem: 'Erro de conexão: ' + e.message };
    }
  }

  async function init() {
    await Promise.all([
      _carregarDepartamentos(),
      _carregarTipos(),
      _carregarGrupos(),
      _carregarPastas(),
    ]);
    _popularFiltros();
    carregarKPIs();
    buscarDocs();
  }

  async function carregarKPIs() {
    const r = await _api({ acao: 'dashboard_stats' });
    if (!r.sucesso) return;
    const d = r.dados || {};
    if ($('kpi-total-docs')) $('kpi-total-docs').textContent = fmt(d.total_documentos);
    if ($('kpi-total-dl'))   $('kpi-total-dl').textContent   = fmt(d.total_downloads);
    if ($('kpi-total-vis'))  $('kpi-total-vis').textContent  = fmt(d.total_visualizacoes);
    if ($('kpi-links'))      $('kpi-links').textContent      = fmt(d.total_links_ativos || d.links_ativos);
    if ($('kpi-exp'))        $('kpi-exp').textContent        = fmt(d.total_expirando || d.expirando);
  }

  /* ── DEPARTAMENTOS ──
     Fonte única: cadastro central (Configurações → Sistema → Departamentos).
     O GED apenas consome — sem criação/edição/exclusão própria. Recarregado
     do zero a cada acesso à aba (sem cache), para refletir alterações feitas
     centralmente sem sincronização manual. */
  async function _carregarDepartamentos() {
    const r = await _api({ acao: 'departamentos_listar' });
    _departamentos = r.sucesso ? (r.dados && r.dados.departamentos ? r.dados.departamentos : []) : [];
  }

  function abrirCadastroDepartamentos() {
    if (window.AppRouter && typeof window.AppRouter.loadPage === 'function') {
      window.AppRouter.loadPage('departamentos');
    } else {
      window.location.href = 'layout-base.html?page=departamentos';
    }
  }

  async function _renderDepartamentos() {
    const g = $('deps-grid');
    if (!g) return;
    if (!_departamentos.length) {
      g.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#94a3b8"><i class="fas fa-building" style="font-size:32px;margin-bottom:8px;display:block"></i>Nenhum departamento cadastrado.</div>';
      return;
    }
    g.innerHTML = _departamentos.map(d => {
      const ativo = Number(d.ativo) === 1;
      return (
      '<div class="page-card docs-dep-card" style="border-left:4px solid ' + (ativo ? '#2563eb' : '#94a3b8') + '">' +
        '<div class="docs-dep-icon" style="background:' + (ativo ? '#2563eb20' : '#94a3b820') + ';color:' + (ativo ? '#2563eb' : '#94a3b8') + '">' +
          '<i class="fas fa-building"></i>' +
        '</div>' +
        '<div class="docs-dep-info">' +
          '<div class="docs-dep-nome">' + d.nome + '</div>' +
          '<div class="docs-dep-desc">' + (d.descricao || '—') + '</div>' +
          '<div class="docs-dep-stats">' +
            '<span class="badge" style="background:' + (ativo ? '#05966920' : '#dc262620') + ';color:' + (ativo ? '#059669' : '#dc2626') + '">' + (ativo ? 'Ativo' : 'Inativo') + '</span>' +
            '<span>' + fmt(d.total_documentos || 0) + ' documentos vinculados</span>' +
          '</div>' +
        '</div>' +
        '<div class="docs-dep-actions">' +
          '<button class="btn-icon" title="Abrir Cadastro" onclick="DocumentosPage.abrirCadastroDepartamentos()"><i class="fas fa-link"></i></button>' +
        '</div>' +
      '</div>');
    }).join('');
  }

  /* ── TIPOS ── */
  async function _carregarTipos() {
    const r = await _api({ acao: 'tipos_listar' });
    _tipos = r.sucesso ? (r.dados && r.dados.tipos ? r.dados.tipos : []) : [];
  }

  async function _renderTipos() {
    const g = $('tipos-grid');
    if (!g) return;
    if (!_tipos.length) {
      g.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#94a3b8"><i class="fas fa-tags" style="font-size:32px;margin-bottom:8px;display:block"></i>Nenhum tipo cadastrado.</div>';
      return;
    }
    g.innerHTML = _tipos.map(t =>
      '<div class="page-card docs-dep-card" style="border-left:4px solid ' + (t.cor || '#2563eb') + '">' +
        '<div class="docs-dep-icon" style="background:' + (t.cor || '#2563eb') + '20;color:' + (t.cor || '#2563eb') + '">' +
          '<i class="' + (t.icone || 'fas fa-file-alt') + '"></i>' +
        '</div>' +
        '<div class="docs-dep-info">' +
          '<div class="docs-dep-nome">' + t.nome + '</div>' +
          '<div class="docs-dep-desc">' + (t.descricao || '—') + '</div>' +
          '<div class="docs-dep-stats"><span>' + fmt(t.total_documentos || 0) + ' documentos</span></div>' +
        '</div>' +
        '<div class="docs-dep-actions">' +
          '<button class="btn-icon" title="Editar" onclick=\'DocumentosPage.abrirModalTipo(' + JSON.stringify(t) + ')\'><i class="fas fa-edit"></i></button>' +
          '<button class="btn-icon btn-icon--red" title="Excluir" onclick="DocumentosPage.excluirTipo(' + t.id + ',\'' + t.nome.replace(/'/g,"\\'") + '\')"><i class="fas fa-trash"></i></button>' +
        '</div>' +
      '</div>'
    ).join('');
  }

  function abrirModalTipo(tipo) {
    tipo = tipo || null;
    $('tipo-id').value        = tipo ? tipo.id : '';
    $('tipo-nome').value      = tipo ? tipo.nome : '';
    $('tipo-descricao').value = tipo ? (tipo.descricao || '') : '';
    $('tipo-icone').value     = tipo ? (tipo.icone || 'fas fa-file-alt') : 'fas fa-file-alt';
    $('tipo-cor').value       = tipo ? (tipo.cor || '#2563eb') : '#2563eb';
    $('modal-tipo-titulo').textContent = tipo ? 'Editar Tipo' : 'Novo Tipo';
    atualizarPreviewTipo();
    $('modal-tipo').classList.add('active');
  }
  function fecharModalTipo() { $('modal-tipo').classList.remove('active'); }

  function atualizarPreviewTipo() {
    const icone = $('tipo-icone') ? $('tipo-icone').value : 'fas fa-file-alt';
    const cor   = $('tipo-cor') ? $('tipo-cor').value : '#2563eb';
    const nome  = $('tipo-nome') ? $('tipo-nome').value : 'Pré-visualização';
    const prev  = $('tipo-preview');
    if (prev) {
      prev.style.background = cor + '15';
      prev.style.borderColor = cor;
      prev.style.color = cor;
      prev.innerHTML = '<i class="' + icone + '"></i> <span>' + nome + '</span>';
    }
  }

  async function salvarTipo() {
    const nome = $('tipo-nome').value.trim();
    if (!nome) { toast('Informe o nome do tipo.', 'error'); return; }
    const r = await _api({ acao: 'tipo_salvar', id: $('tipo-id').value, nome, descricao: $('tipo-descricao').value, icone: $('tipo-icone').value, cor: $('tipo-cor').value }, 'POST');
    if (r.sucesso) { toast(r.mensagem); fecharModalTipo(); await _carregarTipos(); _renderTipos(); _popularFiltros(); }
    else toast(r.mensagem, 'error');
  }

  async function excluirTipo(id, nome) {
    if (!confirm('Excluir tipo "' + nome + '"?')) return;
    const r = await _api({ acao: 'tipo_excluir', id }, 'POST');
    if (r.sucesso) { toast(r.mensagem); await _carregarTipos(); _renderTipos(); _popularFiltros(); }
    else toast(r.mensagem, 'error');
  }

  /* ── GRUPOS ── */
  async function _carregarGrupos() {
    const r = await _api({ acao: 'grupos_listar' });
    _grupos = r.sucesso ? (r.dados && r.dados.grupos ? r.dados.grupos : []) : [];
  }

  async function _renderGrupos() {
    const tb = $('grupos-tbody');
    if (!tb) return;
    if (!_grupos.length) { tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">Nenhum grupo cadastrado.</td></tr>'; return; }
    tb.innerHTML = _grupos.map(g =>
      '<tr>' +
        '<td><strong>' + g.nome + '</strong>' + (g.descricao ? '<br><small style="color:#64748b">' + g.descricao + '</small>' : '') + '</td>' +
        '<td><span class="badge badge-blue">' + (g.tipo_acesso || 'todos') + '</span></td>' +
        '<td>' + fmt(g.total_usuarios || 0) + '</td>' +
        '<td>' + fmt(g.total_moradores || 0) + '</td>' +
        '<td>' +
          '<button class="btn-icon" title="Editar" onclick=\'DocumentosPage.abrirModalGrupo(' + JSON.stringify(g) + ')\'><i class="fas fa-edit"></i></button>' +
          '<button class="btn-icon btn-icon--red" title="Excluir" onclick="DocumentosPage.excluirGrupo(' + g.id + ',\'' + g.nome.replace(/'/g,"\\'") + '\')"><i class="fas fa-trash"></i></button>' +
        '</td>' +
      '</tr>'
    ).join('');
  }

  function abrirModalGrupo(g) {
    g = g || null;
    $('grupo-id').value        = g ? g.id : '';
    $('grupo-nome').value      = g ? g.nome : '';
    $('grupo-descricao').value = g ? (g.descricao || '') : '';
    $('grupo-tipo').value      = g ? (g.tipo_acesso || 'todos') : 'todos';
    $('modal-grupo-titulo').textContent = g ? 'Editar Grupo' : 'Novo Grupo';
    $('modal-grupo').classList.add('active');
  }
  function fecharModalGrupo() { $('modal-grupo').classList.remove('active'); }

  async function salvarGrupo() {
    const nome = $('grupo-nome').value.trim();
    if (!nome) { toast('Informe o nome do grupo.', 'error'); return; }
    const r = await _api({ acao: 'grupo_salvar', id: $('grupo-id').value, nome, descricao: $('grupo-descricao').value, tipo_acesso: $('grupo-tipo').value }, 'POST');
    if (r.sucesso) { toast(r.mensagem); fecharModalGrupo(); await _carregarGrupos(); _renderGrupos(); }
    else toast(r.mensagem, 'error');
  }

  async function excluirGrupo(id, nome) {
    if (!confirm('Excluir grupo "' + nome + '"?')) return;
    const r = await _api({ acao: 'grupo_excluir', id }, 'POST');
    if (r.sucesso) { toast(r.mensagem); await _carregarGrupos(); _renderGrupos(); }
    else toast(r.mensagem, 'error');
  }

  /* ── PASTAS ── */
  async function _carregarPastas() {
    const r = await _api({ acao: 'pastas_listar' });
    _pastas = r.sucesso ? (r.dados && r.dados.pastas ? r.dados.pastas : []) : [];
  }

  async function _renderPastas() {
    const tb = $('pastas-tbody');
    if (!tb) return;
    if (!_pastas.length) { tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">Nenhuma pasta cadastrada.</td></tr>'; return; }
    tb.innerHTML = _pastas.map(p =>
      '<tr>' +
        '<td><i class="fas fa-folder" style="color:#d97706;margin-right:8px"></i><strong>' + p.nome + '</strong></td>' +
        '<td>' + (p.departamento_nome ? p.departamento_nome + (Number(p.departamento_ativo) === 0 ? ' <span class="badge" style="background:#dc262620;color:#dc2626">Departamento Inativo</span>' : '') : '—') + '</td>' +
        '<td>' + (p.pasta_pai_nome || '—') + '</td>' +
        '<td>' + fmt(p.total_documentos || 0) + '</td>' +
        '<td>' +
          '<button class="btn-icon" title="Editar" onclick=\'DocumentosPage.abrirModalPasta(' + JSON.stringify(p) + ')\'><i class="fas fa-edit"></i></button>' +
          '<button class="btn-icon btn-icon--red" title="Excluir" onclick="DocumentosPage.excluirPasta(' + p.id + ',\'' + p.nome.replace(/'/g,"\\'") + '\')"><i class="fas fa-trash"></i></button>' +
        '</td>' +
      '</tr>'
    ).join('');
  }

  function abrirModalPasta(p) {
    p = p || null;
    $('pasta-id').value   = p ? p.id : '';
    $('pasta-nome').value = p ? p.nome : '';
    $('pasta-desc').value = p ? (p.descricao || '') : '';
    _popularSelectDepartamento('pasta-dep', p ? p.departamento_id : '', '-- Departamento --');
    const paiSel = $('pasta-pai');
    paiSel.innerHTML = '<option value="">Nenhuma (raiz)</option>';
    _pastas.filter(x => x.id != (p ? p.id : null)).forEach(x => {
      const o = document.createElement('option');
      o.value = x.id; o.textContent = x.nome;
      if (p && x.id == p.pasta_pai_id) o.selected = true;
      paiSel.appendChild(o);
    });
    $('modal-pasta-titulo').textContent = p ? 'Editar Pasta' : 'Nova Pasta';
    $('modal-pasta').classList.add('active');
  }
  function fecharModalPasta() { $('modal-pasta').classList.remove('active'); }

  async function salvarPasta() {
    const nome = $('pasta-nome').value.trim();
    if (!nome) { toast('Informe o nome da pasta.', 'error'); return; }
    const r = await _api({ acao: 'pasta_salvar', id: $('pasta-id').value, nome, descricao: $('pasta-desc').value, departamento_id: $('pasta-dep').value, pasta_pai_id: $('pasta-pai').value }, 'POST');
    if (r.sucesso) { toast(r.mensagem); fecharModalPasta(); await _carregarPastas(); _renderPastas(); }
    else toast(r.mensagem, 'error');
  }

  async function excluirPasta(id, nome) {
    if (!confirm('Excluir pasta "' + nome + '"?')) return;
    const r = await _api({ acao: 'pasta_excluir', id }, 'POST');
    if (r.sucesso) { toast(r.mensagem); await _carregarPastas(); _renderPastas(); }
    else toast(r.mensagem, 'error');
  }

  /* ── DOCUMENTOS ── */
  async function buscarDocs(pag) {
    pag = pag || 1;
    _docPagina = pag;
    const tb = $('docs-tbody');
    if (tb) tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';
    const r = await _api({
      acao: 'documentos_listar',
      busca: $('doc-busca') ? $('doc-busca').value : '',
      departamento_id: $('doc-filtro-dep') ? $('doc-filtro-dep').value : '',
      tipo_id: $('doc-filtro-tipo') ? $('doc-filtro-tipo').value : '',
      status: $('doc-filtro-status') ? $('doc-filtro-status').value : '',
      pagina: pag,
    });
    if (!r.sucesso) { if (tb) tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#dc2626">' + r.mensagem + '</td></tr>'; return; }
    const docs = r.dados && r.dados.documentos ? r.dados.documentos : [];
    if (!docs.length) {
      if (tb) tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8"><i class="fas fa-folder-open" style="font-size:32px;margin-bottom:8px;display:block"></i>Nenhum documento encontrado.</td></tr>';
    } else {
      if (tb) tb.innerHTML = docs.map(d => {
        const icone = d.arquivo_tipo ? iconeArquivo(d.arquivo_tipo) : (d.link_externo ? 'fas fa-external-link-alt' : 'fas fa-file-alt');
        const cor   = d.arquivo_tipo ? corArquivo(d.arquivo_tipo) : '#64748b';
        const tipoObj = _tipos.find(t => t.id == d.tipo_id);
        const tipoNome = tipoObj ? tipoObj.nome : '—';
        const tipoCor  = tipoObj ? (tipoObj.cor || '#64748b') : '#64748b';
        return '<tr>' +
          '<td><div style="display:flex;align-items:center;gap:10px">' +
            '<div style="width:36px;height:36px;border-radius:8px;background:' + cor + '15;display:flex;align-items:center;justify-content:center;flex-shrink:0">' +
              '<i class="' + icone + '" style="color:' + cor + ';font-size:16px"></i></div>' +
            '<div><div style="font-weight:600;color:#1e293b">' + d.nome + '</div>' +
            (d.tags ? '<div style="font-size:11px;color:#94a3b8">' + d.tags + '</div>' : '') +
            '</div></div></td>' +
          '<td>' + (d.tipo_id ? '<span class="badge" style="background:' + tipoCor + '20;color:' + tipoCor + ';border:1px solid ' + tipoCor + '40">' + tipoNome + '</span>' : '<span style="color:#94a3b8">—</span>') + '</td>' +
          '<td>' + (d.departamento_nome ? (Number(d.departamento_ativo) === 0
            ? '<span class="badge" title="Departamento Inativo" style="background:#dc262620;color:#dc2626">' + d.departamento_nome + ' (Inativo)</span>'
            : '<span class="badge" style="background:#2563eb20;color:#2563eb">' + d.departamento_nome + '</span>') : '—') + '</td>' +
          '<td>' + visBadge(d.visibilidade || 'todos') + '</td>' +
          '<td>' + statusBadge(d.status) + '</td>' +
          '<td style="text-align:center">' + fmt(d.total_downloads) + '</td>' +
          '<td style="text-align:center">' + fmt(d.total_visualizacoes) + '</td>' +
          '<td style="font-size:12px;color:#64748b">' + (d.criado_em || '—') + '</td>' +
          '<td>' +
            (d.arquivo ? '<button class="btn-icon" title="Download" onclick="DocumentosPage.downloadDoc(' + d.id + ')"><i class="fas fa-download"></i></button>' : '') +
            (d.link_externo ? '<a class="btn-icon" href="' + d.link_externo + '" target="_blank" title="Abrir link"><i class="fas fa-external-link-alt"></i></a>' : '') +
            '<button class="btn-icon" title="Compartilhar" onclick="DocumentosPage.abrirModalComp(' + d.id + ')"><i class="fas fa-share-alt"></i></button>' +
            '<button class="btn-icon" title="Editar" onclick="DocumentosPage.editarDoc(' + d.id + ')"><i class="fas fa-edit"></i></button>' +
            '<button class="btn-icon btn-icon--red" title="Excluir" onclick="DocumentosPage.excluirDoc(' + d.id + ',\'' + d.nome.replace(/'/g,"\\'") + '\')"><i class="fas fa-trash"></i></button>' +
          '</td></tr>';
      }).join('');
    }
    _renderPaginacao('docs-paginacao', r.dados && r.dados.pagina, r.dados && r.dados.total_paginas, 'DocumentosPage.buscarDocs');
  }

  function limparFiltrosDocs() {
    if ($('doc-busca')) $('doc-busca').value = '';
    if ($('doc-filtro-dep')) $('doc-filtro-dep').value = '';
    if ($('doc-filtro-tipo')) $('doc-filtro-tipo').value = '';
    if ($('doc-filtro-status')) $('doc-filtro-status').value = 'ativo';
    buscarDocs(1);
  }

  function downloadDoc(id) { window.open(API + '?acao=download&id=' + id, '_blank'); }

  async function editarDoc(id) {
    const r = await _api({ acao: 'documento_carregar', id });
    if (!r.sucesso) { toast(r.mensagem, 'error'); return; }
    abrirModalDoc(r.dados && r.dados.documento ? r.dados.documento : null);
  }

  async function excluirDoc(id, nome) {
    if (!confirm('Excluir documento "' + nome + '"?')) return;
    const r = await _api({ acao: 'documento_excluir', id }, 'POST');
    if (r.sucesso) { toast(r.mensagem); buscarDocs(_docPagina); carregarKPIs(); }
    else toast(r.mensagem, 'error');
  }

  /* ── MODAL DOCUMENTO ── */
  async function abrirModalDoc(doc) {
    doc = doc || null;
    $('doc-id').value           = doc ? doc.id : '';
    $('doc-nome').value         = doc ? doc.nome : '';
    $('doc-descricao').value    = doc ? (doc.descricao || '') : '';
    $('doc-tags').value         = doc ? (doc.tags || '') : '';
    $('doc-link-externo').value = doc ? (doc.link_externo || '') : '';
    $('doc-data-pub').value     = doc ? (doc.data_publicacao || '') : '';
    $('doc-data-exp').value     = doc ? (doc.data_expiracao || '') : '';
    $('doc-status').value       = doc ? (doc.status || 'ativo') : 'ativo';
    $('doc-visibilidade').value = doc ? (doc.visibilidade || 'todos') : 'todos';
    // Preencher usuários selecionados ao editar
    const idsPermitidos = doc ? (doc.usuarios_acesso_ids || []) : [];
    if (doc && doc.visibilidade === 'usuarios') {
      const wrapU = $('doc-usuarios-wrap');
      if (wrapU) wrapU.style.display = 'block';
      _renderUsuarios(idsPermitidos);
    }
    if ($('docs-upload-label')) $('docs-upload-label').textContent = doc && doc.arquivo_nome_original ? 'Arquivo atual: ' + doc.arquivo_nome_original : 'Clique ou arraste o arquivo aqui';
    if ($('doc-arquivo')) $('doc-arquivo').value = '';

    _popularSelectDepartamento('doc-departamento', doc ? doc.departamento_id : '', '-- Departamento --');
    _popularSelect('doc-tipo', _tipos, 'id', 'nome', doc ? doc.tipo_id : '', '-- Tipo de Documento --');
    _popularSelect('doc-grupo', _grupos, 'id', 'nome', doc ? doc.grupo_id : '', '-- Grupo de Acesso --');
    _popularSelect('doc-pasta', _pastas, 'id', 'nome', doc ? doc.pasta_id : '', 'Sem pasta');

    if ($('modal-doc-titulo')) $('modal-doc-titulo').textContent = doc ? 'Editar Documento' : 'Novo Documento';

    if (!_unidades.length) await _carregarUnidades();
    if (!_usuariosSistema.length) await _carregarUsuariosSistema();
    _renderUnidades(doc ? doc.unidades_acesso : null);
    onVisibilidadeChange();

    $('modal-doc').classList.add('active');
  }
  function fecharModalDoc() { $('modal-doc').classList.remove('active'); }

  function onVisibilidadeChange() {
    const vis = $('doc-visibilidade') ? $('doc-visibilidade').value : '';
    const wrapUnidades = $('doc-unidades-wrap');
    if (wrapUnidades) wrapUnidades.style.display = vis === 'unidades_especificas' ? 'block' : 'none';
    const wrapUsuarios = $('doc-usuarios-wrap');
    if (wrapUsuarios) {
      wrapUsuarios.style.display = vis === 'usuarios' ? 'block' : 'none';
      if (vis === 'usuarios') _renderUsuarios([]);
    }
  }

  async function _carregarUnidades() {
    const r = await _api({ acao: 'unidades_select' });
    _unidades = r.sucesso ? (r.dados || []) : [];
  }

  function _renderUnidades(selecionadas) {
    const list = $('doc-unidades-list');
    if (!list) return;
    let sel = [];
    if (selecionadas) {
      try { sel = typeof selecionadas === 'string' ? JSON.parse(selecionadas) : selecionadas; } catch(e) { sel = []; }
    }
    const busca = ($('doc-unidades-busca') ? $('doc-unidades-busca').value : '').toLowerCase();
    const filtradas = busca ? _unidades.filter(u => (u.nome + ' ' + (u.bloco || '')).toLowerCase().includes(busca)) : _unidades;
    if (!filtradas.length) { list.innerHTML = '<div style="text-align:center;padding:16px;color:#94a3b8">Nenhuma unidade encontrada.</div>'; return; }
    list.innerHTML = filtradas.map(u =>
      '<label class="docs-unidade-item">' +
        '<input type="checkbox" value="' + u.id + '" ' + (sel.includes(String(u.id)) || sel.includes(u.id) ? 'checked' : '') + ' onchange="DocumentosPage._atualizarContadorUnidades()">' +
        '<span>' + u.nome + (u.bloco && u.bloco !== 'ADMIN' ? ' — ' + u.bloco : '') + '</span>' +
      '</label>'
    ).join('');
    _atualizarContadorUnidades();
  }

  function filtrarUnidades() { _renderUnidades(_getUnidadesSelecionadas()); }

  function _getUnidadesSelecionadas() {
    const list = $('doc-unidades-list');
    if (!list) return [];
    return Array.from(list.querySelectorAll('input[type=checkbox]:checked')).map(c => c.value);
  }

  function _atualizarContadorUnidades() {
    const n = _getUnidadesSelecionadas().length;
    const el = $('doc-unidades-count');
    if (el) el.textContent = n + ' unidade' + (n !== 1 ? 's' : '') + ' selecionada' + (n !== 1 ? 's' : '');
  }

  function selecionarTodasUnidades() {
    const list = $('doc-unidades-list');
    if (list) list.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = true);
    _atualizarContadorUnidades();
  }

  function limparUnidades() {
    const list = $('doc-unidades-list');
    if (list) list.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false);
    _atualizarContadorUnidades();
  }

  // ── Usuários do sistema ──────────────────────────────────────────────────
  async function _carregarUsuariosSistema() {
    try {
      const r = await _api({ acao: 'usuarios_sistema' });
      _usuariosSistema = r.sucesso ? (r.dados?.usuarios || []) : [];
      console.log('[Documentos] Usuários do sistema carregados:', _usuariosSistema.length);
    } catch(e) {
      console.warn('[Documentos] Falha ao carregar usuários:', e);
      _usuariosSistema = [];
    }
  }

  function _renderUsuarios(selecionados) {
    const list = $('doc-usuarios-list');
    if (!list) return;
    const sel = Array.isArray(selecionados) ? selecionados.map(Number) : [];
    const busca = ($('doc-usuarios-busca') ? $('doc-usuarios-busca').value : '').toLowerCase().trim();
    const filtrados = busca
      ? _usuariosSistema.filter(u => (u.nome + ' ' + (u.email || '')).toLowerCase().includes(busca))
      : _usuariosSistema;
    if (!filtrados.length) {
      list.innerHTML = '<div style="text-align:center;padding:16px;color:#94a3b8">Nenhum usuário encontrado.</div>';
      _atualizarContadorUsuarios();
      return;
    }
    const nivelLabel = { admin: 'Administrador', gerente: 'Gerente', operador: 'Operador', visualizador: 'Visualizador' };
    list.innerHTML = filtrados.map(u => {
      const isAdminGer = u.nivel === 'admin' || u.nivel === 'gerente';
      const checked = isAdminGer || sel.includes(Number(u.id));
      const disabled = isAdminGer ? 'disabled' : '';
      const hint = isAdminGer ? ' <span style="font-size:11px;color:#059669">(acesso automático)</span>' : '';
      return '<label class="docs-unidade-item" style="' + (isAdminGer ? 'opacity:0.7' : '') + '">' +
        '<input type="checkbox" value="' + u.id + '" ' + (checked ? 'checked' : '') + ' ' + disabled +
        ' onchange="DocumentosPage._atualizarContadorUsuarios()">' +
        '<span>' + u.nome + ' <small style="color:#94a3b8">— ' + (nivelLabel[u.nivel] || u.nivel) + '</small>' + hint + '</span>' +
      '</label>';
    }).join('');
    _atualizarContadorUsuarios();
  }

  function filtrarUsuarios() {
    const selecionados = _getUsuariosSelecionados();
    _renderUsuarios(selecionados);
  }

  function _getUsuariosSelecionados() {
    const list = $('doc-usuarios-list');
    if (!list) return [];
    return Array.from(list.querySelectorAll('input[type=checkbox]:checked:not([disabled])')).map(c => Number(c.value));
  }

  function _atualizarContadorUsuarios() {
    const n = _getUsuariosSelecionados().length;
    const el = $('doc-usuarios-count');
    if (el) el.textContent = n + ' usuário' + (n !== 1 ? 's' : '') + ' selecionado' + (n !== 1 ? 's' : '');
  }

  function selecionarTodosUsuarios() {
    const list = $('doc-usuarios-list');
    if (list) list.querySelectorAll('input[type=checkbox]:not([disabled])').forEach(c => c.checked = true);
    _atualizarContadorUsuarios();
  }

  function limparUsuarios() {
    const list = $('doc-usuarios-list');
    if (list) list.querySelectorAll('input[type=checkbox]:not([disabled])').forEach(c => c.checked = false);
    _atualizarContadorUsuarios();
  }

  function onArquivoSelecionado(input) {
    const f = input.files[0];
    if (f && $('docs-upload-label')) $('docs-upload-label').textContent = f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
  }

  async function salvarDoc() {
    const nome = $('doc-nome').value.trim();
    if (!nome) { toast('Informe o nome do documento.', 'error'); return; }
    const btn = $('btn-salvar-doc');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
    const visib = $('doc-visibilidade').value;
    const unidades = visib === 'unidades_especificas' ? JSON.stringify(_getUnidadesSelecionadas()) : '';
    const usuariosIds = visib === 'usuarios' ? JSON.stringify(_getUsuariosSelecionados()) : '';
    const params = {
      acao: 'documento_salvar',
      id: $('doc-id').value,
      nome,
      descricao: $('doc-descricao').value,
      departamento_id: $('doc-departamento').value,
      tipo_id: $('doc-tipo').value,
      pasta_id: $('doc-pasta').value,
      grupo_id: $('doc-grupo').value,
      visibilidade: visib,
      unidades_acesso: unidades,
      usuarios_ids: usuariosIds,
      tags: $('doc-tags').value,
      link_externo: $('doc-link-externo').value,
      status: $('doc-status').value,
      data_publicacao: $('doc-data-pub').value,
      data_expiracao: $('doc-data-exp').value,
    };
    const arqInput = $('doc-arquivo');
    if (arqInput && arqInput.files && arqInput.files[0]) params.arquivo = arqInput.files[0];
    const r = await _api(params, 'POST');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar'; }
    if (r.sucesso) { toast(r.mensagem); fecharModalDoc(); buscarDocs(_docPagina); carregarKPIs(); }
    else toast(r.mensagem, 'error');
  }

  /* ── COMPARTILHAMENTOS ── */
  async function _carregarCompartilhamentos() {
    const r = await _api({ acao: 'compartilhamentos_listar' });
    const tb = $('comp-tbody');
    if (!tb) return;
    const rows = r.dados && r.dados.compartilhamentos ? r.dados.compartilhamentos : [];
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8">Nenhum link compartilhado.</td></tr>'; return; }
    tb.innerHTML = rows.map(c =>
      '<tr>' +
        '<td><strong>' + (c.documento_nome || '—') + '</strong></td>' +
        '<td>' + (c.descricao || '—') + '</td>' +
        '<td style="text-align:center">' + fmt(c.total_acessos) + '</td>' +
        '<td style="text-align:center">' + (c.limite_acessos ? fmt(c.limite_acessos) : '∞') + '</td>' +
        '<td>' + (c.expira_em || '—') + '</td>' +
        '<td>' + (c.ativo ? '<span class="badge badge-green">Ativo</span>' : '<span class="badge badge-gray">Inativo</span>') + '</td>' +
        '<td>' +
          '<button class="btn-icon" title="Copiar link" onclick="navigator.clipboard.writeText(window.location.origin + \'/compartilhado/' + c.token + '\').then(function(){DocumentosPage._toast(\'Link copiado!\')})"><i class="fas fa-copy"></i></button>' +
          (c.ativo ? '<button class="btn-icon btn-icon--red" title="Desativar" onclick="DocumentosPage.desativarLink(' + c.id + ')"><i class="fas fa-ban"></i></button>' : '') +
        '</td>' +
      '</tr>'
    ).join('');
  }

  function abrirModalComp(docId) {
    if ($('comp-doc-id')) $('comp-doc-id').value = docId;
    if ($('comp-desc')) $('comp-desc').value = '';
    if ($('comp-expira')) $('comp-expira').value = '';
    if ($('comp-limite')) $('comp-limite').value = '0';
    if ($('comp-link-gerado')) $('comp-link-gerado').style.display = 'none';
    $('modal-comp').classList.add('active');
  }
  function fecharModalComp() { $('modal-comp').classList.remove('active'); }

  async function gerarLink() {
    const btn = $('btn-gerar-link');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...'; }
    const r = await _api({
      acao: 'compartilhamento_gerar',
      documento_id: $('comp-doc-id') ? $('comp-doc-id').value : '',
      descricao: $('comp-desc') ? $('comp-desc').value : '',
      expira_em: $('comp-expira') ? $('comp-expira').value : '',
      limite_acessos: $('comp-limite') ? $('comp-limite').value : '0',
    }, 'POST');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-link"></i> Gerar Link'; }
    if (r.sucesso) {
      const url = window.location.origin + '/compartilhado/' + (r.dados ? r.dados.token : '');
      if ($('comp-link-url')) $('comp-link-url').value = url;
      if ($('comp-link-gerado')) $('comp-link-gerado').style.display = 'block';
      toast('Link gerado com sucesso!');
    } else toast(r.mensagem, 'error');
  }

  function copiarLink() {
    const el = $('comp-link-url');
    if (el) navigator.clipboard.writeText(el.value).then(function() { toast('Link copiado para a área de transferência!'); });
  }

  async function desativarLink(id) {
    if (!confirm('Desativar este link de compartilhamento?')) return;
    const r = await _api({ acao: 'compartilhamento_desativar', id }, 'POST');
    if (r.sucesso) { toast(r.mensagem); _carregarCompartilhamentos(); }
    else toast(r.mensagem, 'error');
  }

  /* ── RELATÓRIOS ── */
  async function carregarRelatorio() {
    const r = await _api({
      acao: 'relatorio_geral',
      periodo: $('rel-periodo') ? $('rel-periodo').value : '30',
      departamento_id: $('rel-dep') ? $('rel-dep').value : '',
      tipo_id: $('rel-tipo') ? $('rel-tipo').value : '',
    });
    if (!r.sucesso) { toast(r.mensagem, 'error'); return; }
    _relDados = r.dados;
    const porStatus = r.dados.por_status || [];
    const ativos    = (porStatus.find(s => s.status === 'ativo') || {}).total || 0;
    const rascunhos = (porStatus.find(s => s.status === 'rascunho') || {}).total || 0;
    const inativos  = ((porStatus.find(s => s.status === 'inativo') || {}).total || 0) + ((porStatus.find(s => s.status === 'expirado') || {}).total || 0);
    if ($('rel-kpi-tipos'))    $('rel-kpi-tipos').textContent    = _tipos.length;
    if ($('rel-kpi-ativos'))   $('rel-kpi-ativos').textContent   = fmt(ativos);
    if ($('rel-kpi-rascunhos'))$('rel-kpi-rascunhos').textContent= fmt(rascunhos);
    if ($('rel-kpi-inativos')) $('rel-kpi-inativos').textContent = fmt(inativos);
    _renderBarChart('rel-por-tipo', r.dados.por_tipo || [], 'nome', 'total', 'cor');
    _renderBarChart('rel-por-dep', r.dados.por_departamento || [], 'nome', 'total', 'cor');
    const topTb = $('rel-top-tbody');
    const top   = r.dados.top_documentos || [];
    if (topTb) {
      if (!top.length) { topTb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8">Sem dados.</td></tr>'; }
      else topTb.innerHTML = top.map((d, i) =>
        '<tr>' +
          '<td style="text-align:center;font-weight:700;color:' + (i < 3 ? '#d97706' : '#64748b') + '">' + (i + 1) + '</td>' +
          '<td><strong>' + d.nome + '</strong></td>' +
          '<td>' + (d.tipo || '—') + '</td>' +
          '<td>' + (d.departamento || '—') + '</td>' +
          '<td style="text-align:center">' + fmt(d.total_downloads) + '</td>' +
          '<td style="text-align:center">' + fmt(d.total_visualizacoes) + '</td>' +
        '</tr>'
      ).join('');
    }
    _renderLineChart('rel-downloads-chart', r.dados.downloads_periodo || [], 'dia', 'total');
    toast('Relatório gerado com sucesso!');
  }

  function _renderBarChart(elId, data, labelKey, valueKey, corKey) {
    const el = $(elId);
    if (!el) return;
    if (!data.length) { el.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8">Sem dados.</div>'; return; }
    const max = Math.max.apply(null, data.map(d => +d[valueKey]));
    el.innerHTML = data.map(d => {
      const pct = max > 0 ? Math.round((+d[valueKey] / max) * 100) : 0;
      const cor = d[corKey] || '#2563eb';
      return '<div style="margin-bottom:10px">' +
        '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">' +
          '<span style="font-weight:600;color:#1e293b">' + d[labelKey] + '</span>' +
          '<span style="color:#64748b">' + fmt(d[valueKey]) + '</span>' +
        '</div>' +
        '<div style="background:#f1f5f9;border-radius:4px;height:8px;overflow:hidden">' +
          '<div style="width:' + pct + '%;height:100%;background:' + cor + ';border-radius:4px;transition:width .5s"></div>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function _renderLineChart(elId, data, labelKey, valueKey) {
    const el = $(elId);
    if (!el) return;
    if (!data.length) { el.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8">Sem downloads no período.</div>'; return; }
    const max = Math.max.apply(null, data.map(d => +d[valueKey]).concat([1]));
    const w = 600, h = 120, pad = 30;
    const pts = data.map((d, i) => {
      const x = pad + (i / (data.length - 1 || 1)) * (w - 2 * pad);
      const y = h - pad - ((+d[valueKey] / max) * (h - 2 * pad));
      return x + ',' + y;
    }).join(' ');
    el.innerHTML = '<svg viewBox="0 0 ' + w + ' ' + h + '" style="width:100%;height:80px">' +
      '<polyline points="' + pts + '" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linejoin="round"/>' +
      data.map((d, i) => {
        const x = pad + (i / (data.length - 1 || 1)) * (w - 2 * pad);
        const y = h - pad - ((+d[valueKey] / max) * (h - 2 * pad));
        return '<circle cx="' + x + '" cy="' + y + '" r="3" fill="#2563eb"/>' +
               '<text x="' + x + '" y="' + (h - 4) + '" text-anchor="middle" font-size="9" fill="#94a3b8">' + d[labelKey] + '</text>';
      }).join('') +
    '</svg>';
  }

  function exportarRelatorio() {
    if (!_relDados) { toast('Gere o relatório primeiro.', 'error'); return; }
    const top = _relDados.top_documentos || [];
    const csv = ['#,Documento,Tipo,Departamento,Downloads,Visualizações'].concat(
      top.map((d, i) => (i+1) + ',"' + d.nome + '","' + d.tipo + '","' + d.departamento + '",' + d.total_downloads + ',' + d.total_visualizacoes)
    ).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'ged-relatorio-' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
  }

  /* ── RASTREABILIDADE ── */
  async function carregarRastreabilidade(pag) {
    pag = pag || 1;
    _rastroPagina = pag;
    const tipo  = $('rastro-tipo') ? $('rastro-tipo').value : 'acessos';
    const busca = $('rastro-busca') ? $('rastro-busca').value : '';
    const acao  = tipo === 'logs' ? 'logs_listar' : 'acessos_listar';
    const r = await _api({ acao, pagina: pag, busca });
    const tb = $('rastro-tbody');
    const th = $('rastro-thead');
    if (!tb) return;
    if (tipo === 'logs') {
      if (th) th.innerHTML = '<tr><th>Data/Hora</th><th>Documento</th><th>Ação</th><th>Usuário</th><th>Descrição</th><th>IP</th></tr>';
      const rows = r.dados && r.dados.logs ? r.dados.logs : [];
      if (!rows.length) { tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Nenhum log encontrado.</td></tr>'; return; }
      tb.innerHTML = rows.map(l =>
        '<tr>' +
          '<td style="font-size:12px;color:#64748b">' + (l.data_log || '—') + '</td>' +
          '<td>' + (l.documento_nome || '—') + '</td>' +
          '<td><span class="badge badge-blue">' + (l.acao || '—') + '</span></td>' +
          '<td>' + (l.usuario_nome || '—') + '</td>' +
          '<td style="font-size:12px">' + (l.descricao || '—') + '</td>' +
          '<td style="font-size:12px;color:#94a3b8">' + (l.ip || '—') + '</td>' +
        '</tr>'
      ).join('');
    } else {
      if (th) th.innerHTML = '<tr><th>Data/Hora</th><th>Documento</th><th>Tipo</th><th>Origem</th><th>Usuário/IP</th><th>Navegador</th></tr>';
      const rows = r.dados && r.dados.acessos ? r.dados.acessos : [];
      if (!rows.length) { tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Nenhum acesso registrado.</td></tr>'; return; }
      tb.innerHTML = rows.map(a =>
        '<tr>' +
          '<td style="font-size:12px;color:#64748b">' + (a.data_acesso || '—') + '</td>' +
          '<td>' + (a.documento_nome || '—') + '</td>' +
          '<td><span class="badge ' + (a.tipo === 'download' ? 'badge-green' : 'badge-blue') + '">' + (a.tipo || '—') + '</span></td>' +
          '<td><span class="badge ' + (a.origem === 'externo' ? 'badge-orange' : 'badge-gray') + '">' + (a.origem || '—') + '</span></td>' +
          '<td style="font-size:12px">' + (a.usuario_nome || a.ip || '—') + '</td>' +
          '<td style="font-size:11px;color:#94a3b8;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (a.user_agent || '—') + '</td>' +
        '</tr>'
      ).join('');
    }
    _renderPaginacao('rastro-paginacao', r.dados && r.dados.pagina, r.dados && r.dados.total_paginas, 'DocumentosPage.carregarRastreabilidade');
  }

  /* ── ABAS ── */
  function trocarAba(aba) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === aba));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + aba));
    if (aba === 'departamentos') _renderDepartamentos();
    if (aba === 'tipos')         _renderTipos();
    if (aba === 'grupos')        _renderGrupos();
    if (aba === 'pastas')        _renderPastas();
    if (aba === 'compartilhamentos') _carregarCompartilhamentos();
    if (aba === 'rastreabilidade')   carregarRastreabilidade();
    if (aba === 'relatorios') { _popularSelectRel(); carregarRelatorio(); }
  }

  /* ── HELPERS ── */
  function _popularSelect(id, arr, valKey, labelKey, selected, placeholder) {
    const el = $(id);
    if (!el) return;
    el.innerHTML = placeholder ? '<option value="">' + placeholder + '</option>' : '';
    arr.forEach(item => {
      const o = document.createElement('option');
      o.value = item[valKey]; o.textContent = item[labelKey];
      if (String(item[valKey]) === String(selected)) o.selected = true;
      el.appendChild(o);
    });
  }

  // Selects de departamento no formulário de documento/pasta: só oferece
  // departamentos ATIVOS como escolha nova; se o registro já estiver
  // vinculado a um departamento hoje inativo, mantém essa opção visível
  // (marcada) para não perder o vínculo histórico.
  function _popularSelectDepartamento(id, selected, placeholder) {
    const el = $(id);
    if (!el) return;
    el.innerHTML = placeholder ? '<option value="">' + placeholder + '</option>' : '';
    const ativos = _departamentos.filter(d => Number(d.ativo) === 1);
    let lista = ativos;
    if (selected && !ativos.some(d => String(d.id) === String(selected))) {
      const atual = _departamentos.find(d => String(d.id) === String(selected));
      if (atual) lista = ativos.concat([atual]);
    }
    lista.forEach(item => {
      const o = document.createElement('option');
      o.value = item.id;
      o.textContent = item.nome + (Number(item.ativo) === 1 ? '' : ' (Departamento Inativo)');
      if (String(item.id) === String(selected)) o.selected = true;
      el.appendChild(o);
    });
  }

  // Selects de filtro/relatório: mostram todos (ativos e inativos), pois
  // servem para localizar documentos históricos também.
  function _popularSelectFiltroDepartamento(id, placeholder) {
    const el = $(id);
    if (!el) return;
    el.innerHTML = placeholder ? '<option value="">' + placeholder + '</option>' : '';
    _departamentos.forEach(item => {
      const o = document.createElement('option');
      o.value = item.id;
      o.textContent = item.nome + (Number(item.ativo) === 1 ? '' : ' (Inativo)');
      el.appendChild(o);
    });
  }

  function _popularFiltros() {
    _popularSelectFiltroDepartamento('doc-filtro-dep', 'Todos os Departamentos');
    _popularSelect('doc-filtro-tipo', _tipos, 'id', 'nome', '', 'Todos os Tipos');
  }

  function _popularSelectRel() {
    _popularSelectFiltroDepartamento('rel-dep', 'Todos os Departamentos');
    _popularSelect('rel-tipo', _tipos, 'id', 'nome', '', 'Todos os Tipos');
  }

  function _renderPaginacao(elId, pag, total, fn) {
    const el = $(elId);
    if (!el || !total || total <= 1) { if (el) el.innerHTML = ''; return; }
    let html = '<div class="pag-btns">';
    if (pag > 1) html += '<button onclick="' + fn + '(' + (pag - 1) + ')"><i class="fas fa-chevron-left"></i></button>';
    for (let i = 1; i <= total; i++) {
      if (i === 1 || i === total || (i >= pag - 2 && i <= pag + 2)) {
        html += '<button class="' + (i === pag ? 'active' : '') + '" onclick="' + fn + '(' + i + ')">' + i + '</button>';
      } else if (i === pag - 3 || i === pag + 3) {
        html += '<span>…</span>';
      }
    }
    if (pag < total) html += '<button onclick="' + fn + '(' + (pag + 1) + ')"><i class="fas fa-chevron-right"></i></button>';
    html += '</div>';
    el.innerHTML = html;
  }

  function _toast(msg, tipo) { toast(msg, tipo || 'success'); }

  // Chamado pelo App Router ao saber da página — limpa o estado em memória
  // para não vazar dados de uma sessão de navegação para a próxima.
  function destroy() {
    _departamentos = [];
    _tipos         = [];
    _grupos        = [];
    _pastas        = [];
    _unidades      = [];
    _docPagina     = 1;
    _rastroPagina  = 1;
    _relDados      = null;
  }

  return {
    init, destroy, carregarKPIs, buscarDocs, limparFiltrosDocs, downloadDoc, editarDoc, excluirDoc,
    abrirModalDoc, fecharModalDoc, salvarDoc, onArquivoSelecionado, onVisibilidadeChange,
    filtrarUsuarios, selecionarTodosUsuarios, limparUsuarios, _atualizarContadorUsuarios,
    filtrarUnidades, selecionarTodasUnidades, limparUnidades, _atualizarContadorUnidades,
    abrirCadastroDepartamentos,
    abrirModalTipo, fecharModalTipo, salvarTipo, excluirTipo, atualizarPreviewTipo,
    abrirModalGrupo, fecharModalGrupo, salvarGrupo, excluirGrupo,
    abrirModalPasta, fecharModalPasta, salvarPasta, excluirPasta,
    abrirModalComp, fecharModalComp, gerarLink, copiarLink, desativarLink,
    carregarRelatorio, exportarRelatorio,
    carregarRastreabilidade,
    trocarAba,
    _toast,
  };
})();

// Exposto globalmente porque documentos.html usa onclick="DocumentosPage.xxx()"
// inline — esses atributos executam no escopo global, não no escopo do módulo
// ES, então sem esta linha toda a UI falha com "DocumentosPage is not defined"
// (mesmo padrão de window.HidrometroPage, window.MoradoresPage etc.)
window.DocumentosPage = DocumentosPage;

// Contrato do App Router (frontend/js/app-router.js): faz
// `const module = await import(scriptPath)` e chama module.init()/module.destroy().
// Sem este export, o Router loga "módulo carregado mas não possui init() exportado"
// e nunca chama nada — por isso o auto-init abaixo foi removido: a inicialização
// agora acontece só através do Router, evitando carregar os dados duas vezes.
export function init() {
  return DocumentosPage.init();
}

export function destroy() {
  return DocumentosPage.destroy();
}
