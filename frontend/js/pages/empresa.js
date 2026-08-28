/**
 * Controlador da página de Empresa
 */

const state = {
    apiBase: '/api/',
    dom: {},
    administradoras: [],
    layouts: [],
    layoutIdsConfigurados: new Set(),
    administradoraConfiguradaId: 0,
    dashboardWidgets: []
};

export function init() {
    console.log('[Empresa] Inicializando...');
    bindDOM();
    bindEvents();
    carregarDados();
    carregarLayoutAdministradora();
    carregarDashboardConfig();
}

export function destroy() {
    console.log('[Empresa] Destruindo...');
    // Cleanup de listeners se necessário (em single page app, as trocas de DOM removem a maioria automaticamente)
}

function bindDOM() {
    state.dom = {
        alertBox: document.getElementById('alertBox'),
        empresaForm: document.getElementById('empresaForm'),
        logoPreview: document.getElementById('logoPreview'),
        logoUpload: document.getElementById('logoUpload'),
        btnBuscarCNPJ: document.getElementById('btnBuscarCNPJ'),
        btnLimpar: document.getElementById('btnLimpar'),

        cnpj: document.getElementById('cnpj'),
        razao_social: document.getElementById('razao_social'),
        nome_fantasia: document.getElementById('nome_fantasia'),
        endereco_rua: document.getElementById('endereco_rua'),
        endereco_numero: document.getElementById('endereco_numero'),
        endereco_complemento: document.getElementById('endereco_complemento'),
        endereco_bairro: document.getElementById('endereco_bairro'),
        endereco_cidade: document.getElementById('endereco_cidade'),
        endereco_estado: document.getElementById('endereco_estado'),
        endereco_cep: document.getElementById('endereco_cep'),
        email_principal: document.getElementById('email_principal'),
        email_cobranca: document.getElementById('email_cobranca'),
        telefone: document.getElementById('telefone'),
        situacao: document.getElementById('situacao'),
        tabs: Array.from(document.querySelectorAll('[data-empresa-tab]')),
        dadosPane: document.getElementById('empresa-dados-pane'),
        administradoraPane: document.getElementById('empresa-administradora-pane'),
        administradoraSelect: document.getElementById('administradora_id'),
        layoutsLista: document.getElementById('empresa-layouts-lista'),
        layoutsCount: document.getElementById('empresa-layouts-count'),
        administradoraStatus: document.getElementById('empresa-administradora-status'),
        btnSalvarLayoutAdministradora: document.getElementById('btnSalvarLayoutAdministradora'),
        dashboardPane: document.getElementById('empresa-dashboard-pane'),
        dashboardLista: document.getElementById('empresa-dashboard-lista'),
        dashboardStatus: document.getElementById('empresa-dashboard-status'),
        btnSalvarDashboard: document.getElementById('btnSalvarDashboard'),
        btnRestaurarDashboard: document.getElementById('btnRestaurarDashboard')
    };
}

function bindEvents() {
    if (state.dom.btnBuscarCNPJ) {
        state.dom.btnBuscarCNPJ.addEventListener('click', buscarCNPJ);
    }

    if (state.dom.logoUpload) {
        state.dom.logoUpload.addEventListener('change', uploadLogo);
    }

    if (state.dom.empresaForm) {
        state.dom.empresaForm.addEventListener('submit', salvarEmpresa);
    }

    if (state.dom.btnLimpar) {
        state.dom.btnLimpar.addEventListener('click', limparFormulario);
    }
    state.dom.tabs.forEach((tab) => tab.addEventListener('click', () => alternarAbaEmpresa(tab.dataset.empresaTab)));
    if (state.dom.administradoraSelect) state.dom.administradoraSelect.addEventListener('change', renderizarLayoutsAdministradora);
    if (state.dom.btnSalvarLayoutAdministradora) state.dom.btnSalvarLayoutAdministradora.addEventListener('click', salvarLayoutAdministradora);
    if (state.dom.btnSalvarDashboard) state.dom.btnSalvarDashboard.addEventListener('click', salvarDashboardConfig);
    if (state.dom.btnRestaurarDashboard) state.dom.btnRestaurarDashboard.addEventListener('click', restaurarDashboardConfig);
}

