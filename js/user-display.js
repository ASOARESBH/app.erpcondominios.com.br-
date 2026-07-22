/**
 * =====================================================
 * USER DISPLAY - Exibir Usuário Logado no Menu
 * =====================================================
 * Adiciona informações do usuário logado no menu lateral
 */

(function() {
    'use strict';
    
    // Aguardar evento de autenticação
    document.addEventListener('usuarioAutenticado', function(e) {
        const usuario = e.detail;
        exibirUsuarioNoMenu(usuario);
    });
    
    // Também tentar carregar do sessionStorage (caso página seja recarregada)
    document.addEventListener('DOMContentLoaded', function() {
        const nome = sessionStorage.getItem('usuario_nome');
        const email = sessionStorage.getItem('usuario_email');
        const permissao = sessionStorage.getItem('usuario_permissao');
        
        if (nome && email && permissao) {
            exibirUsuarioNoMenu({ nome, email, permissao });
        }
    });
    
    function exibirUsuarioNoMenu(usuario) {
        // Encontrar o sidebar
        const sidebar = document.querySelector('.sidebar') || document.getElementById('sidebar');
        
        if (!sidebar) {
            console.warn('Sidebar não encontrado');
            return;
        }
        
        // Verificar se já existe o perfil do usuário
        if (sidebar.querySelector('.user-profile')) {
            return; // Já foi adicionado
        }
        
        // Criar HTML do perfil do usuário
        const perfilHTML = criarPerfilHTML(usuario);
        
        // Inserir após o título (h1)
        const titulo = sidebar.querySelector('h1');
        if (titulo) {
            titulo.insertAdjacentHTML('afterend', perfilHTML);
        }
        
        // Adicionar botão de sair no final do menu
        adicionarBotaoSair(sidebar);
        
        // Adicionar estilos CSS
        adicionarEstilos();
    }
    
    function criarPerfilHTML(usuario) {
        const inicial = usuario.nome.charAt(0).toUpperCase();
        const nomeExibicao = usuario.nome.length > 20 ? usuario.nome.substring(0, 20) + '...' : usuario.nome;
        
        const roles = {
            'admin': 'Administrador',
            'gerente': 'Gerente',
            'operador': 'Operador',
            'visualizador': 'Visualizador'
        };
        const roleExibicao = roles[usuario.permissao] || 'Usuário';
        
        return `
            <div class="user-profile">
                <div class="user-avatar">${inicial}</div>
                <div class="user-name" title="${usuario.nome}">${nomeExibicao}</div>
                <div class="user-role">${roleExibicao}</div>
            </div>
        `;
    }
    
    function adicionarBotaoSair(sidebar) {
        // Encontrar o menu de navegação
        const navMenu = sidebar.querySelector('.nav-menu');
        
        if (!navMenu) {
            console.warn('Menu de navegação não encontrado');
            return;
        }
        
        // Verificar se já existe o botão de sair
        if (navMenu.querySelector('.nav-link-logout')) {
            return; // Já foi adicionado
        }
        
        // Criar HTML do botão de sair
        const sairHTML = `
            <div class="nav-divider"></div>
            <li class="nav-item">
                <a href="#" class="nav-link nav-link-logout" onclick="fazerLogoutGlobal(event); return false;">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </li>
        `;
        
        // Adicionar no final do menu
        navMenu.insertAdjacentHTML('beforeend', sairHTML);
    }
    
    // Função global de logout
    window.fazerLogoutGlobal = function(event) {
        if (event) event.preventDefault();
        
        if (confirm('Deseja realmente sair do sistema?')) {
            fetch('../api/logout.php', {
                method: 'POST',
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                // Limpar sessionStorage
                sessionStorage.clear();
                
                // Redirecionar para login
                window.location.href = 'login.html';
            })
            .catch(error => {
                console.error('Erro ao fazer logout:', error);
                // Mesmo com erro, limpar e redirecionar
                sessionStorage.clear();
                window.location.href = 'login.html';
            });
        }
    }
    
    function adicionarEstilos() {
        // Verificar se os estilos já foram adicionados
        if (document.getElementById('user-display-styles')) {
            return;
        }
        
        const style = document.createElement('style');
        style.id = 'user-display-styles';
        style.textContent = `
            /* Perfil do Usuário */
            .user-profile {
                padding: 1rem;
                margin: 0 1rem 1rem 1rem;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 10px;
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .user-profile .user-avatar {
                width: 50px;
                height: 50px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 0.75rem;
                font-size: 1.5rem;
                color: #fff;
                font-weight: bold;
            }
            
            .user-profile .user-name {
                color: #fff;
                font-size: 0.95rem;
                font-weight: 600;
                text-align: center;
                margin-bottom: 0.25rem;
                word-wrap: break-word;
            }
            
            .user-profile .user-role {
                color: #94a3b8;
                font-size: 0.8rem;
                text-align: center;
                text-transform: capitalize;
            }
            
            /* Divider */
            .nav-divider {
                height: 1px;
                background: rgba(255, 255, 255, 0.1);
                margin: 1rem 0;
            }
            
            /* Botão Sair */
            .nav-link-logout {
                background: rgba(239, 68, 68, 0.1) !important;
                color: #fca5a5 !important;
                margin-top: 0.5rem;
            }
            
            .nav-link-logout:hover {
                background: rgba(239, 68, 68, 0.2) !important;
                color: #fef2f2 !important;
            }
        `;
        
        document.head.appendChild(style);
    }
    
})();

