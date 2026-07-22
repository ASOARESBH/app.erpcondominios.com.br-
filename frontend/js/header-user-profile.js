/**
 * =====================================================
 * HEADER USER PROFILE - Componente de Cabeçalho Global
 * =====================================================
 * Implementa o bloco de identidade do usuário no cabeçalho esquerdo
 * com avatar azul, nome em CAPS LOCK, função e status "Ativo"
 * 
 * ✅ CORRIGIDO (v6.0): Integrado com SessionManagerSingleton
 * - Remove polling de 1s
 * - Escuta eventos do gerenciador centralizado
 */

(function () {
    'use strict';

    // Configurações (SEM updateInterval — usa Singleton)
    const CONFIG = {
        headerSelector: '.header',
        userBlockId: 'headerUserBlock'
    };

    let inicializado = false;

    /**
     * Inicializar o componente
     */
    function inicializar() {
        if (inicializado) return; // Evitar múltiplas inicializações
        inicializado = true;

        console.log('🔧 Header User Profile inicializado');

        // Adicionar estilos CSS
        adicionarEstilos();

        // Criar estrutura do bloco de usuário
        criarBlocoUsuario();

        // Obter SessionManager Singleton
        const sessionMgr = SessionManagerCore.getInstance();

        // Carregar dados iniciais
        const usuarioAtual = sessionMgr.getUserData();
        if (usuarioAtual) {
            atualizarBlocoUsuario(usuarioAtual);
        }

        // Escutar mudanças de dados do usuário (em vez de polling a cada 1s)
        sessionMgr.onUserDataChanged((dados) => {
            if (dados.usuario) {
                atualizarBlocoUsuario(dados.usuario);
            }
        });

        // Listeners
        document.addEventListener('visibilitychange', handleVisibilidadeChange);
        window.addEventListener('beforeunload', limpar);

        console.log('✅ Header User Profile pronto (Gerenciador Centralizado)');
    }

    /**
     * Criar bloco de identidade do usuário no cabeçalho
     */
    function criarBlocoUsuario() {
        const header = document.querySelector(CONFIG.headerSelector);

        if (!header) {
            console.warn('Cabeçalho não encontrado');
            return;
        }

        // Verificar se já existe
        if (document.getElementById(CONFIG.userBlockId)) {
            return;
        }

        // Criar HTML do bloco de usuário
        const html = `
            <div class="header-user-block" id="${CONFIG.userBlockId}">
                <div class="user-avatar-header" id="userAvatarHeader">-</div>
                <div class="user-details">
                    <div class="user-name-header" id="userNameHeader">Carregando...</div>
                    <div class="user-function-header">
                        <span id="userFunctionHeader">-</span>
                        <span class="status-indicator">
                            <i class="fas fa-circle"></i> Ativo
                        </span>
                    </div>
                </div>
            </div>
        `;

        // Inserir no lado esquerdo do header (antes do h1 ou no início)
        const h1 = header.querySelector('h1');
        if (h1) {
            h1.insertAdjacentHTML('beforebegin', html);
        } else {
            header.insertAdjacentHTML('afterbegin', html);
        }
    }

    /**
     * Carregar dados do usuário logado (DEPRECADO)
     */
    function carregarDadosUsuario() {
        // NOTA: Esta função não é mais chamada em loop.
        // SessionManager Singleton centraliza requisições.
        const sessionMgr = SessionManagerCore.getInstance();
        return sessionMgr.refreshUserData();
    }

    /**
     * Atualizar exibição do perfil no cabeçalho
     * Alias para atualizarBlocoUsuario
     */
    function atualizarExibicao(usuario) {
        atualizarBlocoUsuario(usuario);
    }

    /**
     * Atualizar bloco de usuário com dados do Singleton
     */
    function atualizarBlocoUsuario(usuario) {
        if (!usuario) {
            // Exibir placeholders quando usuário não disponível
            const userAvatar = document.getElementById('userAvatarHeader');
            const userName = document.getElementById('userNameHeader');
            const userFunction = document.getElementById('userFunctionHeader');
            if (userAvatar) userAvatar.textContent = '-';
            if (userName) userName.textContent = 'Convidado';
            if (userFunction) userFunction.textContent = '-';
            return;
        }

        // Extrair inicial do nome com segurança
        const nome = usuario.nome || '';
        const inicial = nome.length ? nome.charAt(0).toUpperCase() : '-';

        // Atualizar avatar
        const userAvatar = document.getElementById('userAvatarHeader');
        if (userAvatar) {
            userAvatar.textContent = inicial;
        }

        // Atualizar nome em CAPS LOCK
        const userName = document.getElementById('userNameHeader');
        if (userName) {
            userName.textContent = (nome || 'Usuário').toString().toUpperCase();
        }

        // Atualizar função
        const userFunction = document.getElementById('userFunctionHeader');
        if (userFunction) {
            const funcao = usuario.funcao || usuario.permissao || 'USUÁRIO';
            userFunction.textContent = funcao.toUpperCase();
        }
    }

    /**
     * Lidar com mudança de visibilidade
     */
    function handleVisibilidadeChange() {
        if (document.hidden) {
            if (intervaloAtualizacao) clearInterval(intervaloAtualizacao);
        } else {
            carregarDadosUsuario();
            intervaloAtualizacao = setInterval(carregarDadosUsuario, CONFIG.updateInterval);
        }
    }

    /**
     * Adicionar estilos CSS
     */
    function adicionarEstilos() {
        if (document.getElementById('header-user-profile-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'header-user-profile-styles';
        style.textContent = `
            /* Header User Block - Lado Esquerdo */
            .header-user-block {
                display: flex;
                align-items: center;
                gap: 15px;
                color: white;
                order: -1;
            }
            
            /* Avatar Circular Azul */
            .user-avatar-header {
                width: 50px;
                height: 50px;
                background: #2563eb;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 1.5rem;
                font-weight: bold;
                flex-shrink: 0;
                border: 2px solid rgba(255, 255, 255, 0.2);
                box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
            }
            
            /* Detalhes do Usuário */
            .user-details {
                display: flex;
                flex-direction: column;
                line-height: 1.2;
                min-width: 0;
            }
            
            /* Nome em CAPS LOCK */
            .user-name-header {
                font-weight: 700;
                font-size: 0.95rem;
                color: #ffffff;
                text-transform: uppercase;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                letter-spacing: 0.5px;
            }
            
            /* Função e Status */
            .user-function-header {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 0.75rem;
                color: #cbd5e1;
                opacity: 0.85;
            }
            
            /* Indicador de Status */
            .status-indicator {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                background: rgba(16, 185, 129, 0.15);
                padding: 2px 8px;
                border-radius: 12px;
                color: #10b981;
                font-weight: 600;
                white-space: nowrap;
            }
            
            .status-indicator i {
                font-size: 0.5rem;
            }
            
            /* Responsivo */
            @media (max-width: 1024px) {
                .header-user-block {
                    gap: 12px;
                }
                
                .user-avatar-header {
                    width: 45px;
                    height: 45px;
                    font-size: 1.3rem;
                }
                
                .user-name-header {
                    font-size: 0.9rem;
                }
                
                .user-function-header {
                    font-size: 0.7rem;
                }
            }
            
            @media (max-width: 768px) {
                .header-user-block {
                    gap: 10px;
                }
                
                .user-avatar-header {
                    width: 40px;
                    height: 40px;
                    font-size: 1.1rem;
                }
                
                .user-name-header {
                    font-size: 0.85rem;
                }
                
                .user-function-header {
                    font-size: 0.65rem;
                    gap: 4px;
                }
                
                .status-indicator {
                    padding: 1px 6px;
                    font-size: 0.6rem;
                }
            }
        `;

        document.head.appendChild(style);
    }

    /**
     * Limpar recursos
     */
    function limpar() {
        if (intervaloAtualizacao) {
            clearInterval(intervaloAtualizacao);
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

})();