function renderizarPreviewLogo(url) {
    if (!state.dom.logoPreview) return;
    const fallback = () => {
        state.dom.logoPreview.replaceChildren();
        const institucional = document.createElement('img');
        institucional.src = '/assets/img/logos/logo_padrao.png';
        institucional.alt = 'ERP Condomínio';
        institucional.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;';
        institucional.addEventListener('error', () => {
            state.dom.logoPreview.innerHTML = '<i class="fas fa-building" aria-hidden="true"></i><span>ERP Condomínio</span>';
        }, { once: true });
        state.dom.logoPreview.appendChild(institucional);
    };
    if (!url) {
        fallback();
        return;
    }

    const caminho = url.startsWith('http') || url.startsWith('/') ? url : '/' + url;
    const separador = caminho.includes('?') ? '&' : '?';
    const imagem = document.createElement('img');
    imagem.src = caminho + separador + 'v=' + Date.now();
    imagem.alt = 'Logo do condomínio';
    imagem.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;';
    imagem.addEventListener('error', () => {
        console.warn('[Empresa] Logo indisponível; exibindo fallback institucional.');
        fallback();
    }, { once: true });
    state.dom.logoPreview.replaceChildren(imagem);
}

function mostrarAlerta(mensagem, tipo = 'success') {
    if (!state.dom.alertBox) return;

    let color = tipo === 'error' ? '#fee2e2' : '#dcfce7';
    let textColor = tipo === 'error' ? '#b91c1c' : '#166534';
    let borderColor = tipo === 'error' ? '#f87171' : '#22c55e';
    let icon = tipo === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle';

    state.dom.alertBox.innerHTML = `
        <div style="background: ${color}; color: ${textColor}; border: 1px solid ${borderColor}; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas ${icon}"></i> ${mensagem}
        </div>`;

    setTimeout(() => {
        if (state.dom.alertBox) state.dom.alertBox.innerHTML = '';
    }, 5000);
}

async function carregarDados() {
    try {
        console.debug('[Empresa] Carregando dados do tenant ativo...');
        const response = await fetch(`${state.apiBase}api_empresa.php?action=obter`, { credentials: 'include' });
        const data = await response.json();
        if (!response.ok || !data.sucesso) {
            throw new Error(data.mensagem || `Erro HTTP ${response.status}`);
        }

        if (data.dados) {
            const empresa = data.dados;

            state.dom.cnpj.value = empresa.cnpj || '';
            state.dom.razao_social.value = empresa.razao_social || '';
            state.dom.nome_fantasia.value = empresa.nome_fantasia || '';
            state.dom.endereco_rua.value = empresa.endereco_rua || '';
            state.dom.endereco_numero.value = empresa.endereco_numero || '';
            state.dom.endereco_complemento.value = empresa.endereco_complemento || '';
            state.dom.endereco_bairro.value = empresa.endereco_bairro || '';
            state.dom.endereco_cidade.value = empresa.endereco_cidade || '';
            state.dom.endereco_estado.value = empresa.endereco_estado || '';
            state.dom.endereco_cep.value = empresa.endereco_cep || '';
            state.dom.email_principal.value = empresa.email_principal || '';
            state.dom.email_cobranca.value = empresa.email_cobranca || '';
            state.dom.telefone.value = empresa.telefone || '';
            state.dom.situacao.value = empresa.situacao || 'ativo';

            // A URL BLOB autenticada é a fonte primária. O caminho legado fica
            // somente como compatibilidade enquanto a migração é concluída.
            renderizarPreviewLogo(empresa.logo_url_segura || empresa.logo_url);
            localStorage.setItem('tenant_id', String(empresa.tenant_id || ''));
            localStorage.setItem('tenant_nome', empresa.nome_fantasia || empresa.razao_social || '');
            localStorage.setItem('tenant_slug', empresa.slug || '');
            console.debug('[Empresa] Dados carregados com sucesso.', { tenant_id: empresa.tenant_id, origem: empresa.origem_dados });
        }
    } catch (error) {
        console.error('[Empresa] Erro ao carregar dados do tenant:', error);
        mostrarAlerta(`Não foi possível carregar os dados do condomínio: ${error.message}`, 'error');
    }
}

