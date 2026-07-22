/**
 * IDENTIDADE VISUAL - LOGIN & GLOBAL
 * Gerencia a logo e o nome da empresa em telas de login e provê fallback.
 */
async function carregarIdentidadeVisual(isFrontendPath = false) {
    try {
        const apiPath = isFrontendPath ? '../api/api_empresa.php?action=obter' : 'api/api_empresa.php?action=obter';
        const baseUploads = isFrontendPath ? '../' : '';

        const response = await fetch(apiPath);
        if (!response.ok) return;
        const data = await response.json();

        if (data.sucesso && data.dados) {
            const empresa = data.dados;
            const logoUrl = empresa.logo_url ? baseUploads + empresa.logo_url : null;
            const nomeEmpresa = empresa.nome_fantasia || empresa.razao_social || 'Serra da Liberdade';

            // 1. Atualizar Logo no Login (Várias estruturas possíveis)
            const logoContainers = document.querySelectorAll('.login-logo, .brand-logo, #login-logo');
            logoContainers.forEach(container => {
                if (logoUrl) {
                    if (container.tagName === 'IMG') {
                        container.src = logoUrl;
                    } else {
                        container.innerHTML = `<img src="${logoUrl}" alt="${nomeEmpresa}" style="max-width: 200px; height: auto;">`;
                    }
                }
            });

            // 2. Atualizar Nomes em Headers de Login
            const loginHeaders = document.querySelectorAll('.login-header h1, .login-container h1, .brand-name');
            loginHeaders.forEach(h1 => {
                h1.textContent = nomeEmpresa;
            });

            // 3. Atualizar Título da Aba
            if (document.title.includes('Serra da Liberdade')) {
                document.title = document.title.replace('Serra da Liberdade', nomeEmpresa);
            }

            // 4. Suporte para a nova Sidebar (Fallback)
            const dynamicSidebarLogo = document.getElementById('dynamicSidebarLogo');
            if (dynamicSidebarLogo && logoUrl) {
                dynamicSidebarLogo.src = logoUrl;
            }
        }
    } catch (error) {
        console.error('[Identidade Visual] Erro ao carregar:', error);
    }
}

// Auto-executar baseado no contexto
document.addEventListener('DOMContentLoaded', () => {
    const isFrontend = window.location.pathname.includes('/frontend/');
    carregarIdentidadeVisual(isFrontend);
});
