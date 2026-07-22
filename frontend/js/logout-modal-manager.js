/**
 * =====================================================
 * LOGOUT MODAL MANAGER - Fluxo Seguro de Logout
 * =====================================================
 * Gerencia o modal de confirmação de logout
 * Integra com sessao_manager.js para limpeza segura
 * 
 * FLUXO:
 * 1. Usuário clica em "Sair"
 * 2. Modal de confirmação abre
 * 3. Usuário confirma ou cancela
 * 4. Se confirmar: chama sessao_manager.logout()
 * 5. Limpa token_acesso, localStorage, sessionStorage
 * 6. Redireciona para login.html
 */

(function() {
    'use strict';
    
    // Configurações
    const CONFIG = {
        modalId: 'logoutModal',
        btnLogoutId: 'btn-logout',
        btnConfirmId: 'btnConfirmLogout',
        btnCancelId: 'btnCancelLogout',
        logoutApiUrl: '../api/logout.php'
    };
    
    /**
     * Inicializar o gerenciador
     */
    function inicializar() {
        console.log('🔧 Logout Modal Manager inicializado');
        
        // Adicionar estilos CSS
        adicionarEstilos();
        
        // Criar modal de logout
        criarModal();
        
        // Interceptar botões de logout
        interceptarBotoesLogout();
        
        console.log('✅ Logout Modal Manager pronto');
    }
    
    /**
     * Criar modal de logout
     */
    function criarModal() {
        // Verificar se modal já existe
        if (document.getElementById(CONFIG.modalId)) {
            return;
        }
        
        const modalHTML = `
            <div class="logout-modal-overlay" id="${CONFIG.modalId}">
                <div class="logout-modal-content">
                    <div class="logout-modal-icon">
                        <i class="fas fa-sign-out-alt"></i>
                    </div>
                    <h3 class="logout-modal-title">Encerrar Sessão?</h3>
                    <p class="logout-modal-text">
                        Você está prestes a sair do sistema. 
                        Sua sessão será encerrada com segurança.
                    </p>
                    <div class="logout-modal-buttons">
                        <button class="btn-modal btn-modal-cancel" id="${CONFIG.btnCancelId}">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button class="btn-modal btn-modal-confirm" id="${CONFIG.btnConfirmId}">
                            <i class="fas fa-check"></i> Sim, Sair
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Adicionar event listeners
        const btnConfirm = document.getElementById(CONFIG.btnConfirmId);
        const btnCancel = document.getElementById(CONFIG.btnCancelId);
        
        if (btnConfirm) {
            btnConfirm.addEventListener('click', confirmarLogout);
        }
        
        if (btnCancel) {
            btnCancel.addEventListener('click', cancelarLogout);
        }
        
        // Fechar modal ao clicar fora
        const modal = document.getElementById(CONFIG.modalId);
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    fecharModal();
                }
            });
        }
    }
    
    /**
     * Interceptar botões de logout
     */
    function interceptarBotoesLogout() {
        const btnLogout = document.getElementById(CONFIG.btnLogoutId);
        
        if (btnLogout) {
            // Remover onclick anterior
            btnLogout.removeAttribute('onclick');
            
            // Adicionar novo listener
            btnLogout.addEventListener('click', function(e) {
                e.preventDefault();
                abrirModal();
            });
            
            console.log('✅ Botão de logout interceptado');
        } else {
            console.warn('⚠️ Botão de logout não encontrado');
        }
    }
    
    /**
     * Abrir modal de logout
     */
    function abrirModal() {
        const modal = document.getElementById(CONFIG.modalId);
        
        if (modal) {
            modal.style.display = 'flex';
            // Trigger animação
            setTimeout(() => {
                modal.classList.add('show');
            }, 10);
            
            console.log('📋 Modal de logout aberto');
        }
    }
    
    /**
     * Fechar modal de logout
     */
    function fecharModal() {
        const modal = document.getElementById(CONFIG.modalId);
        
        if (modal) {
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
            
            console.log('📋 Modal de logout fechado');
        }
    }
    
    /**
     * Cancelar logout
     */
    function cancelarLogout() {
        console.log('❌ Logout cancelado pelo usuário');
        fecharModal();
    }
    
    /**
     * Confirmar logout
     */
    function confirmarLogout() {
        console.log('✅ Logout confirmado pelo usuário');
        
        // Fechar modal
        fecharModal();
        
        // Aguardar fechamento da animação
        setTimeout(() => {
            executarLogout();
        }, 300);
    }
    
    /**
     * Executar logout seguro
     */
    function executarLogout() {
        console.log('🚀 Executando logout seguro...');
        
        // Verificar se sessao_manager está disponível
        if (window.sessaoManager && typeof window.sessaoManager.logout === 'function') {
            console.log('📞 Chamando sessao_manager.logout()');
            window.sessaoManager.logout();
        } else {
            console.warn('⚠️ sessao_manager não disponível, fazendo logout direto');
            fazerLogoutDireto();
        }
    }
    
    /**
     * Fazer logout direto (fallback)
     */
    function fazerLogoutDireto() {
        console.log('🔄 Fazendo logout direto (fallback)');
        
        // Chamar API de logout
        fetch(CONFIG.logoutApiUrl, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            console.log('✅ Logout realizado pela API');
            limparDadosLocais();
        })
        .catch(error => {
            console.error('❌ Erro ao fazer logout:', error);
            // Mesmo com erro, limpar dados locais
            limparDadosLocais();
        });
    }
    
    /**
     * Limpar dados locais
     */
    function limparDadosLocais() {
        console.log('🧹 Limpando dados locais...');
        
        // Limpar localStorage
        localStorage.clear();
        console.log('✅ localStorage limpo');
        
        // Limpar sessionStorage
        sessionStorage.clear();
        console.log('✅ sessionStorage limpo');
        
        // Remover token_acesso específico
        if (localStorage.getItem('token_acesso')) {
            localStorage.removeItem('token_acesso');
            console.log('✅ token_acesso removido');
        }
        
        // Aguardar e redirecionar
        console.log('🔄 Redirecionando para login...');
        setTimeout(() => {
            window.location.href = window.location.origin + '/frontend/login.html';
        }, 500);
    }
    
    /**
     * Adicionar estilos CSS
     */
    function adicionarEstilos() {
        if (document.getElementById('logout-modal-manager-styles')) {
            return;
        }
        
        const style = document.createElement('style');
        style.id = 'logout-modal-manager-styles';
        style.textContent = `
            /* ===== MODAL OVERLAY ===== */
            
            .logout-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                backdrop-filter: blur(4px);
                z-index: 10000;
                display: none;
                align-items: center;
                justify-content: center;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            
            .logout-modal-overlay.show {
                opacity: 1;
            }
            
            /* ===== MODAL CONTENT ===== */
            
            .logout-modal-content {
                background: #1e293b;
                color: white;
                padding: 2rem;
                border-radius: 16px;
                width: 90%;
                max-width: 400px;
                text-align: center;
                border: 1px solid rgba(255, 255, 255, 0.1);
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
                transform: scale(0.9);
                transition: transform 0.3s ease;
            }
            
            .logout-modal-overlay.show .logout-modal-content {
                transform: scale(1);
            }
            
            /* ===== MODAL ICON ===== */
            
            .logout-modal-icon {
                font-size: 3.5rem;
                color: #2563eb;
                margin-bottom: 1rem;
                animation: iconPulse 2s infinite;
            }
            
            @keyframes iconPulse {
                0%, 100% {
                    transform: scale(1);
                }
                50% {
                    transform: scale(1.1);
                }
            }
            
            /* ===== MODAL TITLE ===== */
            
            .logout-modal-title {
                font-size: 1.25rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
                color: #ffffff;
            }
            
            /* ===== MODAL TEXT ===== */
            
            .logout-modal-text {
                color: #94a3b8;
                font-size: 0.95rem;
                margin-bottom: 1.5rem;
                line-height: 1.5;
            }
            
            /* ===== MODAL BUTTONS ===== */
            
            .logout-modal-buttons {
                display: flex;
                gap: 1rem;
                justify-content: center;
            }
            
            .btn-modal {
                padding: 0.6rem 1.5rem;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                border: none;
                font-size: 0.95rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                justify-content: center;
            }
            
            .btn-modal:hover {
                transform: translateY(-2px);
            }
            
            .btn-modal:active {
                transform: translateY(0);
            }
            
            /* ===== BOTÃO CANCELAR ===== */
            
            .btn-modal-cancel {
                background: transparent;
                color: #cbd5e1;
                border: 1px solid rgba(255, 255, 255, 0.2);
            }
            
            .btn-modal-cancel:hover {
                background: rgba(255, 255, 255, 0.05);
                color: white;
                border-color: rgba(255, 255, 255, 0.3);
            }
            
            /* ===== BOTÃO CONFIRMAR ===== */
            
            .btn-modal-confirm {
                background: #2563eb;
                color: white;
                border: none;
            }
            
            .btn-modal-confirm:hover {
                background: #1d4ed8;
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            }
            
            /* ===== RESPONSIVO ===== */
            
            @media (max-width: 480px) {
                .logout-modal-content {
                    padding: 1.5rem;
                    width: 95%;
                }
                
                .logout-modal-icon {
                    font-size: 2.5rem;
                }
                
                .logout-modal-title {
                    font-size: 1.1rem;
                }
                
                .logout-modal-text {
                    font-size: 0.9rem;
                }
                
                .btn-modal {
                    padding: 0.5rem 1rem;
                    font-size: 0.9rem;
                }
                
                .logout-modal-buttons {
                    flex-direction: column;
                    gap: 0.75rem;
                }
                
                .btn-modal {
                    width: 100%;
                }
            }
            
            /* ===== ACESSIBILIDADE ===== */
            
            @media (prefers-reduced-motion: reduce) {
                .logout-modal-overlay,
                .logout-modal-content,
                .logout-modal-icon,
                .btn-modal {
                    transition: none !important;
                    animation: none !important;
                }
            }
            
            .btn-modal:focus {
                outline: 2px solid #2563eb;
                outline-offset: 2px;
            }
        `;
        
        document.head.appendChild(style);
    }
    
    /**
     * Inicializar quando DOM estiver pronto
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }
    
})();