async function alternarAbaEmpresa(aba) {
    const panes = { dados: state.dom.dadosPane, administradora: state.dom.administradoraPane, dashboard: state.dom.dashboardPane };
    Object.entries(panes).forEach(([nome, pane]) => { if (pane) pane.hidden = nome !== aba; });
    state.dom.tabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.empresaTab === aba));
    console.debug('[Empresa] Aba alterada.', { aba });
}

function escaparHtml(valor) {
    return String(valor ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
}

function statusLayout(status) {
    const mapa = { PRONTO: 'Pronto para importação', CONFIGURADO: 'Layout configurado', PLANEJADO: 'Em homologação' };
    return mapa[status] || 'Layout configurado';
}

function renderizarStatusAdministradora(mensagem, tipo = 'info') {
    const destino = state.dom.administradoraStatus;
    if (!destino) return;
    destino.hidden = !mensagem;
    destino.className = `empresa-administradora-status ${tipo}`;
    destino.textContent = mensagem || '';
}

function atualizarContagemLayouts() {
    const marcados = state.dom.layoutsLista ? state.dom.layoutsLista.querySelectorAll('input[data-layout-id]:checked').length : 0;
    if (state.dom.layoutsCount) state.dom.layoutsCount.textContent = `${marcados} layout(s) habilitado(s)`;
}

function renderizarLayoutsAdministradora() {
    const administradoraId = Number(state.dom.administradoraSelect?.value || 0);
    const layouts = state.layouts.filter((layout) => Number(layout.administradora_id) === administradoraId);
    const destino = state.dom.layoutsLista;
    if (!destino) return;
    if (!administradoraId) {
        destino.innerHTML = '<p class="empresa-layouts-vazio">Selecione uma administradora para visualizar os layouts de importação disponíveis.</p>';
        atualizarContagemLayouts();
        return;
    }
    if (!layouts.length) {
        destino.innerHTML = '<p class="empresa-layouts-vazio">Nenhum layout ativo foi cadastrado para esta administradora.</p>';
        atualizarContagemLayouts();
        return;
    }
    destino.innerHTML = layouts.map((layout) => {
        const selecionado = state.layoutIdsConfigurados.has(Number(layout.id)) || (administradoraId !== state.administradoraConfiguradaId && state.layoutIdsConfigurados.size === 0);
        return `<label class="empresa-layout-card">
            <input type="checkbox" data-layout-id="${Number(layout.id)}" data-modulo="${escaparHtml(layout.modulo)}" ${selecionado ? 'checked' : ''}>
            <span class="empresa-layout-card-icon"><i class="fas fa-file-alt"></i></span>
            <span class="empresa-layout-card-content"><strong>${escaparHtml(layout.nome)}</strong><small>${escaparHtml(layout.descricao || 'Layout analítico para importação.')}</small><em>${escaparHtml(layout.modulo.replace(/_/g, ' '))} · ${escaparHtml(layout.formato_aceito)}</em></span>
            <span class="empresa-layout-status ${String(layout.status_implantacao || '').toLowerCase()}">${escaparHtml(statusLayout(layout.status_implantacao))}</span>
        </label>`;
    }).join('');
    destino.querySelectorAll('input[data-layout-id]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) {
                destino.querySelectorAll(`input[data-modulo="${CSS.escape(input.dataset.modulo)}"]`).forEach((outro) => {
                    if (outro !== input) outro.checked = false;
                });
            }
            atualizarContagemLayouts();
        });
    });
    atualizarContagemLayouts();
    console.debug('[Empresa] Layouts renderizados.', { administradora_id: administradoraId, total: layouts.length });
}

