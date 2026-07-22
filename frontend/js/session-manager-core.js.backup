/**
 * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
 * SESSION MANAGER CORE v2.0 - ÃšNICO PONTO DE CONTROLE DE SESSÃƒO
 * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
 * 
 * âœ… PRINCÃPIOS:
 *   â€¢ SessÃ£o â‰  UI (UI nunca valida ou renova sessÃ£o)
 *   â€¢ Apenas um fetch por requisiÃ§Ã£o de sessÃ£o
 *   â€¢ Estado centralizado e compartilhado
 *   â€¢ Listeners sÃ£o consumidores PASSIVOS
 *   â€¢ Logout centralizado (nÃ£o espalhado em 24 pÃ¡ginas)
 *
 * âœ… RESPONSABILIDADES:
 *   â€¢ verificarSessao() â†’ 1x por startup + 1x/60s
 *   â€¢ renovarSessao() â†’ 1x/5min (automÃ¡tico)
 *   â€¢ logout() â†’ centralizado
 *   â€¢ emitir eventos â†’ para UI reagir
 *
 * âŒ NÃƒO FAZ:
 *   â€¢ Renderizar HTML
 *   â€¢ Fazer lÃ³gica de negÃ³cio
 *   â€¢ Ser chamado de mÃºltiplos pontos (singleton!)
 *   â€¢ Permitir duplicaÃ§Ã£o
 *
 * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
 */

class SessionManagerCore {
    static instance = null;
    static locked = false;

