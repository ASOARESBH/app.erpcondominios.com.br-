/**
 * Controlador da página de Empresa
 */

const state = {
    apiBase: '/api/',
    dom: {}
};

export function init() {
    console.log('[Empresa] Inicializando...');
    bindDOM();
    bindEvents();
    carregarDados();
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
        situacao: document.getElementById('situacao')
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
        const response = await fetch(`${state.apiBase}api_empresa.php?action=obter`);
        const data = await response.json();

        if (data.sucesso && data.dados) {
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

            if (empresa.logo_url && state.dom.logoPreview) {
                let logoUrl = empresa.logo_url;
                if (!logoUrl.startsWith('http') && !logoUrl.startsWith('/')) {
                    logoUrl = '/' + logoUrl;
                }
                state.dom.logoPreview.innerHTML = `<img src="${logoUrl}" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
            }
        }
    } catch (error) {
        console.error('[Empresa] Erro ao carregar dados:', error);
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
        const response = await fetch(`${state.apiBase}api_empresa.php?action=upload_logo`, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.sucesso && state.dom.logoPreview) {
            const logoUrl = data.dados.url;
            console.log('[Empresa] Upload sucesso. URL:', logoUrl);
            state.dom.logoPreview.innerHTML = `<img src="${logoUrl}" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
            mostrarAlerta('Logo enviada com sucesso!', 'success');

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
            body: JSON.stringify(dados)
        });

        const data = await response.json();

        if (data.sucesso) {
            mostrarAlerta('Dados da empresa salvos com sucesso!', 'success');
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
