/**
 * =====================================================
 * LOGOUT MODAL UNIFIED - Gerenciador de Modal de Logout
 * =====================================================
 * Modal de confirmação seguro para logout
 * Sincronizado com sessao_manager.js
 * 
 * IDs Preservados:
 * - btn-logout
 * - sessionTimer
 * - sessionStatus
 * 
 * Fluxo:
 * 1. Clique em btn-logout abre o modal
 * 2. Confirmação chama sessao_manager.logout()
 * 3. Limpeza de token_acesso e storage
 * 4. Redirecionamento para login
 */

(function() {
    'use strict';

    const CONFIG = {
        modalId: 'logoutModal',
        overlayId: 'logoutModalOverlay',
        confirmButtonId: 'logoutConfirmBtn',
        cancelButtonId: 'logoutCancelBtn',
        logoutButtonId: 'btn-logout',
        sessionTimerId: 'sessionTimer',
        sessionStatusId: 'sessionStatus'
    };

    let modalElement = null;
    let overlayElement = null;
    let isProcessing = false;

    /**
     * Inicializar o gerenciador de modal
     */
    function inicializar() {
        console.log('🔐 Logout Modal Unified inicializado');

        // Criar HTML do modal
        criarModal();

        // Adicionar estilos CSS
        adicionarEstilos();

        // Configurar event listeners
        configurarEventListeners();

        console.log('✅ Logout Modal Unified pronto');
    }

    /**
     * Criar HTML do modal
     */
    function criarModal() {
        // Verificar se já existe
        if (document.getElementById(CONFIG.overlayId)) {
            overlayElement = document.getElementById(CONFIG.overlayId);
            modalElement = document.getElementById(CONFIG.modalId);
            return;
        }

        // HTML do modal
        const modalHTML = `
            <div class="logout-modal-overlay" id="${CONFIG.overlayId}">
                <div class="logout-modal-container" id="${CONFIG.modalId}">
                    <div class="logout-modal-header">
                        <div class="logout-modal-icon">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                        <h2 class="logout-modal-title">Confirmar Logout</h2>
                    </div>

                    <div class="logout-modal-body">
                        <p class="logout-modal-message">
                            Você tem certeza que deseja sair do sistema?
                        </p>
                        <p class="logout-modal-message">
                            Sua sessão será encerrada e você será redirecionado para a página de login.
                        </p>
                        <div class="logout-modal-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Certifique-se de salvar qualquer trabalho em progresso antes de sair.</span>
                        </div>
                    </div>

                    <div class="logout-modal-footer">
                        <button class="logout-modal-button logout-modal-cancel" id="${CONFIG.cancelButtonId}">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </button>
                        <button class="logout-modal-button logout-modal-confirm" id="${CONFIG.confirmButtonId}">
                            <i class="fas fa-check"></i>
                            Confirmar Logout
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Inserir no DOM
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Obter referências
        overlayElement = document.getElementById(CONFIG.overlayId);
        modalElement = document.getElementById(CONFIG.modalId);
    }

    /**
     * Adicionar estilos CSS
     */
    function adicionarEstilos() {
        // Verificar se CSS já foi adicionado
        if (document.getElementById('logout-modal-css')) {
            return;
        }

        const linkElement = document.createElement('link');
        linkElement.id = 'logout-modal-css';
        linkElement.rel = 'stylesheet';
        linkElement.href = '../css/logout-modal.css';
        document.head.appendChild(linkElement);
    }

    /**
     * Configurar event listeners
     */
    function configurarEventListeners() {
        // Botão de logout
        const btnLogout = document.getElementById(CONFIG.logoutButtonId);
        if (btnLogout) {
            btnLogout.addEventListener('click', function(e) {
                e.preventDefault();
                abrirModal();
            });
        }

        // Botão cancelar
        const btnCancel = document.getElementById(CONFIG.cancelButtonId);
        if (btnCancel) {
            btnCancel.addEventListener('click', fecharModal);
        }

        // Botão confirmar
        const btnConfirm = document.getElementById(CONFIG.confirmButtonId);
        if (btnConfirm) {
            btnConfirm.addEventListener('click', confirmarLogout);
        }

        // Fechar ao clicar no overlay
        if (overlayElement) {
            overlayElement.addEventListener('click', function(e) {
                if (e.target === overlayElement) {
                    fecharModal();
                }
            });
        }

        // Fechar com ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlayElement?.classList.contains('active')) {
                fecharModal();
            }
        });
    }

    /**
     * Abrir modal
     */
    function abrirModal() {
        if (!overlayElement) {
            console.warn('Modal overlay não encontrado');
            return;
        }

        console.log('🔓 Abrindo modal de logout');
        overlayElement.classList.add('active');
        overlayElement.classList.remove('closing');

        // Focar no botão de cancelar para acessibilidade
        const btnCancel = document.getElementById(CONFIG.cancelButtonId);
        if (btnCancel) {
            setTimeout(() => btnCancel.focus(), 100);
        }
    }

    /**
     * Fechar modal
     */
    function fecharModal() {
        if (!overlayElement) {
            return;
        }

        console.log('🔒 Fechando modal de logout');
        overlayElement.classList.remove('active');

        // Focar no botão de logout
        const btnLogout = document.getElementById(CONFIG.logoutButtonId);
        if (btnLogout) {
            setTimeout(() => btnLogout.focus(), 100);
        }
    }

    /**
     * Confirmar logout
     */
    async function confirmarLogout() {
        if (isProcessing) {
            return;
        }

        isProcessing = true;

        const btnConfirm = document.getElementById(CONFIG.confirmButtonId);
        if (btnConfirm) {
            btnConfirm.disabled = true;
            btnConfirm.classList.add('loading');
            btnConfirm.innerHTML = '<span class="logout-modal-spinner"></span> Saindo...';
        }

        try {
            console.log('🔐 Processando logout...');

            // Chamar função de logout do sessao_manager
            if (window.sessaoManager && typeof window.sessaoManager.logout === 'function') {
                console.log('✅ Chamando sessao_manager.logout()');
                await window.sessaoManager.logout();
            } else {
                console.warn('⚠️ sessao_manager não disponível, fazendo logout manual');
                await fazerLogoutManual();
            }
        } catch (error) {
            console.error('❌ Erro ao fazer logout:', error);
            isProcessing = false;

            if (btnConfirm) {
                btnConfirm.disabled = false;
                btnConfirm.classList.remove('loading');
                btnConfirm.innerHTML = '<i class="fas fa-check"></i> Confirmar Logout';
            }

            // Mostrar erro
            alert('Erro ao fazer logout. Tente novamente.');
        }
    }

    /**
     * Fazer logout manual (fallback)
     */
    async function fazerLogoutManual() {
        try {
            // Limpar localStorage
            localStorage.removeItem('token_acesso');
            localStorage.removeItem('usuario_id');
            localStorage.removeItem('usuario_nome');
            localStorage.removeItem('usuario_funcao');
            localStorage.removeItem('usuario_email');

            // Limpar sessionStorage
            sessionStorage.removeItem('token_acesso');
            sessionStorage.removeItem('sessao_ativa');

            // Chamar API de logout
            const response = await fetch('../api/verificar_sessao_completa.php', {
                method: 'POST',
                body: new FormData(Object.assign(new FormData(), { acao: 'logout' })),
                credentials: 'include'
            });

            console.log('✅ Logout manual realizado');

            // Aguardar 1 segundo e redirecionar
            setTimeout(() => {
                window.location.href = window.location.origin + '/frontend/login.html';
            }, 1000);
        } catch (error) {
            console.error('Erro no logout manual:', error);
            // Mesmo com erro, redirecionar
            setTimeout(() => {
                window.location.href = window.location.origin + '/frontend/login.html';
            }, 1000);
        }
    }

    /**
     * Inicializar quando DOM estiver pronto
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    // Expor funções globalmente para debug
    window.logoutModalManager = {
        abrirModal,
        fecharModal,
        confirmarLogout
    };

})();