    constructor() {
        // Garantir singleton (rejeitar tentativas de criar novo)
        if (SessionManagerCore.instance) {
            throw new Error(
                '[SessionManager] âŒ SessionManagerCore jÃ¡ foi instanciado! Use getInstance() em vez de new.'
            );
        }

        // â•â•â• CONSTANTES â•â•â•
        this.API_BASE = '../api/';
        this.CHECK_INTERVAL = 60000;      // 60s (verificaÃ§Ã£o segura)
        this.RENEW_INTERVAL = 300000;     // 5min (renovaÃ§Ã£o automÃ¡tica)
        this.TIMEOUT = 15000;             // 15s timeout (mais robusto)
        this.MAX_RETRIES = 1;             // Sem retry (sÃ³ 1 tentativa)

        // â•â•â• ESTADO â•â•â•
        this.isAuthenticated = false;
        this.currentUser = null;
        this.sessionExpireTime = null;
        this.isFetching = false;          // Guard contra requests simultÃ¢neos
        this.isInitialized = false;        // Se ja fez primeira verificaÃ§Ã£o
        this.lastError = null;            // Ãšltimo erro que ocorreu
        this.lastSuccessfulCheck = null;  // Timestamp (ms) do Ãºltimo check bem-sucedido
        this.isOnline = navigator.onLine; // Flag de conectividade de rede

        // â•â•â• TIMERS â•â•â•
        this.checkTimer = null;
        this.renewTimer = null;

        // â•â•â• EVENT SYSTEM â•â•â•
        this.listeners = new Map();
        this.listeners.set('userDataChanged', []);
        this.listeners.set('sessionExpired', []);
        this.listeners.set('error', []);
        this.listeners.set('sessionRenewed', []);

        // â•â•â• PERSISTÃŠNCIA â•â•â•
        this.storageKey = 'sessionManagerState_v2';

        // Marcar como singleton
        SessionManagerCore.instance = this;
        SessionManagerCore.locked = true;

        console.log('[SessionManager] âœ… SessionManagerCore inicializado (singleton)');
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * PUBLIC API: getInstance
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    static getInstance() {
        if (!SessionManagerCore.instance) {
            SessionManagerCore.instance = new SessionManagerCore();
        }
        return SessionManagerCore.instance;
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * INICIALIZAÃ‡ÃƒO
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    async initialize() {
        if (this.isInitialized) {
            console.log('[SessionManager] â„¹ï¸ JÃ¡ inicializado, pulando...');
            return;
        }

        console.log('[SessionManager] Inicializando...');

        // â•â•â• MONITORAR CONECTIVIDADE DE REDE
        window.addEventListener('online', () => {
            console.log('[SessionManager] ðŸ“¡ ConexÃ£o de rede restaurada');
            this.isOnline = true;
            this.checkSession(); // Tentar reconectar
        });

        window.addEventListener('offline', () => {
            console.log('[SessionManager] ðŸ”Œ ConexÃ£o de rede perdida');
            this.isOnline = false;
        });

        // Tentar recuperar estado anterior (localStorage)
        const persisted = this.loadPersistedState();
        if (persisted && persisted.isAuthenticated) {
            console.log('[SessionManager] Restaurando estado do localStorage');
            // NÃƒO restaurar isAuthenticated (seguranÃ§a exigida)
            // this.isAuthenticated = persisted.isAuthenticated;
            // this.currentUser = persisted.currentUser;
            // this.sessionExpireTime = persisted.sessionExpireTime;
        }

        // Primeira verificaÃ§Ã£o (OBRIGATÃ“RIA)
        const checkOk = await this.checkSession();

        if (!checkOk && !this.isPublicPage()) {
            console.warn('[SessionManager] âš ï¸ VerificaÃ§Ã£o inicial falhou, redirecionando');
            this.redirectToLogin();
            return;
        }

        // Iniciar timers de verificaÃ§Ã£o periÃ³dica (se pÃ¡gina protegida)
        if (!this.isPublicPage()) {
            this.startPeriodicChecks();
        }

        this.isInitialized = true;
        console.log('[SessionManager] âœ… InicializaÃ§Ã£o concluÃ­da');
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * VERIFICAR SESSÃƒO (ÃšNICO lugar que faz este fetch!)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    async checkSession() {
        // Guard: evitar requests simultÃ¢neos
        if (this.isFetching) {
            console.log('[SessionManager] â„¹ï¸ VerificaÃ§Ã£o jÃ¡ em progresso, aguardando...');
            return this.isAuthenticated;
        }

        this.isFetching = true;

        try {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), this.TIMEOUT);

            const response = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    signal: controller.signal
                }
            );

            clearTimeout(timeout);

            if (!response.ok) {
                console.warn('[SessionManager] âš ï¸ Resposta nÃ£o OK:', response.status);
                this.handleSessionExpired('response_not_ok');
                return false;
            }

            const data = await response.json();

            if (data.sucesso && data.sessao_ativa) {
                console.log('[SessionManager] âœ… SessÃ£o ativa');

                // Atualizar estado local
                this.isAuthenticated = true;
                this.currentUser = data.usuario;
                this.sessionExpireTime = data.sessao?.tempo_restante;

                // Persistir estado
                this.persistState();

                // EMITIR EVENTO (UI vai escutar e renderizar)
                this.emit('userDataChanged', {
                    user: this.currentUser,
                    expireTime: this.sessionExpireTime
                });

                this.isFetching = false;
                return true;
            } else {
                console.warn('[SessionManager] âš ï¸ SessÃ£o inativa');
                this.handleSessionExpired('not_active');
                return false;
            }
        } catch (error) {
            console.error('[SessionManager] âŒ Erro ao verificar sessÃ£o:', error.message);

            // Registrar Ãºltimo erro
            this.lastError = {
                message: error.message,
                type: error.name || 'unknown',
                timestamp: Date.now()
            };

            // TIMEOUT: Servidor nÃ£o respondeu a tempo
            if (error.name === 'AbortError') {
                console.warn('[SessionManager] âš ï¸ Timeout na verificaÃ§Ã£o (15s)');
                this.emit('error', {
                    type: 'timeout',
                    message: 'Servidor nÃ£o respondeu em 15s'
                });
                this.isFetching = false;
                return this.isAuthenticated; // Manter sessÃ£o
            }

            // ERRO DE REDE: Sem conexÃ£o com server
            if (error instanceof TypeError) {
                console.warn('[SessionManager] âš ï¸ Erro de rede durante verificaÃ§Ã£o');
                this.isOnline = false;
                this.emit('error', {
                    type: 'network',
                    message: error.message
                });
                this.isFetching = false;
                return this.isAuthenticated; // Manter sessÃ£o
            }

            // ERRO DESCONHECIDO: Fazer logout seguro
            console.error('[SessionManager] âŒ Erro desconhecido na verificaÃ§Ã£o:', error);
            this.handleSessionExpired('unknown_error');
            this.isFetching = false;
            return false;
        }
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * RENOVAR SESSÃƒO (automÃ¡tico)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    async renewSession() {
        if (!this.isAuthenticated) {
            console.log('[SessionManager] â„¹ï¸ NÃ£o autenticado, pulando renovaÃ§Ã£o');
            return false;
        }

        if (this.isFetching) {
            console.log('[SessionManager] â„¹ï¸ RenovaÃ§Ã£o jÃ¡ em progresso');
            return false;
        }

        try {
            console.log('[SessionManager] ðŸ”„ Renovando sessÃ£o...');

            this.isFetching = true;

            const formData = new FormData();
            formData.append('acao', 'renovar');

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), this.TIMEOUT);

