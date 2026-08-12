/**
 * IDENTIDADE VISUAL DA PLATAFORMA
 *
 * Regra Multi-Tenant:
 * - Qualquer tela de login exibe somente a marca ERP Condomínio.
 * - A identidade de um tenant é aplicada exclusivamente após autenticação,
 *   pelo user-profile-sidebar.js com base no tenant_id da sessão.
 */
(function () {
    'use strict';

    function isTelaDeLogin() {
        return /(^|\/)(login|login_morador|login_fornecedor)\.html$/i.test(window.location.pathname);
    }

    function aplicarMarcaInstitucionalNoLogin() {
        const logoInstitucional = '/assets/img/logos/logo_padrao.png';
        const seletores = ['.login-logo', '.brand-logo', '#login-logo', '#loginLogo'];
        document.querySelectorAll(seletores.join(',')).forEach(elemento => {
            if (elemento.tagName === 'IMG') {
                elemento.src = logoInstitucional;
                elemento.alt = 'ERP Condomínio';
            }
        });

        document.querySelectorAll('.login-header h1, .login-container h1, .brand-name').forEach(titulo => {
            titulo.textContent = 'ERP Condomínio';
        });

        document.title = 'Login - ERP Condomínio — Gestão Inteligente';
        console.info('[LoginBranding] Identidade institucional preservada; logo de tenant não é carregada.');
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (isTelaDeLogin()) aplicarMarcaInstitucionalNoLogin();
    });
})();
