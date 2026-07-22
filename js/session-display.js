/**
 * =====================================================
 * SESSION DISPLAY - Exibir Usuário Logado e Tempo de Sessão
 * =====================================================
 * Script para exibir informações do usuário logado e
 * tempo de sessão em tempo real no menu lateral
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
        console.log('🔧 Session Display inicializado');
        
        carregarDadosUsuario();
        intervaloAtualizacao = setInterval(carregarDadosUsuario, CONFIG.updateInterval);
        
        document.addEventListener('visibilitychange', handleVisibilidadeChange);
        window.addEventListener('beforeunload', limpar);
        
        console.log('✅ Session Display pronto');
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
     * Atualizar exibição na interface
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
        let perfilElement = document.querySelector('.user-profile');
        
        if (!perfilElement) {
            criarPerfilUsuario(usuario);
            return;
        }
        
        const nomeElement = perfilElement.querySelector('.user-name');
        if (nomeElement) {
            nomeElement.textContent = truncarTexto(usuario.nome, 20);
            nomeElement.title = usuario.nome;
        }
    }
    
    /**
     * Criar elemento de perfil
     */
    function criarPerfilUsuario(usuario) {
        const sidebar = document.querySelector('.sidebar') || 
                       document.querySelector('[data-sidebar]') ||
                       document.querySelector('.nav-menu');
        
        if (!sidebar || sidebar.querySelector('.user-profile')) {
            return;
        }
        
        const inicial = usuario.nome.charAt(0).toUpperCase();
        const roles = {
            'admin': 'Administrador',
            'gerente': 'Gerente',
            'operador': 'Operador',
            'visualizador': 'Visualizador'
        };
        
        const perfilHTML = `
            <div class="user-profile" data-user-profile>
                <div class="user-avatar">${inicial}</div>
                <div class="user-info">
                    <div class="user-name" title="${usuario.nome}">${truncarTexto(usuario.nome, 20)}</div>
                    <div class="user-funcao">${usuario.funcao || roles[usuario.permissao] || 'Usuário'}</div>
                    <div class="user-session-timer">
                        <i class="fas fa-hourglass-end"></i>
                        <span data-tempo-sessao>00:00:00</span>
                    </div>
                </div>
            </div>
        `;
        
        sidebar.insertAdjacentHTML('afterbegin', perfilHTML);
        adicionarEstilos();
    }
    
    /**
     * Atualizar tempo de sessão
     */
    function atualizarTempoSessao(sessao) {
        let tempoElement = document.querySelector('[data-tempo-sessao]');
        
        if (!tempoElement) {
            return;
        }
        
        const tempoRestante = sessao.tempo_restante_formatado || formatarTempo(sessao.tempo_restante);
        tempoElement.textContent = tempoRestante;
        
        if (sessao.tempo_restante < CONFIG.warningThreshold) {
            tempoElement.style.color = '#ef4444';
        } else if (sessao.tempo_restante < 1800) {
            tempoElement.style.color = '#f97316';
        } else {
            tempoElement.style.color = '#10b981';
        }
    }
    
    /**
     * Atualizar status visual
     */
    function atualizarStatusVisual(tempoRestante) {
        const perfilElement = document.querySelector('.user-profile');
        
        if (!perfilElement) {
            return;
        }
        
        perfilElement.classList.remove('session-warning', 'session-critical');
        
        if (tempoRestante < CONFIG.warningThreshold) {
            perfilElement.classList.add('session-critical');
        } else if (tempoRestante < 1800) {
            perfilElement.classList.add('session-warning');
        }
    }
    
    /**
     * Verificar avisos
     */
    function verificarAvisos(tempoRestante) {
        if (tempoRestante < CONFIG.warningThreshold && tempoRestante > CONFIG.warningThreshold - 60) {
            mostrarAviso('warning', 'Sua sessão expirará em 5 minutos. Clique para renovar.');
        }
        
        if (tempoRestante < 60 && tempoRestante > 50) {
            mostrarAviso('critical', 'Sua sessão expirará em menos de 1 minuto!');
        }
    }
    
    /**
     * Mostrar aviso
     */
    function mostrarAviso(tipo, mensagem) {
        if (document.querySelector(`[data-aviso-sessao="${tipo}"]`)) {
            return;
        }
        
        const avisoHTML = `
            <div class="session-aviso session-aviso-${tipo}" data-aviso-sessao="${tipo}">
                <i class="fas fa-exclamation-triangle"></i>
                <span>${mensagem}</span>
                <button onclick="renovarSessaoManual()" class="btn-renovar">
                    <i class="fas fa-sync-alt"></i> Renovar
                </button>
                <button onclick="this.parentElement.remove()" class="btn-fechar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.querySelector('body').insertAdjacentHTML('afterbegin', avisoHTML);
        
        setTimeout(() => {
            const aviso = document.querySelector(`[data-aviso-sessao="${tipo}"]`);
            if (aviso) aviso.remove();
        }, 10000);
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
     * Renovar sessão manualmente
     */
    window.renovarSessaoManual = function() {
        renovarSessaoAutomaticamente();
        document.querySelectorAll('[data-aviso-sessao]').forEach(el => el.remove());
    };
    
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
        
        const avisoHTML = `
            <div class="session-aviso session-aviso-expired">
                <i class="fas fa-lock"></i>
                <span>Sua sessão expirou. Faça login novamente.</span>
                <button onclick="window.location.href='login.html'" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Fazer Login
                </button>
            </div>
        `;
        
        document.querySelector('body').insertAdjacentHTML('afterbegin', avisoHTML);
        
        setTimeout(() => {
            window.location.href = 'login.html';
        }, 5000);
    }
    
    /**
     * Adicionar estilos CSS
     */
    function adicionarEstilos() {
        if (document.getElementById('session-display-styles')) {
            return;
        }
        
        const style = document.createElement('style');
        style.id = 'session-display-styles';
        style.textContent = `
            .user-profile {
                padding: 1rem;
                margin: 0 1rem 1rem 1rem;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 10px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                transition: all 0.3s ease;
            }
            
            .user-profile.session-warning {
                background: rgba(249, 115, 22, 0.1);
                border-color: rgba(249, 115, 22, 0.3);
            }
            
            .user-profile.session-critical {
                background: rgba(239, 68, 68, 0.1);
                border-color: rgba(239, 68, 68, 0.3);
                animation: pulse 1s infinite;
            }
            
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.7; }
            }
            
            .user-avatar {
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
            
            .user-info {
                text-align: center;
            }
            
            .user-name {
                color: #fff;
                font-size: 0.95rem;
                font-weight: 600;
                margin-bottom: 0.25rem;
            }
            
            .user-funcao {
                color: #94a3b8;
                font-size: 0.8rem;
                margin-bottom: 0.5rem;
            }
            
            .user-session-timer {
                margin-top: 0.5rem;
                padding: 0.5rem;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 5px;
                font-size: 0.9rem;
                color: #10b981;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                font-weight: 600;
            }
            
            .session-aviso {
                position: fixed;
                top: 20px;
                right: 20px;
                background: #fff;
                border-radius: 8px;
                padding: 1rem;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                gap: 1rem;
                z-index: 9999;
                max-width: 400px;
                animation: slideIn 0.3s ease;
            }
            
            @keyframes slideIn {
                from { transform: translateX(450px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            .session-aviso-warning { border-left: 4px solid #f97316; }
            .session-aviso-warning i { color: #f97316; }
            .session-aviso-critical { border-left: 4px solid #ef4444; }
            .session-aviso-critical i { color: #ef4444; }
            .session-aviso-expired { border-left: 4px solid #8b5cf6; }
            .session-aviso-expired i { color: #8b5cf6; }
            
            .session-aviso span {
                flex: 1;
                color: #1f2937;
                font-size: 0.9rem;
            }
            
            .session-aviso button {
                padding: 0.5rem 1rem;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 0.85rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                transition: all 0.2s ease;
            }
            
            .btn-renovar {
                background: #10b981;
                color: white;
            }
            
            .btn-renovar:hover {
                background: #059669;
            }
            
            .btn-fechar {
                background: #e5e7eb;
                color: #6b7280;
                padding: 0.5rem 0.75rem;
            }
            
            .btn-fechar:hover {
                background: #d1d5db;
            }
            
            .btn-login {
                background: #3b82f6;
                color: white;
            }
            
            .btn-login:hover {
                background: #2563eb;
            }
            
            @media (max-width: 640px) {
                .session-aviso {
                    right: 10px;
                    left: 10px;
                    max-width: none;
                }
            }
        `;
        
        document.head.appendChild(style);
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
     * Truncar texto
     */
    function truncarTexto(texto, limite) {
        return texto.length > limite ? texto.substring(0, limite) + '...' : texto;
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
     * Inicializar
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }
    
})();