            const response = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'include',
                    signal: controller.signal
                }
            );

            clearTimeout(timeout);

            if (response.ok) {
                const data = await response.json();

                // Validar resposta
                if (!data.sucesso) {
                    console.warn('[SessionManager] âš ï¸ Resposta invÃ¡lida na renovaÃ§Ã£o');
                    this.isFetching = false;
                    return false;
                }

                // Atualizar tempo de expiraÃ§Ã£o
                if (data.tempo_restante_segundos) {
                    this.sessionExpireTime = data.tempo_restante_segundos;
                }

                // Atualizar dados do usuÃ¡rio se veio na resposta
                if (data.usuario) {
                    this.currentUser = data.usuario;
                }

                // Registrar sucesso
                this.lastSuccessfulCheck = Date.now();

                // Emitir evento de renovaÃ§Ã£o
                this.emit('sessionRenewed', {
                    expireTime: this.sessionExpireTime,
                    user: this.currentUser
                });

                console.log('[SessionManager] âœ… SessÃ£o renovada com sucesso');
                this.isFetching = false;
                return true;
            } else {
                console.warn('[SessionManager] âš ï¸ RenovaÃ§Ã£o falhou:', response.status);
                this.isFetching = false;
                return false;
            }
        } catch (error) {
            console.error('[SessionManager] âŒ Erro ao renovar:', error.message);
            this.isFetching = false;
            return false;
        }
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * LOGOUT (ÃšNICO lugar!)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    async logout() {
        console.log('[SessionManager] ðŸšª Fazendo logout...');

        try {
            // 1. Chamar API
            await fetch(`${this.API_BASE}logout.php`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            }).catch(() => {
                // Ignorar erros de rede
                console.warn('[SessionManager] âš ï¸ Erro ao chamar logout.php, mas continuando...');
            });
        } catch (e) { }

        // 2. Limpar TUDO - localStorage, sessionStorage e caches
        console.log('[SessionManager] ðŸ§¹ Limpando todos os caches...');

        try {
            localStorage.clear();
            sessionStorage.clear();

            // Limpar service worker caches se existir
            if ('caches' in window) {
                caches.keys().then(names => {
                    for (let name of names) {
                        caches.delete(name);
                    }
                });
            }
        } catch (e) {
            console.warn('[SessionManager] âš ï¸ Erro ao limpar caches:', e);
        }

        // 3. Limpar estado local
        this.isAuthenticated = false;
        this.currentUser = null;
        this.sessionExpireTime = null;
        this.stopPeriodicChecks();

        // 4. Emitir evento
        this.emit('sessionExpired', {});

        // 5. Redirecionar com replace() para evitar histÃ³rico
        setTimeout(() => {
            console.log('[SessionManager] ðŸ”„ Redirecionando para login...');
            window.location.replace('login.html'); // Usar replace, nÃ£o href
        }, 300);
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * VERIFICAÃ‡Ã•ES PERIÃ“DICAS
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    startPeriodicChecks() {
        if (this.checkTimer) {
            console.log('[SessionManager] â„¹ï¸ VerificaÃ§Ãµes periÃ³dicas jÃ¡ ativas');
            return;
        }

        console.log('[SessionManager] â–¶ï¸ Iniciando verificaÃ§Ãµes periÃ³dicas');

        // VerificaÃ§Ã£o a cada 60s
        this.checkTimer = setInterval(() => {
            this.checkSession();
        }, this.CHECK_INTERVAL);

        // RenovaÃ§Ã£o a cada 5min
        this.renewTimer = setInterval(() => {
            this.renewSession();
        }, this.RENEW_INTERVAL);
    }

    stopPeriodicChecks() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
            this.checkTimer = null;
        }
        if (this.renewTimer) {
            clearInterval(this.renewTimer);
            this.renewTimer = null;
        }
        console.log('[SessionManager] â¹ï¸ VerificaÃ§Ãµes periÃ³dicas paradas');
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * TRATAMENTO DE EXPIRAÃ‡ÃƒO
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    handleSessionExpired(reason) {
        console.warn(`[SessionManager] âŒ SessÃ£o expirou (motivo: ${reason})`);

        this.isAuthenticated = false;
        this.currentUser = null;
        this.clearPersistedState();
        this.stopPeriodicChecks();

        // Emitir evento (UI e auth-guard vÃ£o saber)
        this.emit('sessionExpired', { reason });

        // Redirecionar se em pÃ¡gina protegida
        if (!this.isPublicPage()) {
            setTimeout(() => this.redirectToLogin(), 500);
        }
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * EVENT SYSTEM (Observer Pattern)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    on(event, callback) {
        if (typeof callback === 'function') {
            if (!this.listeners.has(event)) {
                this.listeners.set(event, []);
            }
            this.listeners.get(event).push(callback);

            // Retornar unsubscribe function
            return () => {
                const list = this.listeners.get(event);
                const index = list.indexOf(callback);
                if (index > -1) {
                    list.splice(index, 1);
                }
            };
        }
    }

    // âœ… COMPATIBILIDADE: MÃ©todo especÃ­fico exigido pela UI
    onUserDataChanged(callback) {
        return this.on('userDataChanged', callback);
    }

    emit(event, data) {
        if (this.listeners.has(event)) {
            const callbacks = this.listeners.get(event);
            callbacks.forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error(
                        `[SessionManager] âŒ Erro em listener '${event}':`,
                        error
                    );
                }
            });
        }
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * GETTERS (UI can query state)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    isLoggedIn() {
        return this.isAuthenticated;
    }

    getUser() {
        return this.currentUser;
    }

    getUserData() {
        return this.currentUser;
    }

    getSessionExpireTime() {
        return this.sessionExpireTime;
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * REFRESH USER DATA (Atualizar dados do usuário)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * 
     * Método de compatibilidade para chamar checkSession() e retornar dados.
     * Usado por componentes que precisam atualizar dados do usuário manualmente.
     * 
     * @returns {Promise<Object>} Dados da sessão com estrutura normalizada
     * @example
     * const sessionMgr = SessionManagerCore.getInstance();
     * const dados = await sessionMgr.refreshUserData();
     * console.log(dados.usuario); // Dados do usuário atual
     */
    async refreshUserData() {
        console.log('[SessionManager] 🔄 Atualizando dados do usuário (refreshUserData)...');
        
        try {
            // Executar verificação de sessão
            const checkOk = await this.checkSession();
            
            if (!checkOk) {
                console.warn('[SessionManager] ⚠️ Verificação de sessão falhou em refreshUserData');
                return {
                    sucesso: false,
                    sessao_ativa: false,
                    usuario: null,
                    tempo_restante: null
                };
            }
            
            // Retornar dados normalizados
            const dados = {
                sucesso: true,
                sessao_ativa: this.isAuthenticated,
                usuario: this.currentUser,
                tempo_restante: this.sessionExpireTime,
                sessao: {
                    tempo_restante: this.sessionExpireTime,
                    tempo_restante_formatado: this.formatarTempoRestante(this.sessionExpireTime)
                }
            };
            
            console.log('[SessionManager] ✅ Dados do usuário atualizados com sucesso');
            return dados;
            
        } catch (erro) {
            console.error('[SessionManager] ❌ Erro ao atualizar dados do usuário:', erro.message);
            return {
                sucesso: false,
                sessao_ativa: false,
                usuario: null,
                tempo_restante: null,
                erro: erro.message
            };
        }
    }

    /**
     * Formatar tempo restante em formato legível
     * @private
     */
    formatarTempoRestante(segundos) {
        if (!segundos || segundos <= 0) return '0s';
        
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        const secs = segundos % 60;
        
        if (horas > 0) {
            return `${horas}h ${minutos}m`;
        } else if (minutos > 0) {
            return `${minutos}m ${secs}s`;
        } else {
            return `${secs}s`;
        }
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * GET SESSION DATA (for UI components)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    async getSessionData() {
        try {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), this.TIMEOUT);

            const response = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    signal: controller.signal
                }
            );

            clearTimeout(timeout);

            if (!response.ok) {
                return { sucesso: false, logado: false };
            }

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('[SessionManager] âŒ Erro em getSessionData:', error.message);
            return { sucesso: false, logado: false };
        }
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * PERSISTÃŠNCIA (localStorage)
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    persistState() {
        try {
            // SEGURANÃ‡A: SÃ³ guardar flag de autenticaÃ§Ã£o, NUNCA dados sensÃ­veis
            localStorage.setItem(
                this.storageKey,
                JSON.stringify({
                    // isAuthenticated: this.isAuthenticated, // REMOVIDO por seguranÃ§a
                    timestamp: Date.now()
                })
            );
        } catch (e) {
            console.warn('[SessionManager] âš ï¸ Erro ao persistir estado:', e.message);
        }
    }

    loadPersistedState() {
        try {
            const data = localStorage.getItem(this.storageKey);
            if (!data) return null;

            const parsed = JSON.parse(data);
            const age = Date.now() - (parsed.timestamp || 0);

            // Descartar se mais velho que 24h
            if (age > 86400000) {
                console.log('[SessionManager] â„¹ï¸ Estado persistido expirou (24h+)');
                this.clearPersistedState();
                return null;
            }

            return parsed;
        } catch (e) {
            console.warn('[SessionManager] âš ï¸ Erro ao carregar persisted:', e);
            this.clearPersistedState();
            return null;
        }
    }

    clearPersistedState() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch (e) { }
    }

    /**
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     * HELPERS
     * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     */
    isPublicPage() {
        const publicPages = [
            'login.html',
            'login_morador.html',
            'login_fornecedor.html',
            'esqueci_senha.html',
            'redefinir_senha.html',
            'index.html',
            'register.html',
            'layout-base.html?page=registro'
        ];
        const pathname = window.location.pathname;
        const page = pathname.split('/').pop();
        return publicPages.includes(page) || page === '' || page === 'frontend/';
    }

    redirectToLogin() {
        console.log('[SessionManager] ðŸ”„ Redirecionando para login...');
        window.location.replace('login.html'); // Usar replace, nÃ£o href
    }
}

/**
 * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
 * AUTO-INICIALIZAÃ‡ÃƒO
 * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
 * 
 * SessionManager inicializa automaticamente quando page carrega.
 * Garante que inicializa UMA ÃšNICA VEZ, independente de quantas vezes
 * o script Ã© carregado.
 */
(function initializeSessionManager() {
    console.log('[SessionManager] ðŸ“ Script carregado');

    if (document.readyState === 'loading') {
        // Document ainda estÃ¡ carregando
        document.addEventListener('DOMContentLoaded', async () => {
            const manager = SessionManagerCore.getInstance();
            await manager.initialize();
            window.sessionManager = manager;
            console.log('[SessionManager] âœ… Anexado a window.sessionManager');
        });
    } else {
        // Document jÃ¡ carregou
        try {
            const manager = SessionManagerCore.getInstance();
            manager.initialize().then(() => {
                window.sessionManager = manager;
                console.log('[SessionManager] âœ… Anexado a window.sessionManager');
            });
        } catch (e) {
            console.error('[SessionManager] âŒ Erro na inicializaÃ§Ã£o:', e);
        }
    }
})();

// Export para uso em mÃ³dulos (se aplicÃ¡vel)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SessionManagerCore;
}

