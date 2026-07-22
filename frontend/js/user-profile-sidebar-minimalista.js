/**
 * =====================================================
 * USER PROFILE SIDEBAR - Versão Minimalista
 * =====================================================
 * Sidebar simplificada com APENAS logo e menu
 * 
 * ALTERAÇÕES:
 * 1. Removido: Título "Serra da Liberdade"
 * 2. Removido: Bloco de perfil (avatar, nome, função, status)
 * 3. Mantido: Logo dinâmica centralizada
 * 4. Mantido: Menu de navegação com "Regra de Ouro"
 * 5. Removido: Footer com tempo de sessão
 * 
 * Justificativa: Informações de perfil consolidadas no cabeçalho global
 */

(function () {
    'use strict';

    // Configurações
    const CONFIG = {
        logoPath: '../uploads/logo/logo_1769740112.jpeg',
        companyName: 'Serra da Liberdade',
        extensoes: ['jpeg', 'jpg', 'png', 'webp', 'gif']
    };

    /**
     * Inicializar o script
     */
    function inicializar() {
        console.log('🔧 User Profile Sidebar Minimalista inicializado');

        // Adicionar estilos CSS
        adicionarEstilos();

        // Carregar logo dinâmica
        carregarLogo();

        console.log('✅ User Profile Sidebar Minimalista pronto');
    }

    /**
     * Carregar logo dinamicamente com fallback
     */
    function carregarLogo() {
        const sidebar = document.querySelector('.sidebar');

        if (!sidebar) {
            console.warn('Sidebar não encontrado');
            return;
        }

        const sidebarHeader = sidebar.querySelector('.sidebar-header');

        if (!sidebarHeader) {
            console.warn('Sidebar header não encontrado');
            return;
        }

        // Limpar header (remover título)
        sidebarHeader.innerHTML = '';

        // Criar container para logo
        const logoContainer = document.createElement('div');
        logoContainer.className = 'sidebar-logo-container-minimal';
        sidebarHeader.appendChild(logoContainer);

        // Tentar carregar logo com diferentes extensões
        tentarCarregarLogo(logoContainer, 0);
    }

    /**
     * Tentar carregar logo com uma extensão
     */
    function tentarCarregarLogo(container, index) {
        if (index >= CONFIG.extensoes.length) {
            // Nenhuma extensão funcionou, usar fallback
            exibirFallback(container);
            return;
        }

        const extensao = CONFIG.extensoes[index];
        const caminhoLogo = `${CONFIG.logoPath}.${extensao}`;

        const img = document.createElement('img');
        img.alt = 'Logo da Empresa';
        img.className = 'sidebar-logo-minimal';

        img.onload = function () {
            container.innerHTML = '';
            container.appendChild(img);
            console.log(`✅ Logo carregada: ${caminhoLogo}`);
        };

        img.onerror = function () {
            // Tentar próxima extensão
            tentarCarregarLogo(container, index + 1);
        };

        img.src = caminhoLogo;
    }

    /**
     * Exibir fallback com texto elegante
     */
    function exibirFallback(container) {
        container.innerHTML = `
            <div class="logo-fallback-minimal">
                <div class="logo-fallback-text-minimal">${CONFIG.companyName}</div>
            </div>
        `;
        console.log(`⚠️ Logo não encontrada. Exibindo fallback: ${CONFIG.companyName}`);
    }

    /**
     * Adicionar estilos CSS
     */
    function adicionarEstilos() {
        if (document.getElementById('user-profile-sidebar-minimal-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'user-profile-sidebar-minimal-styles';
        style.textContent = `
            /* ===== SIDEBAR MINIMALISTA ===== */
            
            .sidebar {
                display: flex;
                flex-direction: column;
                height: 100vh;
            }
            
            /* Logo Container - Minimalista */
            .sidebar-logo-container-minimal {
                padding: 25px 15px;
                text-align: center;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                min-height: 100px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255, 255, 255, 0.02);
                transition: all 0.3s ease;
            }
            
            .sidebar-logo-minimal {
                max-width: 140px;
                height: auto;
                object-fit: contain;
                filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
                transition: all 0.3s ease;
            }
            
            .sidebar-logo-minimal:hover {
                filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
                transform: scale(1.02);
            }
            
            /* Logo Fallback */
            .logo-fallback-minimal {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                height: 100%;
            }
            
            .logo-fallback-text-minimal {
                color: #cbd5e1;
                font-size: 0.9rem;
                font-weight: 600;
                text-align: center;
                letter-spacing: 0.5px;
                line-height: 1.4;
                padding: 0 10px;
                text-transform: uppercase;
            }
            
            /* ===== MENU DE NAVEGAÇÃO ===== */
            
            .nav-menu {
                list-style: none;
                padding: 1rem;
                flex: 1;
                overflow-y: auto;
            }
            
            .nav-item {
                margin-bottom: 0.5rem;
            }
            
            .nav-link {
                display: flex;
                align-items: center;
                padding: 0.75rem 1rem;
                color: #cbd5e1;
                text-decoration: none;
                border-radius: 8px;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                position: relative;
                overflow: hidden;
            }
            
            /* Regra de Ouro - Hover */
            .nav-link:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #ffffff;
                padding-left: 1.25rem;
            }
            
            /* Regra de Ouro - Active */
            .nav-link.active {
                background: linear-gradient(90deg, rgba(37, 99, 235, 0.2) 0%, rgba(37, 99, 235, 0.1) 100%);
                color: #2563eb;
                border-left: 3px solid #2563eb;
                padding-left: calc(1rem - 3px);
                font-weight: 600;
            }
            
            /* Ícone do Menu */
            .nav-link i {
                margin-right: 0.75rem;
                width: 20px;
                text-align: center;
                transition: all 0.2s;
            }
            
            /* Ícone Active */
            .nav-link.active i {
                color: #2563eb;
            }
            
            /* Divider */
            .nav-divider {
                height: 1px;
                background: rgba(255, 255, 255, 0.1);
                margin: 1rem 0;
            }
            
            /* Botão Logout */
            .nav-link-logout {
                background: rgba(239, 68, 68, 0.1) !important;
                color: #fca5a5 !important;
                margin-top: 0.5rem;
                border-left: 3px solid transparent;
            }
            
            .nav-link-logout:hover {
                background: rgba(239, 68, 68, 0.2) !important;
                color: #fef2f2 !important;
                border-left-color: #ef4444;
                padding-left: calc(1rem - 3px);
            }
            
            .nav-link-logout i {
                color: #fca5a5;
            }
            
            /* ===== RESPONSIVO ===== */
            
            @media (max-width: 768px) {
                .sidebar-logo-container-minimal {
                    min-height: 80px;
                    padding: 15px 10px;
                }
                
                .sidebar-logo-minimal {
                    max-width: 120px;
                }
                
                .logo-fallback-text-minimal {
                    font-size: 0.8rem;
                }
                
                .nav-menu {
                    padding: 0.75rem;
                }
                
                .nav-item {
                    margin-bottom: 0.25rem;
                }
                
                .nav-link {
                    padding: 0.6rem 0.75rem;
                    font-size: 0.9rem;
                }
                
                .nav-link i {
                    width: 18px;
                    margin-right: 0.5rem;
                }
            }
            
            @media (max-width: 480px) {
                .sidebar-logo-container-minimal {
                    min-height: 70px;
                    padding: 12px 8px;
                }
                
                .sidebar-logo-minimal {
                    max-width: 100px;
                }
                
                .logo-fallback-text-minimal {
                    font-size: 0.75rem;
                }
                
                .nav-menu {
                    padding: 0.5rem;
                }
                
                .nav-link {
                    padding: 0.5rem 0.6rem;
                    font-size: 0.85rem;
                }
                
                .nav-link i {
                    width: 16px;
                    margin-right: 0.4rem;
                }
            }
            
            /* ===== ANIMAÇÕES ===== */
            
            .nav-link {
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            .nav-link:active {
                transform: translateY(1px);
            }
            
            /* ===== ACESSIBILIDADE ===== */
            
            @media (prefers-reduced-motion: reduce) {
                .sidebar-logo-minimal,
                .nav-link {
                    transition: none !important;
                }
            }
            
            .nav-link:focus {
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
