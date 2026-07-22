/**
 * =====================================================
 * USER PROFILE SIDEBAR - Componente Reutilizável
 * =====================================================
 * Script para exibir perfil do usuário logado em todas as páginas
 * Mostra: Nome, Função, Tempo de Sessão e Status
 * Sem email
 */

(function() {
    'use strict';
    
    // Configurações
    const CONFIG = {
        apiUrl: '../api/api_usuario_logado.php',
        updateInterval: 1000,
        warningThreshold: 300,
        autoRenewThreshold: 600,
        enableAutoRenew: true
    };
    
    let intervaloAtualizacao = null;
    
    /**
     * Inicializar o script
     */
    function inicializar() {
        console.log('🔧 User Profile Sidebar inicializado');
        
        // Criar ou atualizar seção de perfil
        criarOuAtualizarPerfil();
        
        // Carregar dados iniciais
        carregarDadosUsuario();
        
        // Iniciar atualização periódica
        intervaloAtualizacao = setInterval(carregarDadosUsuario, CONFIG.updateInterval);
        
        // Listeners
        document.addEventListener('visibilitychange', handleVisibilidadeChange);
        window.addEventListener('beforeunload', limpar);
        
        console.log('✅ User Profile Sidebar pronto');
    }
    
    /**
     * Criar ou atualizar seção de perfil
     */
    function criarOuAtualizarPerfil() {
        const sidebar = document.querySelector('.sidebar') || 
                       document.querySelector('[data-sidebar]') ||
                       document.querySelector('.nav-menu')?.parentElement;
        
        if (!sidebar) {
            console.warn('Sidebar não encontrado');
            return;
        }
        
        // Verificar se já existe
        let perfilSection = document.getElementById('userProfileSection');
        
        if (!perfilSection) {
            // Criar seção
            const html = `
                <div class="user-profile-section" id="userProfileSection">
                    <div class="user-profile-header">
                        <div class="user-avatar" id="userAvatar">-</div>
                        <div class="user-info">
                            <p class="user-name" id="userName">Carregando...</p>
                            <p class="user-function" id="userFunction">-</p>
                        </div>
                    </div>
                    <div class="session-info">
                        <div class="session-item">
                            <div class="session-label">Tempo</div>
                            <div class="session-value session-timer" id="sessionTimer">00:00:00</div>
                        </div>
                        <div class="session-item">
                            <div class="session-label">Status</div>
                            <div class="session-value" id="sessionStatus"><i class="fas fa-circle" style="color: #10b981; font-size: 0.6rem;"></i> Ativo</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Inserir após h1 ou no início
            const h1 = sidebar.querySelector('h1');
            if (h1) {
                h1.insertAdjacentHTML('afterend', html);
            } else {
                sidebar.insertAdjacentHTML('afterbegin', html);
            }
            
            // Adicionar estilos
            adicionarEstilos();
        }
    }
    
    /**
     * Carregar dados do usuário logado
     */
    function carregarDadosUsuario() {
        fetch(CONFIG.apiUrl, {
            method: 'GET',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.sucesso && data.logado) {
                atualizarExibicao(data);
                verificarAvisos(data.sessao.tempo_restante);
                
                if (CONFIG.enableAutoRenew && data.sessao.tempo_restante < CONFIG.autoRenewThreshold) {
                    renovarSessaoAutomaticamente();
                }
            } else {
                handleSessaoExpirada();
            }
        })
        .catch(error => console.warn('Erro ao carregar dados da sessão:', error));
    }
    
    /**
     * Atualizar exibição
     */
    function atualizarExibicao(data) {
        atualizarPerfilUsuario(data.usuario);
        atualizarTempoSessao(data.sessao);
        atualizarStatusVisual(data.sessao.tempo_restante);
    }
    
    /**
     * Atualizar perfil do usuário
     */
    function atualizarPerfilUsuario(usuario) {
        const inicial = usuario.nome.charAt(0).toUpperCase();
        const userAvatar = document.getElementById('userAvatar');
        const userName = document.getElementById('userName');
        const userFunction = document.getElementById('userFunction');
        
        if (userAvatar) userAvatar.textContent = inicial;
        if (userName) userName.textContent = usuario.nome;
        if (userFunction) userFunction.textContent = usuario.funcao || usuario.permissao || 'Usuário';
    }
    
    /**
     * Atualizar tempo de sessão
     */
    function atualizarTempoSessao(sessao) {
        const tempoElement = document.getElementById('sessionTimer');
        if (!tempoElement) return;
        
        const tempoRestante = sessao.tempo_restante_formatado || formatarTempo(sessao.tempo_restante);
        tempoElement.textContent = tempoRestante;
        
        // Atualizar classe de cor
        tempoElement.classList.remove('warning', 'critical');
        if (sessao.tempo_restante < CONFIG.warningThreshold) {
            tempoElement.classList.add('critical');
        } else if (sessao.tempo_restante < 1800) {
            tempoElement.classList.add('warning');
        }
    }
    
    /**
     * Atualizar status visual
     */
    function atualizarStatusVisual(tempoRestante) {
        const section = document.getElementById('userProfileSection');
        if (!section) return;
        
        section.style.borderColor = 'rgba(255, 255, 255, 0.2)';
        section.style.background = 'rgba(255, 255, 255, 0.1)';
        
        if (tempoRestante < CONFIG.warningThreshold) {
            section.style.borderColor = 'rgba(239, 68, 68, 0.3)';
            section.style.background = 'rgba(239, 68, 68, 0.1)';
        } else if (tempoRestante < 1800) {
            section.style.borderColor = 'rgba(249, 115, 22, 0.3)';
            section.style.background = 'rgba(249, 115, 22, 0.1)';
        }
    }
    
    /**
     * Verificar avisos
     */
    function verificarAvisos(tempoRestante) {
        if (tempoRestante < CONFIG.warningThreshold && tempoRestante > CONFIG.warningThreshold - 60) {
            mostrarNotificacao('warning', 'Sua sessão expirará em 5 minutos');
        }
        
        if (tempoRestante < 60 && tempoRestante > 50) {
            mostrarNotificacao('critical', 'Sua sessão expirará em menos de 1 minuto!');
        }
    }
    
    /**
     * Mostrar notificação
     */
    function mostrarNotificacao(tipo, mensagem) {
        if (document.querySelector(`[data-notif-sessao="${tipo}"]`)) {
            return;
        }
        
        const cor = tipo === 'critical' ? '#ef4444' : '#f97316';
        const notifHTML = `
            <div class="session-notification session-notification-${tipo}" data-notif-sessao="${tipo}" style="
                position: fixed;
                top: 20px;
                right: 20px;
                background: #fff;
                padding: 1rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                z-index: 9999;
                border-left: 4px solid ${cor};
                animation: slideIn 0.3s ease;
            ">
                <i class="fas fa-exclamation-triangle" style="color: ${cor}; margin-right: 0.5rem;"></i>
                <span style="color: #1f2937;">${mensagem}</span>
            </div>
        `;
        
        document.body.insertAdjacentHTML('afterbegin', notifHTML);
        
        setTimeout(() => {
            const notif = document.querySelector(`[data-notif-sessao="${tipo}"]`);
            if (notif) notif.remove();
        }, 8000);
    }
    
    /**
     * Renovar sessão automaticamente
     */
    function renovarSessaoAutomaticamente() {
        fetch(CONFIG.apiUrl + '?acao=renovar', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.sucesso) {
                console.log('✅ Sessão renovada automaticamente');
                carregarDadosUsuario();
            }
        })
        .catch(error => console.warn('Erro ao renovar sessão:', error));
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
     * Lidar com sessão expirada
     */
    function handleSessaoExpirada() {
        console.warn('⚠️ Sessão expirada ou inválida');
    }
    
    /**
     * Formatar tempo
     */
    function formatarTempo(segundos) {
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        const segs = segundos % 60;
        
        return `${String(horas).padStart(2, '0')}:${String(minutos).padStart(2, '0')}:${String(segs).padStart(2, '0')}`;
    }
    
    /**
     * Adicionar estilos CSS
     */
    function adicionarEstilos() {
        if (document.getElementById('user-profile-sidebar-styles')) {
            return;
        }
        
        const style = document.createElement('style');
        style.id = 'user-profile-sidebar-styles';
        style.textContent = `
            /* User Profile Section */
            .user-profile-section {
                background: rgba(255, 255, 255, 0.1);
                border-radius: 12px;
                padding: 1.5rem;
                margin: 0 1rem 1.5rem 1rem;
                border: 1px solid rgba(255, 255, 255, 0.2);
                transition: all 0.3s ease;
            }
            
            .user-profile-header {
                display: flex;
                align-items: center;
                gap: 1rem;
                margin-bottom: 1rem;
            }
            
            .user-avatar {
                width: 50px;
                height: 50px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 1.5rem;
                font-weight: bold;
                flex-shrink: 0;
            }
            
            .user-info {
                flex: 1;
                min-width: 0;
            }
            
            .user-name {
                color: #fff;
                font-size: 1rem;
                font-weight: 600;
                margin: 0;
                word-wrap: break-word;
            }
            
            .user-function {
                color: #cbd5e1;
                font-size: 0.85rem;
                margin: 0.25rem 0 0 0;
            }
            
            .session-info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
                margin-top: 1rem;
                padding-top: 1rem;
                border-top: 1px solid rgba(255, 255, 255, 0.2);
            }
            
            .session-item { }
            
            .session-label {
                color: #94a3b8;
                font-size: 0.75rem;
                margin-bottom: 0.25rem;
            }
            
            .session-value {
                color: #fff;
                font-size: 0.9rem;
                font-weight: 600;
            }
            
            .session-timer {
                color: #10b981;
                font-weight: 700;
            }
            
            .session-timer.warning {
                color: #f97316;
            }
            
            .session-timer.critical {
                color: #ef4444;
            }
            
            /* Responsive */
            @media (max-width: 768px) {
                .user-profile-section {
                    padding: 1rem;
                    margin: 0 0.5rem 1rem 0.5rem;
                }
                
                .user-avatar {
                    width: 45px;
                    height: 45px;
                    font-size: 1.3rem;
                }
                
                .user-name {
                    font-size: 0.95rem;
                }
                
                .user-function {
                    font-size: 0.8rem;
                }
                
                .session-info {
                    gap: 0.75rem;
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