async function carregarLayoutAdministradora() {
    try {
        const response = await fetch(`${state.apiBase}api_empresa.php?action=administradoras_layouts`, { credentials: 'include' });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || `Erro HTTP ${response.status}`);
        state.administradoras = data.dados.administradoras || [];
        state.layouts = data.dados.layouts || [];
        state.layoutIdsConfigurados = new Set(state.layouts.filter((layout) => Number(layout.selecionado) === 1).map((layout) => Number(layout.id)));
        const select = state.dom.administradoraSelect;
        if (!select) return;
        const configurada = Number(data.dados.configuracao?.administradora_id || 0);
        state.administradoraConfiguradaId = configurada;
        select.innerHTML = '<option value="">Selecione a administradora</option>' + state.administradoras.map((adm) => `<option value="${Number(adm.id)}">${escaparHtml(adm.nome)}</option>`).join('');
        select.value = configurada ? String(configurada) : '';
        renderizarLayoutsAdministradora();
        renderizarStatusAdministradora(configurada ? `Administradora atual: ${data.dados.configuracao.nome}. Ajuste os layouts conforme os relatórios recebidos.` : 'Nenhuma administradora foi selecionada para este condomínio. Ao selecionar uma administradora, os layouts padrão serão sugeridos.');
        console.debug('[Empresa] Layout Administradora carregado.', { administradoras: state.administradoras.length, layouts: state.layouts.length, configurada });
    } catch (error) {
        console.error('[Empresa] Erro ao carregar Layout Administradora:', error);
        renderizarStatusAdministradora(error.message, 'error');
    }
}

async function salvarLayoutAdministradora() {
    const administradoraId = Number(state.dom.administradoraSelect?.value || 0);
    const layoutIds = Array.from(state.dom.layoutsLista?.querySelectorAll('input[data-layout-id]:checked') || []).map((input) => Number(input.dataset.layoutId));
    if (!administradoraId) { mostrarAlerta('Selecione a administradora do empreendimento.', 'error'); return; }
    if (!layoutIds.length) { mostrarAlerta('Selecione pelo menos um layout analítico.', 'error'); return; }
    try {
        const response = await fetch(`${state.apiBase}api_empresa.php?action=salvar_layout_administradora`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ administradora_id: administradoraId, layout_ids: layoutIds })
        });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || `Erro HTTP ${response.status}`);
        state.layoutIdsConfigurados = new Set(data.dados.layout_ids || layoutIds);
        state.administradoraConfiguradaId = administradoraId;
        renderizarStatusAdministradora(`Configuração salva para ${data.dados.administradora?.nome || 'a administradora selecionada'}.`, 'success');
        mostrarAlerta('Layout Administradora salvo com sucesso.', 'success');
        console.debug('[Empresa] Layout Administradora salvo.', { administradora_id: administradoraId, layout_ids: layoutIds });
    } catch (error) {
        console.error('[Empresa] Erro ao salvar Layout Administradora:', error);
        renderizarStatusAdministradora(error.message, 'error');
        mostrarAlerta(`Erro ao salvar Layout Administradora: ${error.message}`, 'error');
    }
}

async function buscarCNPJ() {
    const cnpj = state.dom.cnpj.value;
    if (!cnpj) {
        mostrarAlerta('Por favor, informe um CNPJ', 'error');
        return;
    }

    try {
        const responseValidacao = await fetch(`${state.apiBase}api_empresa.php?action=validar_cnpj&cnpj=${cnpj}`);
        const dataValidacao = await responseValidacao.json();

        if (!dataValidacao.sucesso) {
            mostrarAlerta(dataValidacao.mensagem, 'error');
            return;
        }

        const responseBusca = await fetch(`${state.apiBase}api_empresa.php?action=buscar_cnpj&cnpj=${cnpj}`);
        const dataBusca = await responseBusca.json();

        if (dataBusca.sucesso && dataBusca.dados) {
            const dados = dataBusca.dados;
            state.dom.razao_social.value = dados.razao_social || '';
            state.dom.nome_fantasia.value = dados.nome_fantasia || '';
            state.dom.endereco_rua.value = dados.endereco_rua || '';
            state.dom.endereco_numero.value = dados.endereco_numero || '';
            state.dom.endereco_complemento.value = dados.endereco_complemento || '';
            state.dom.endereco_bairro.value = dados.endereco_bairro || '';
            state.dom.endereco_cidade.value = dados.endereco_cidade || '';
            state.dom.endereco_estado.value = dados.endereco_estado || '';
            state.dom.endereco_cep.value = dados.endereco_cep || '';
            state.dom.telefone.value = dados.telefone || '';

            if (dados.email_principal) {
                state.dom.email_principal.value = dados.email_principal;
            }

            mostrarAlerta('Dados do CNPJ carregados com sucesso!', 'success');
        } else {
            mostrarAlerta(dataBusca.mensagem || 'Erro ao buscar dados do CNPJ', 'error');
        }
    } catch (error) {
        console.error('[Empresa] Erro ao buscar CNPJ:', error);
        mostrarAlerta('Erro ao buscar dados do CNPJ', 'error');
    }
}

