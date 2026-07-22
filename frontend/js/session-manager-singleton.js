/**
 * =====================================================
 * SESSION MANAGER SINGLETON - Versão 6.0 (Definitiva)
 * =====================================================
 * 
 * Gerenciador centralizado de sessão e dados de usuário.
 * - UM ÚNICO gerenciador para toda a aplicação
 * - Impede requisições concorrentes com flag isFetching
 * - Intervals seguros (≥60s) para verificação
 * - Sincronização de dados entre componentes via eventos
 * - Sem race conditions ou logs infinitos
 * 
 * Uso:
 *   const manager = SessionManagerSingleton.getInstance();
 *   manager.onUserDataChanged(callback);
 *   manager.refreshUserData(); // Dispara atualização manual
 */

class SessionManagerSingleton {
    static instance = null;

    constructor() {
        this.apiBase = '../api/';
        this.verificacaoInterval = 60000; // 60s — intervalo SEGURO
        this.renovacaoInterval = 300000; // 5min
        this.isFetching = false; // Flag para evitar requisições sobrepostas
        this.timeoutVerificacao = null;
        this.timeoutRenovacao = null;
        this.sessaoAtiva = false;
        this.usuarioAtual = null;
        this.listeners = {
            userDataChanged: [],
            sessionExpired: [],
            error: []
        };
        this.tipoUsuario = this.detectarTipoUsuario();
    }

    /**
     * Obter instância singleton
     */
    static getInstance() {
        if (!SessionManagerSingleton.instance) {
            SessionManagerSingleton.instance = new SessionManagerSingleton();
        }
        return SessionManagerSingleton.instance;
    }

    detectarTipoUsuario() {
        const caminhoAtual = window.location.pathname;
        if (caminhoAtual.includes('painel_fornecedor') || caminhoAtual.includes('login_fornecedor')) {
            return 'fornecedor';
        }
        return 'comum';
    }

    obterUrlLogin() {
        if (this.tipoUsuario === 'fornecedor') return 'login_fornecedor.html';
        return 'login.html';
    }

    /**
     * Iniciar o gerenciador
     */
    iniciar() {
        console.log('[SessionManager] Iniciando gerenciador centralizado...');
        
        if (!window.location.pathname.includes('login')) {
            // Verificação inicial
            this.verificarSessao();
            
            // Agendamento seguro de verificações periódicas
            this.timeoutVerificacao = setInterval(() => {
                this.verificarSessao();
            }, this.verificacaoInterval);
            
            this.timeoutRenovacao = setInterval(() => {
                this.renovarSessao();
            }, this.renovacaoInterval);
            
            // Renovação por atividade do usuário
            this.configurarRenovacaoPorAtividade();
            
            console.log('[SessionManager] Gerenciador iniciado com segurança ✅');
        }
    }

    /**
     * Parar o gerenciador
     */
    parar() {
        if (this.timeoutVerificacao) clearInterval(this.timeoutVerificacao);
        if (this.timeoutRenovacao) clearInterval(this.timeoutRenovacao);
        console.log('[SessionManager] Gerenciador parado');
    }

    /**
     * Verificar sessão com proteção contra requisições simultâneas
     */
    async verificarSessao() {
        // Evitar requisições sobrepostas
        if (this.isFetching) {
            console.log('[SessionManager] Requisição anterior ainda pendente, pulando...');
            return false;
        }

        this.isFetching = true;

        try {
            const response = await fetch(this.apiBase + 'verificar_sessao_completa.php', {
                method: 'GET',
                credentials: 'include',
                timeout: 10000 // timeout de 10s para evitar travamentos
            });

            if (!response.ok) {
                this.sessaoAtiva = false;
                this.isFetching = false;
                return false;
            }

            const data = await response.json();

            if (data.sucesso && data.sessao_ativa) {
                this.sessaoAtiva = true;
                this.usuarioAtual = data.usuario || null;
                
                // Notificar listeners que dados mudaram
                // enviar o objeto original para que o notificador normalize
                this.notificar('userDataChanged', data);
                
                this.isFetching = false;
                return true;
            } else {
                this.sessaoAtiva = false;
                
                // Só redireciona se não for tela de login
                if (!window.location.pathname.includes('login.html')) {
                    this.notificar('sessionExpired', {});
                    this.redirecionarParaLogin();
                }
                
                this.isFetching = false;
                return false;
            }
        } catch (error) {
            console.error('[SessionManager] Erro na verificação:', error);
            this.sessaoAtiva = false;
            this.notificar('error', { message: error.message });
            this.isFetching = false;
            return false;
        }
    }

