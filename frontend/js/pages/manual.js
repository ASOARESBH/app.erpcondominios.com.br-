/**
 * Manual do Sistema — Base de Conhecimento Inteligente
 * ERP Condomínios | Vanilla JS
 */
console.log('[Manual] Módulo carregado');

const Manual = (() => {
    const API = '../api/api_manual.php';
    let _modulos = [];
    let _artigos = [];
    let _moduloAtivo = 'todos';
    let _artigoAvaliacaoId = null;
    let _searchTimeout = null;
    let _podeEditar = false;
    let _savedRange = null;

    // ==========================================
    // INICIALIZAÇÃO
    // ==========================================
    async function init() {
        console.log('[Manual] Inicializando...');
        await _carregarModulos();
        await _carregarDashboard();
        _iniciarBusca();
        _verificarPermissoes();
        _verificarContexto();
    }

    async function _verificarPermissoes() {
        // Verifica se o usuário pode editar (admin/supervisor)
        try {
            const r = await fetch('../api/usuario_logado.php', { credentials: 'include' });
            const d = await r.json();
            if (d.sucesso) {
                const p = (d.usuario.permissao || d.usuario.funcao || '').toLowerCase();
                _podeEditar = ['admin', 'sindico', 'administrador', 'supervisor'].includes(p);
                if (_podeEditar) {
                    document.getElementById('manualAdminBtns').style.display = 'flex';
                    document.getElementById('manualFilterStatus').style.display = 'block';
                    _carregarPendentes();
                }
            }
        } catch (e) {
            console.warn('[Manual] Não foi possível verificar permissões:', e);
        }
    }

    function _verificarContexto() {
        // Se a página foi aberta com ?modulo=X, filtrar automaticamente
        const url = new URL(window.location.href);
        const pageId = url.searchParams.get('modulo') || url.searchParams.get('page');
        if (pageId && pageId !== 'manual') {
            const modulo = _modulos.find(m => m.page_id === pageId);
            if (modulo) {
                filtrarModulo(modulo.id, null);
            }
        }
    }

    // ==========================================
    // MÓDULOS (SIDEBAR)
    // ==========================================
    async function _carregarModulos() {
        try {
            const r = await fetch(`${API}?acao=listar_modulos`, { credentials: 'include' });
            const d = await r.json();
            if (d.sucesso) {
                _modulos = d.modulos;
                _renderizarNavModulos();
                _popularSelectModulos();
            }
        } catch (e) {
            console.error('[Manual] Erro ao carregar módulos:', e);
        }
    }

    function _renderizarNavModulos() {
        const nav = document.getElementById('manualNavModulos');
        if (!nav) return;
        nav.innerHTML = _modulos.map(m => `
            <div class="manual-nav-item" data-modulo="${m.id}" onclick="Manual.filtrarModulo('${m.id}', this)">
                <i class="${m.icone}"></i>
                <span>${m.nome}</span>
            </div>
        `).join('');
    }

    function _popularSelectModulos() {
        const sel = document.getElementById('editorModulo');
        if (!sel) return;
        sel.innerHTML = '<option value="">Selecione o módulo...</option>' +
            _modulos.map(m => `<option value="${m.id}">${m.nome}</option>`).join('');
    }

    // ==========================================
    // DASHBOARD E ARTIGOS
    // ==========================================
    async function _carregarDashboard() {
        try {
            const r = await fetch(`${API}?acao=dashboard`, { credentials: 'include' });
            const d = await r.json();
            if (d.sucesso) {
                const stats = d.dados;
                const row = document.getElementById('manualStatsRow');
                if (row) row.style.display = 'grid';
                _setEl('statTotalArtigos', stats.total_artigos);
                _setEl('statBuscasHoje', stats.buscas_hoje);
                _setEl('statAvaliacoes', stats.avaliacoes_positivas);

                // Buscas sem resultado (só admin)
                if (_podeEditar && stats.buscas_sem_resultado.length > 0) {
                    const div = document.getElementById('manualBuscasVazias');
                    const list = document.getElementById('manualBuscasVaziasList');
                    if (div && list) {
                        div.style.display = 'block';
                        list.innerHTML = stats.buscas_sem_resultado.map(b =>
                            `<span class="manual-tag-busca">${b.termo} <small>(${b.total}x)</small></span>`
                        ).join('');
                    }
                }
            }
        } catch (e) {
            console.warn('[Manual] Erro no dashboard:', e);
        }
        await _carregarArtigos();
    }

    async function _carregarArtigos(moduloId = null, pageId = null) {
        const grid = document.getElementById('manualArtigosGrid');
        if (!grid) return;
        grid.innerHTML = '<div class="manual-loading"><i class="fas fa-spinner fa-spin"></i> Carregando artigos...</div>';

        let url = `${API}?acao=listar_artigos`;
        if (moduloId && moduloId !== 'todos' && moduloId !== 'favoritos') url += `&modulo_id=${moduloId}`;
        if (pageId) url += `&page_id=${pageId}`;

        try {
            const r = await fetch(url, { credentials: 'include' });
            const d = await r.json();
            if (d.sucesso) {
                _artigos = d.artigos;
                _renderizarArtigos(_artigos);
            } else {
                grid.innerHTML = '<div class="manual-empty"><i class="fas fa-file-times"></i><p>Nenhum artigo encontrado.</p></div>';
            }
        } catch (e) {
            grid.innerHTML = '<div class="manual-empty"><i class="fas fa-exclamation-circle"></i><p>Erro ao carregar artigos.</p></div>';
        }
    }

    function _renderizarArtigos(artigos) {
        const grid = document.getElementById('manualArtigosGrid');
        if (!grid) return;

        const statusFilter = document.getElementById('manualFilterStatus')?.value || '';
        let filtrados = artigos;
        if (statusFilter) filtrados = artigos.filter(a => a.status === statusFilter);

        if (filtrados.length === 0) {
            grid.innerHTML = '<div class="manual-empty"><i class="fas fa-book-open"></i><p>Nenhum artigo encontrado nesta seção.</p><p style="font-size:0.85rem;color:#94a3b8;">Seja o primeiro a documentar este módulo!</p></div>';
            return;
        }

        grid.innerHTML = filtrados.map(a => `
            <div class="manual-artigo-card ${a.status !== 'publicado' ? 'manual-card-draft' : ''}" onclick="Manual.abrirArtigo(${a.id})">
                <div class="manual-card-header">
                    <span class="manual-card-modulo">
                        <i class="${a.modulo_icone}"></i> ${a.modulo_nome}
                    </span>
                    <div class="manual-card-actions">
                        <button class="manual-btn-fav ${a.is_favorito > 0 ? 'ativo' : ''}" 
                            onclick="event.stopPropagation(); Manual.toggleFavorito(${a.id}, this)" 
                            title="${a.is_favorito > 0 ? 'Remover dos favoritos' : 'Adicionar aos favoritos'}">
                            <i class="${a.is_favorito > 0 ? 'fas' : 'far'} fa-star"></i>
                        </button>
                        ${_podeEditar ? `
                        <button class="manual-btn-edit" onclick="event.stopPropagation(); Manual.editarArtigo(${a.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="manual-btn-delete" onclick="event.stopPropagation(); Manual.excluirArtigo(${a.id})" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>` : ''}
                    </div>
                </div>
                <h3 class="manual-card-titulo">${a.titulo}</h3>
                ${a.resumo ? `<p class="manual-card-resumo">${a.resumo}</p>` : ''}
                <div class="manual-card-footer">
                    <span class="manual-badge-status manual-badge-${a.status}">${_labelStatus(a.status)}</span>
                    <span class="manual-card-meta">
                        <i class="fas fa-eye"></i> ${a.visualizacoes}
                        &nbsp;&nbsp;
                        <i class="fas fa-code-branch"></i> v${a.versao}
                    </span>
                </div>
            </div>
        `).join('');
    }

    function _labelStatus(s) {
        return { publicado: 'Publicado', rascunho: 'Rascunho', desatualizado: 'Desatualizado' }[s] || s;
    }

    // ==========================================
    // FILTROS
    // ==========================================
    async function filtrarModulo(moduloId, el) {
        _moduloAtivo = moduloId;

        // Atualizar nav ativo
        document.querySelectorAll('.manual-nav-item').forEach(i => i.classList.remove('active'));
        if (el) el.classList.add('active');

        // Atualizar título da seção
        const titleEl = document.getElementById('manualSectionTitle');
        const statsRow = document.getElementById('manualStatsRow');

        if (moduloId === 'todos') {
            if (titleEl) titleEl.innerHTML = '<i class="fas fa-home"></i> Todos os Artigos';
            if (statsRow) statsRow.style.display = 'grid';
            await _carregarArtigos();
        } else if (moduloId === 'favoritos') {
            if (titleEl) titleEl.innerHTML = '<i class="fas fa-star"></i> Meus Favoritos';
            if (statsRow) statsRow.style.display = 'none';
            await _carregarArtigos('favoritos');
        } else {
            const modulo = _modulos.find(m => m.id == moduloId);
            if (modulo) {
                if (titleEl) titleEl.innerHTML = `<i class="${modulo.icone}"></i> ${modulo.nome}`;
            }
            if (statsRow) statsRow.style.display = 'none';
            await _carregarArtigos(moduloId);
        }
    }

    function aplicarFiltros() {
        _renderizarArtigos(_artigos);
    }

    // ==========================================
    // VISUALIZAÇÃO DE ARTIGO
    // ==========================================
    async function abrirArtigo(id) {
        try {
            const r = await fetch(`${API}?acao=obter_artigo&id=${id}`, { credentials: 'include' });
            const d = await r.json();
            if (!d.sucesso) { _toast('Artigo não encontrado.', 'error'); return; }

            const a = d.artigo;
            const view = document.getElementById('manualViewArtigo');
            const lista = document.getElementById('manualViewLista');
            const content = document.getElementById('manualArtigoContent');

            if (lista) lista.style.display = 'none';
            if (view) view.style.display = 'block';

            // Embed YouTube
            let videoEmbed = '';
            if (a.video_url) {
                const ytId = _extrairYoutubeId(a.video_url);
                if (ytId) {
                    videoEmbed = `
                        <div class="manual-video-wrapper">
                            <iframe src="https://www.youtube.com/embed/${ytId}" 
                                frameborder="0" allowfullscreen></iframe>
                        </div>`;
                }
            }

            // Tags
            const tags = a.tags ? a.tags.split(',').map(t =>
                `<span class="manual-tag">${t.trim()}</span>`
            ).join('') : '';

            content.innerHTML = `
                <div class="manual-artigo-breadcrumb">
                    <span><i class="${a.modulo_icone}"></i> ${a.modulo_nome}</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>${a.titulo}</span>
                </div>

                <div class="manual-artigo-topbar">
                    <h1 class="manual-artigo-titulo">${a.titulo}</h1>
                    <div class="manual-artigo-meta-row">
                        <span><i class="fas fa-user"></i> ${a.autor_nome || 'Sistema'}</span>
                        <span><i class="fas fa-calendar"></i> ${_formatarData(a.atualizado_em)}</span>
                        <span><i class="fas fa-code-branch"></i> v${a.versao}</span>
                        <span><i class="fas fa-eye"></i> ${a.visualizacoes} visualizações</span>
                        <span class="manual-badge-status manual-badge-${a.status}">${_labelStatus(a.status)}</span>
                    </div>
                    <div class="manual-artigo-toolbar">
                        <button class="manual-btn-fav ${a.is_favorito > 0 ? 'ativo' : ''}" 
                            id="btnFavArtigo"
                            onclick="Manual.toggleFavorito(${a.id}, this)">
                            <i class="${a.is_favorito > 0 ? 'fas' : 'far'} fa-star"></i>
                            ${a.is_favorito > 0 ? 'Favoritado' : 'Favoritar'}
                        </button>
                        <button class="manual-btn-link" onclick="Manual.verHistorico(${a.id})">
                            <i class="fas fa-history"></i> Histórico
                        </button>
                        <button class="manual-btn-link" onclick="Manual.abrirModulo('${a.page_id}')">
                            <i class="fas fa-external-link-alt"></i> Abrir Módulo
                        </button>
                        ${_podeEditar ? `
                        <button class="manual-btn-link" onclick="Manual.editarArtigo(${a.id})">
                            <i class="fas fa-edit"></i> Editar
                        </button>` : ''}
                    </div>
                </div>

                ${a.resumo ? `<div class="manual-artigo-resumo">${a.resumo}</div>` : ''}

                ${videoEmbed}

                <div class="manual-artigo-corpo">${a.conteudo || '<p><em>Sem conteúdo ainda.</em></p>'}</div>

                ${tags ? `<div class="manual-artigo-tags"><i class="fas fa-tags"></i> ${tags}</div>` : ''}

                <!-- AVALIAÇÃO -->
                <div class="manual-avaliacao-box">
                    <p class="manual-avaliacao-pergunta">Este artigo foi útil?</p>
                    <div class="manual-avaliacao-btns">
                        <button class="manual-btn-avaliacao sim ${a.minha_avaliacao === '1' ? 'ativo' : ''}" 
                            onclick="Manual.avaliar(${a.id}, true)">
                            <i class="fas fa-thumbs-up"></i> Sim
                        </button>
                        <button class="manual-btn-avaliacao nao ${a.minha_avaliacao === '0' ? 'ativo' : ''}" 
                            onclick="Manual.avaliar(${a.id}, false)">
                            <i class="fas fa-thumbs-down"></i> Não
                        </button>
                    </div>
                </div>
            `;

            window.scrollTo(0, 0);
        } catch (e) {
            _toast('Erro ao abrir artigo.', 'error');
        }
    }

    function voltarLista() {
        document.getElementById('manualViewArtigo').style.display = 'none';
        document.getElementById('manualViewLista').style.display = 'block';
    }

    function abrirModulo(pageId) {
        if (pageId && window.AppRouter) {
            window.AppRouter.loadPage(pageId);
        } else {
            window.location.href = `../layout-base.html?page=${pageId}`;
        }
    }

    // ==========================================
    // BUSCA INTELIGENTE
    // ==========================================
    function _iniciarBusca() {
        const input = document.getElementById('manualSearchInput');
        const clearBtn = document.getElementById('manualSearchClear');
        if (!input) return;

        input.addEventListener('input', () => {
            const q = input.value.trim();
            clearBtn.style.display = q ? 'flex' : 'none';
            clearTimeout(_searchTimeout);
            if (q.length >= 2) {
                _searchTimeout = setTimeout(() => _executarBusca(q), 350);
            } else {
                _fecharResultados();
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') limparBusca();
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.manual-search-wrapper') && !e.target.closest('.manual-search-results')) {
                _fecharResultados();
            }
        });
    }

    async function _executarBusca(q) {
        const resultsDiv = document.getElementById('manualSearchResults');
        if (!resultsDiv) return;
        resultsDiv.style.display = 'block';
        resultsDiv.innerHTML = '<div class="manual-search-loading"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';

        try {
            const r = await fetch(`${API}?acao=buscar&q=${encodeURIComponent(q)}`, { credentials: 'include' });
            const d = await r.json();

            if (d.resultados && d.resultados.length > 0) {
                resultsDiv.innerHTML = `
                    <div class="manual-search-header">
                        <span>${d.resultados.length} resultado(s) para "<strong>${q}</strong>"</span>
                    </div>
                    ${d.resultados.map(r => `
                        <div class="manual-search-item" onclick="Manual.abrirArtigo(${r.id}); Manual.limparBusca();">
                            <div class="manual-search-item-modulo">
                                <i class="${r.icone}"></i> ${r.modulo_nome}
                            </div>
                            <div class="manual-search-item-titulo">${_destacar(r.titulo, q)}</div>
                            ${r.resumo ? `<div class="manual-search-item-resumo">${_destacar(r.resumo.substring(0, 100), q)}...</div>` : ''}
                            <button class="manual-search-item-btn" onclick="event.stopPropagation(); Manual.abrirModulo('${r.page_id}')">
                                <i class="fas fa-external-link-alt"></i> Abrir Módulo
                            </button>
                        </div>
                    `).join('')}
                `;
            } else {
                resultsDiv.innerHTML = `
                    <div class="manual-search-empty">
                        <i class="fas fa-search-minus"></i>
                        <p>Nenhum resultado para "<strong>${q}</strong>"</p>
                        <p style="font-size:0.8rem;color:#94a3b8;">Tente outros termos ou verifique a ortografia.</p>
                    </div>
                `;
            }
        } catch (e) {
            resultsDiv.innerHTML = '<div class="manual-search-empty"><i class="fas fa-exclamation-circle"></i> Erro na busca.</div>';
        }
    }

    function _destacar(texto, termo) {
        if (!texto || !termo) return texto;
        const regex = new RegExp(`(${termo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return texto.replace(regex, '<mark>$1</mark>');
    }

    function _fecharResultados() {
        const r = document.getElementById('manualSearchResults');
        if (r) r.style.display = 'none';
    }

    function limparBusca() {
        const input = document.getElementById('manualSearchInput');
        const clearBtn = document.getElementById('manualSearchClear');
        if (input) input.value = '';
        if (clearBtn) clearBtn.style.display = 'none';
        _fecharResultados();
    }

    // ==========================================
    // FAVORITOS
    // ==========================================
    async function toggleFavorito(artigoId, btn) {
        try {
            const r = await fetch(`${API}?acao=favoritar`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'favoritar', artigo_id: artigoId })
            });
            const d = await r.json();
            if (d.sucesso) {
                const ativo = d.favorito;
                btn.classList.toggle('ativo', ativo);
                btn.querySelector('i').className = ativo ? 'fas fa-star' : 'far fa-star';
                if (btn.id === 'btnFavArtigo') {
                    btn.innerHTML = `<i class="${ativo ? 'fas' : 'far'} fa-star"></i> ${ativo ? 'Favoritado' : 'Favoritar'}`;
                }
                _toast(ativo ? 'Adicionado aos favoritos!' : 'Removido dos favoritos.', 'success');
            }
        } catch (e) {
            _toast('Erro ao atualizar favorito.', 'error');
        }
    }

    // ==========================================
    // AVALIAÇÕES
    // ==========================================
    function avaliar(artigoId, util) {
        _artigoAvaliacaoId = artigoId;
        if (!util) {
            // Mostrar modal de comentário
            document.getElementById('avaliacaoComentario').value = '';
            document.getElementById('manualModalAvaliacao').style.display = 'flex';
        } else {
            _enviarAvaliacao(artigoId, true, '');
        }
    }

    async function confirmarAvaliacao() {
        const comentario = document.getElementById('avaliacaoComentario').value;
        document.getElementById('manualModalAvaliacao').style.display = 'none';
        await _enviarAvaliacao(_artigoAvaliacaoId, false, comentario);
    }

    async function _enviarAvaliacao(artigoId, util, comentario) {
        try {
            const r = await fetch(`${API}?acao=avaliar`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'avaliar', artigo_id: artigoId, util: util ? 1 : 0, comentario })
            });
            const d = await r.json();
            if (d.sucesso) {
                _toast(d.mensagem, 'success');
                // Atualizar botões de avaliação
                document.querySelectorAll('.manual-btn-avaliacao').forEach(b => b.classList.remove('ativo'));
                const btnAtivo = document.querySelector(`.manual-btn-avaliacao.${util ? 'sim' : 'nao'}`);
                if (btnAtivo) btnAtivo.classList.add('ativo');
            }
        } catch (e) {
            _toast('Erro ao registrar avaliação.', 'error');
        }
    }

    // ==========================================
    // EDITOR DE ARTIGOS
    // ==========================================
    function abrirEditor(artigo = null) {
        document.getElementById('manualEditorTitle').innerHTML = '<i class="fas fa-edit"></i> ' + (artigo ? 'Editar Artigo' : 'Novo Artigo');
        document.getElementById('editorArtigoId').value = artigo ? artigo.id : '';
        document.getElementById('editorTitulo').value = artigo ? artigo.titulo : '';
        document.getElementById('editorResumo').value = artigo ? artigo.resumo : '';
        document.getElementById('editorTags').value = artigo ? artigo.tags : '';
        document.getElementById('editorVideoUrl').value = artigo ? artigo.video_url : '';
        document.getElementById('editorStatus').value = artigo ? artigo.status : 'publicado';
        document.getElementById('editorConteudo').innerHTML = artigo ? artigo.conteudo : '';

        if (artigo && artigo.modulo_id) {
            document.getElementById('editorModulo').value = artigo.modulo_id;
        }

        document.getElementById('manualModalEditor').style.display = 'flex';
    }

    function fecharEditor() {
        document.getElementById('manualModalEditor').style.display = 'none';
    }

    async function editarArtigo(id) {
        try {
            const r = await fetch(`${API}?acao=obter_artigo&id=${id}`, { credentials: 'include' });
            const d = await r.json();
            if (d.sucesso) abrirEditor(d.artigo);
        } catch (e) {
            _toast('Erro ao carregar artigo.', 'error');
        }
    }

    async function salvarArtigo() {
        const id = document.getElementById('editorArtigoId').value;
        const modulo_id = document.getElementById('editorModulo').value;
        const titulo = document.getElementById('editorTitulo').value.trim();
        const resumo = document.getElementById('editorResumo').value.trim();
        const conteudo = document.getElementById('editorConteudo').innerHTML;
        const tags = document.getElementById('editorTags').value.trim();
        const video_url = document.getElementById('editorVideoUrl').value.trim();
        const status = document.getElementById('editorStatus').value;

        if (!titulo) { _toast('Título é obrigatório.', 'error'); return; }
        if (!modulo_id) { _toast('Selecione o módulo.', 'error'); return; }

        try {
            const r = await fetch(`${API}?acao=salvar_artigo`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'salvar_artigo', id: id || 0, modulo_id, titulo, resumo, conteudo, tags, video_url, status })
            });
            const d = await r.json();
            if (d.sucesso) {
                _toast(d.mensagem, 'success');
                fecharEditor();
                await _carregarArtigos(_moduloAtivo !== 'todos' && _moduloAtivo !== 'favoritos' ? _moduloAtivo : null);
            } else {
                _toast(d.mensagem, 'error');
            }
        } catch (e) {
            _toast('Erro ao salvar artigo.', 'error');
        }
    }

    async function excluirArtigo(id) {
        if (!confirm('Tem certeza que deseja excluir este artigo? Esta ação não pode ser desfeita.')) return;
        try {
            const fd = new FormData();
            fd.append('id', id);
            const r = await fetch(`${API}?acao=excluir_artigo`, { method: 'POST', credentials: 'include', body: fd });
            const d = await r.json();
            if (d.sucesso) {
                _toast(d.mensagem, 'success');
                voltarLista();
                await _carregarArtigos();
            } else {
                _toast(d.mensagem, 'error');
            }
        } catch (e) {
            _toast('Erro ao excluir artigo.', 'error');
        }
    }

    // ==========================================
    // EDITOR WYSIWYG — COMANDOS
    // ==========================================
    function execCmd(cmd) {
        document.getElementById('editorConteudo').focus();
        document.execCommand(cmd, false, null);
    }

    function inserirH2() {
        document.getElementById('editorConteudo').focus();
        document.execCommand('formatBlock', false, 'h2');
    }

    function inserirH3() {
        document.getElementById('editorConteudo').focus();
        document.execCommand('formatBlock', false, 'h3');
    }

    function inserirLink() {
        const url = prompt('URL do link:');
        if (url) {
            document.getElementById('editorConteudo').focus();
            document.execCommand('createLink', false, url);
        }
    }

    function inserirAlerta() {
        const editor = document.getElementById('editorConteudo');
        editor.focus();
        const html = '<div class="manual-alerta"><i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong> Texto do alerta aqui.</div>';
        document.execCommand('insertHTML', false, html);
    }

    function inserirDica() {
        const editor = document.getElementById('editorConteudo');
        editor.focus();
        const html = '<div class="manual-dica"><i class="fas fa-lightbulb"></i> <strong>Dica:</strong> Texto da dica aqui.</div>';
        document.execCommand('insertHTML', false, html);
    }

    function inserirImagem() {
        // Salvar seleção atual
        const sel = window.getSelection();
        if (sel.rangeCount) _savedRange = sel.getRangeAt(0).cloneRange();
        document.getElementById('imgUrl').value = '';
        document.getElementById('imgAlt').value = '';
        document.getElementById('manualModalImagem').style.display = 'flex';
    }

    function confirmarImagem() {
        const url = document.getElementById('imgUrl').value.trim();
        const alt = document.getElementById('imgAlt').value.trim();
        if (!url) { _toast('Informe a URL da imagem.', 'error'); return; }

        const editor = document.getElementById('editorConteudo');
        editor.focus();

        if (_savedRange) {
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(_savedRange);
        }

        document.execCommand('insertHTML', false, `<img src="${url}" alt="${alt}" class="manual-img-content">`);
        document.getElementById('manualModalImagem').style.display = 'none';
    }

    async function uploadImagem(input) {
        const file = input.files[0];
        if (!file) return;

        const fd = new FormData();
        fd.append('imagem', file);

        try {
            const r = await fetch(`${API}?acao=upload_imagem`, { method: 'POST', credentials: 'include', body: fd });
            const d = await r.json();
            if (d.sucesso) {
                document.getElementById('imgUrl').value = d.url;
                _toast('Imagem enviada com sucesso!', 'success');
            } else {
                _toast(d.mensagem, 'error');
            }
        } catch (e) {
            _toast('Erro ao fazer upload.', 'error');
        }
    }

    // ==========================================
    // HISTÓRICO DE VERSÕES
    // ==========================================
    async function verHistorico(artigoId) {
        document.getElementById('manualModalHistorico').style.display = 'flex';
        const content = document.getElementById('manualHistoricoContent');
        content.innerHTML = '<div class="manual-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

        try {
            const r = await fetch(`${API}?acao=listar_historico&artigo_id=${artigoId}`, { credentials: 'include' });
            const d = await r.json();

            if (d.historico && d.historico.length > 0) {
                content.innerHTML = `
                    <div class="manual-historico-list">
                        ${d.historico.map(h => `
                            <div class="manual-historico-item">
                                <div class="manual-historico-versao">v${h.versao}</div>
                                <div class="manual-historico-info">
                                    <strong>${h.titulo}</strong>
                                    <span>${_formatarData(h.modificado_em)} — ${h.autor || 'Sistema'}</span>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
            } else {
                content.innerHTML = '<div class="manual-empty"><i class="fas fa-history"></i><p>Nenhum histórico disponível.</p></div>';
            }
        } catch (e) {
            content.innerHTML = '<div class="manual-empty"><i class="fas fa-exclamation-circle"></i><p>Erro ao carregar histórico.</p></div>';
        }
    }

    // ==========================================
    // ARTIGOS PENDENTES
    // ==========================================
    async function _carregarPendentes() {
        try {
            const r = await fetch(`${API}?acao=artigos_pendentes`, { credentials: 'include' });
            const d = await r.json();
            if (d.pendentes && d.pendentes.length > 0) {
                const badge = document.getElementById('badgePendentes');
                if (badge) {
                    badge.textContent = d.pendentes.length;
                    badge.style.display = 'inline-flex';
                }
            }
        } catch (e) {}
    }

    async function verPendentes() {
        document.getElementById('manualModalPendentes').style.display = 'flex';
        const content = document.getElementById('manualPendentesContent');
        content.innerHTML = '<div class="manual-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

        try {
            const r = await fetch(`${API}?acao=artigos_pendentes`, { credentials: 'include' });
            const d = await r.json();

            if (d.pendentes && d.pendentes.length > 0) {
                content.innerHTML = `
                    <p style="color:#64748b;margin-bottom:1rem;">Artigos marcados como desatualizados que precisam de revisão:</p>
                    ${d.pendentes.map(p => `
                        <div class="manual-pendente-item">
                            <div>
                                <strong>${p.titulo}</strong>
                                <span style="color:#94a3b8;font-size:0.8rem;display:block;">${p.modulo_nome} — ${_formatarData(p.atualizado_em)}</span>
                            </div>
                            <button class="btn-manual-secondary" onclick="Manual.editarArtigo(${p.id}); document.getElementById('manualModalPendentes').style.display='none';">
                                <i class="fas fa-edit"></i> Revisar
                            </button>
                        </div>
                    `).join('')}
                `;
            } else {
                content.innerHTML = '<div class="manual-empty"><i class="fas fa-check-circle" style="color:#16a34a;"></i><p>Nenhuma documentação pendente!</p></div>';
            }
        } catch (e) {
            content.innerHTML = '<div class="manual-empty"><i class="fas fa-exclamation-circle"></i><p>Erro ao carregar pendentes.</p></div>';
        }
    }

    // ==========================================
    // AJUDA CONTEXTUAL (chamada de outras páginas)
    // ==========================================
    function abrirAjudaContextual(pageId) {
        if (window.AppRouter) {
            window.AppRouter.loadPage('manual');
            setTimeout(() => {
                const modulo = _modulos.find(m => m.page_id === pageId);
                if (modulo) filtrarModulo(modulo.id, null);
            }, 800);
        }
    }

    // ==========================================
    // UTILITÁRIOS
    // ==========================================
    function _extrairYoutubeId(url) {
        const match = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\n?#]+)/);
        return match ? match[1] : null;
    }

    function _formatarData(str) {
        if (!str) return '—';
        const d = new Date(str);
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function _setEl(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    function _toast(msg, tipo = 'success') {
        const existing = document.querySelector('.manual-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `manual-toast manual-toast-${tipo}`;
        toast.innerHTML = `<i class="fas fa-${tipo === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}`;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3500);
    }

    // ==========================================
    // PÚBLICO
    // ==========================================
    return {
        init,
        filtrarModulo,
        aplicarFiltros,
        abrirArtigo,
        voltarLista,
        abrirModulo,
        toggleFavorito,
        avaliar,
        confirmarAvaliacao,
        abrirEditor,
        fecharEditor,
        editarArtigo,
        salvarArtigo,
        excluirArtigo,
        execCmd,
        inserirH2,
        inserirH3,
        inserirLink,
        inserirImagem,
        inserirAlerta,
        inserirDica,
        confirmarImagem,
        uploadImagem,
        verHistorico,
        verPendentes,
        limparBusca,
        abrirAjudaContextual
    };
})();

/// Expor globalmente para ajuda contextual de outras páginas
window.Manual = Manual;

// Contrato do App Router (frontend/js/app-router.js): faz
// `const module = await import(scriptPath)` e chama module.init().
// Sem este export, o Router loga "módulo carregado mas não possui init() exportado"
// e a página não inicializa via roteamento.
export function init() {
    return Manual.init();
}
export function destroy() {
    if (typeof Manual.destroy === 'function') return Manual.destroy();
}