async function uploadLogo(e) {
    const arquivo = e.target.files[0];
    if (!arquivo) return;

    if (arquivo.size > 5 * 1024 * 1024) {
        mostrarAlerta('Arquivo muito grande. Máximo 5MB', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('logo', arquivo);

    try {
        const response = await fetch(`${state.apiBase}api_empresa.php?action=upload_logo`, { method: 'POST', body: formData, credentials: 'include' });
        const data = await response.json();

        if (data.sucesso && state.dom.logoPreview) {
            const logoUrl = data.dados.url_segura || data.dados.url;
            console.log('[Empresa] Upload sucesso. URL segura:', logoUrl);
            renderizarPreviewLogo(logoUrl);
            localStorage.setItem('tenant_logo_url', logoUrl);
            mostrarAlerta('Logo enviada com sucesso! A identidade visual foi atualizada para este condomínio.', 'success');

            setTimeout(() => carregarDados(), 1000);
        } else {
            console.error('[Empresa] Erro no upload:', data);
            mostrarAlerta(data.mensagem || 'Erro ao fazer upload da logo', 'error');
        }
    } catch (error) {
        console.error('[Empresa] Erro ao fazer upload:', error);
        mostrarAlerta('Erro ao fazer upload da logo', 'error');
    }
}

async function salvarEmpresa(e) {
    e.preventDefault();

    const dados = {
        cnpj: state.dom.cnpj.value,
        razao_social: state.dom.razao_social.value,
        nome_fantasia: state.dom.nome_fantasia.value,
        endereco_rua: state.dom.endereco_rua.value,
        endereco_numero: state.dom.endereco_numero.value,
        endereco_complemento: state.dom.endereco_complemento.value,
        endereco_bairro: state.dom.endereco_bairro.value,
        endereco_cidade: state.dom.endereco_cidade.value,
        endereco_estado: state.dom.endereco_estado.value,
        endereco_cep: state.dom.endereco_cep.value,
        email_principal: state.dom.email_principal.value,
        email_cobranca: state.dom.email_cobranca.value,
        telefone: state.dom.telefone.value,
        situacao: state.dom.situacao.value
    };

    try {
        const response = await fetch(`${state.apiBase}api_empresa.php?action=atualizar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(dados)
        });

        const data = await response.json();

        if (response.ok && data.sucesso) {
            localStorage.setItem('tenant_nome', dados.nome_fantasia || dados.razao_social);
            mostrarAlerta('Dados do condomínio salvos e sincronizados com o tenant!', 'success');
        } else {
            mostrarAlerta(data.mensagem, 'error');
        }
    } catch (error) {
        console.error('[Empresa] Erro ao salvar:', error);
        mostrarAlerta('Erro ao salvar dados da empresa', 'error');
    }
}

function limparFormulario() {
    if (state.dom.empresaForm) {
        state.dom.empresaForm.reset();
    }
    if (state.dom.logoPreview) {
        state.dom.logoPreview.innerHTML = '<i class="fas fa-image" style="font-size: 2.5rem; color: #cbd5e1;"></i>';
    }
}

function renderizarStatusDashboard(mensagem, tipo = 'info') {
    const destino = state.dom.dashboardStatus;
    if (!destino) return;
    destino.hidden = !mensagem;
    destino.className = `empresa-administradora-status ${tipo}`;
    destino.textContent = mensagem || '';
}

function renderizarDashboardConfig() {
    const destino = state.dom.dashboardLista;
    if (!destino) return;
    const grupos = state.dashboardWidgets.reduce((acc, widget) => {
        (acc[widget.modulo_key] ||= { nome: widget.modulo_nome, icone: widget.modulo_icone, widgets: [] }).widgets.push(widget);
        return acc;
    }, {});
    destino.innerHTML = Object.values(grupos).map((grupo) => `
        <section class="empresa-dashboard-modulo">
            <div class="empresa-dashboard-modulo-head"><h3><i class="${escaparHtml(grupo.icone)}"></i> ${escaparHtml(grupo.nome)}</h3><label class="empresa-dashboard-toggle"><input type="checkbox" data-dashboard-modulo="${escaparHtml(grupo.nome)}" checked><span>Ativar módulo</span></label></div>
            <div class="empresa-dashboard-widgets">${grupo.widgets.map((widget) => `<label class="empresa-dashboard-widget"><input type="checkbox" data-dashboard-widget="${escaparHtml(widget.widget_key)}" ${Number(widget.habilitado) ? 'checked' : ''}><span><strong>${escaparHtml(widget.widget_nome)}</strong><small>${escaparHtml(widget.descricao || 'Widget informativo')}</small><em>${escaparHtml(widget.widget_tipo)} · ${escaparHtml(widget.tamanho_padrao)}</em></span></label>`).join('')}</div>
        </section>`).join('');
    destino.querySelectorAll('[data-dashboard-modulo]').forEach((toggle) => toggle.addEventListener('change', () => {
        const modulo = toggle.closest('.empresa-dashboard-modulo');
        modulo?.querySelectorAll('[data-dashboard-widget]').forEach((widget) => { widget.checked = toggle.checked; });
    }));
    destino.querySelectorAll('[data-dashboard-widget]').forEach((widget) => widget.addEventListener('change', () => {
        const modulo = widget.closest('.empresa-dashboard-modulo');
        const todos = [...modulo.querySelectorAll('[data-dashboard-widget]')];
        const mestre = modulo.querySelector('[data-dashboard-modulo]');
        if (mestre) mestre.checked = todos.some((item) => item.checked);
    }));
}

async function carregarDashboardConfig() {
    try {
        const response = await fetch('/api/api_dashboard_personalizacao.php?acao=empresa_config', { credentials: 'include' });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || `Erro HTTP ${response.status}`);
        state.dashboardWidgets = data.dados?.widgets || [];
        renderizarDashboardConfig();
        renderizarStatusDashboard('A configuração define quais widgets estarão disponíveis para os usuários.', 'info');
    } catch (error) {
        console.error('[Empresa] Erro ao carregar Dashboard:', error);
        renderizarStatusDashboard(error.message, 'error');
        if (state.dom.dashboardLista) state.dom.dashboardLista.innerHTML = '<p class="empresa-layouts-vazio">Não foi possível carregar o catálogo. Execute a migration do Dashboard e tente novamente.</p>';
    }
}

function restaurarDashboardConfig() {
    state.dom.dashboardLista?.querySelectorAll('[data-dashboard-widget]').forEach((item) => { item.checked = true; });
    state.dom.dashboardLista?.querySelectorAll('[data-dashboard-modulo]').forEach((item) => { item.checked = true; });
    renderizarStatusDashboard('Padrão restaurado localmente. Clique em Salvar Dashboard para confirmar.', 'info');
}

async function salvarDashboardConfig() {
    const widgets = [...(state.dom.dashboardLista?.querySelectorAll('[data-dashboard-widget]') || [])].map((input) => ({ widget_key: input.dataset.dashboardWidget, habilitado: input.checked ? 1 : 0 }));
    try {
        const response = await fetch('/api/api_dashboard_personalizacao.php?acao=empresa_config', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ widgets }) });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || `Erro HTTP ${response.status}`);
        renderizarStatusDashboard('Whitelist do Dashboard salva com sucesso.', 'success');
        mostrarAlerta('Configuração do Dashboard salva.', 'success');
    } catch (error) {
        renderizarStatusDashboard(error.message, 'error');
        mostrarAlerta(`Erro ao salvar Dashboard: ${error.message}`, 'error');
    }
}