    /**
     * Renovar sessão (se ativa)
     */
    async renovarSessao() {
        if (!this.sessaoAtiva || this.isFetching) return false;

        try {
            const formData = new FormData();
            formData.append('acao', 'renovar');
            const response = await fetch(this.apiBase + 'verificar_sessao_completa.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            return response.ok;
        } catch (error) {
            console.error('[SessionManager] Erro na renovação:', error);
            return false;
        }
    }

    /**
     * Renovar sessão por atividade do usuário
     */
    configurarRenovacaoPorAtividade() {
        let ultimaAtividade = Date.now();
        const eventos = ['mousedown', 'keydown', 'scroll', 'touchstart'];
        
        const atualizarAtividade = () => {
            const agora = Date.now();
            // Renovar apenas se passaram 30min desde última atividade
            if (agora - ultimaAtividade > 1800000) {
                this.renovarSessao();
            }
            ultimaAtividade = agora;
        };
        
        eventos.forEach(evento => 
            document.addEventListener(evento, atualizarAtividade, { passive: true })
        );
    }

    /**
     * Solicitar atualização de dados do usuário (chamada manual)
     */
    async refreshUserData() {
        return this.verificarSessao();
    }

    /**
     * Obter dados do usuário atual
     */
    getUserData() {
        return this.usuarioAtual;
    }

    /**
     * Verificar se sessão está ativa
     */
    isSessionActive() {
        return this.sessaoAtiva;
    }

    /**
     * Registrar listener para mudanças
     */
    onUserDataChanged(callback) {
        if (typeof callback === 'function') {
            this.listeners.userDataChanged.push(callback);
        }
    }

    onSessionExpired(callback) {
        if (typeof callback === 'function') {
            this.listeners.sessionExpired.push(callback);
        }
    }

    onError(callback) {
        if (typeof callback === 'function') {
            this.listeners.error.push(callback);
        }
    }

    /**
     * Disparar listeners
     */
    notificar(tipo, dados) {
        // Normalizar payload para evitar TypeErrors nas callbacks
        const normalized = {
            logado: Boolean(dados && (typeof dados.logado !== 'undefined' ? dados.logado : (typeof (dados && dados.sucesso) !== 'undefined' ? dados.sucesso : this.sessaoAtiva))),
            usuario: (dados && (dados.usuario ?? dados.usuario_logado ?? null)) ?? this.usuarioAtual ?? null,
            tempo_restante: (dados && (typeof dados.tempo_restante !== 'undefined' ? dados.tempo_restante : (dados.sessao && typeof dados.sessao.tempo_restante !== 'undefined' ? dados.sessao.tempo_restante : null))) ?? null,
            sessao: (dados && dados.sessao) ?? null,
            raw: dados ?? null
        };

        if (this.listeners[tipo]) {
            this.listeners[tipo].forEach(callback => {
                try {
                    callback(normalized);
                } catch (err) {
                    console.warn(`[SessionManager] Erro em listener ${tipo}:`, err);
                }
            });
        }
    }

    /**
     * Redirecionar para login
     */
    redirecionarParaLogin() {
        this.parar();
        window.location.href = this.obterUrlLogin();
    }

    /**
     * FLUXO DE LOGOUT SEGURO
     */
    async finalizarSessao() {
        console.log('[SessionManager] Iniciando logout seguro...');

        try {
            // 1. Chamar API de logout
            await fetch(this.apiBase + 'logout.php', { 
                method: 'POST', 
                credentials: 'include' 
            }).catch(() => {});

            // 2. Limpeza de Cookies
            document.cookie = "token_acesso=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            document.cookie = "PHPSESSID=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

            // 3. Limpeza de Storages
            localStorage.clear();
            sessionStorage.clear();

            // 4. Parar o gerenciador
            this.parar();

            console.log('[SessionManager] Logout completo ✅');

            // 5. Redirecionar
            window.location.href = this.obterUrlLogin();
        } catch (error) {
            console.error('[SessionManager] Erro no logout:', error);
            localStorage.clear();
            sessionStorage.clear();
            window.location.href = 'login.html';
        }
    }
}

/**
 * Inicializar Singleton no DOMContentLoaded
 */
document.addEventListener('DOMContentLoaded', () => {
    const manager = SessionManagerSingleton.getInstance();
    
    // Exposição global para uso em outros scripts
    window.sessionManagerSingleton = manager;
    
    manager.iniciar();
});

// Compatibilidade com código antigo que usa window.sessaoManager
document.addEventListener('DOMContentLoaded', () => {
    window.sessaoManager = SessionManagerSingleton.getInstance();
});
