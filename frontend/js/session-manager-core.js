/**
  * ============================================================
 * SESSION MANAGER CORE v3.1 - ?NICO PONTO DE CONTROLE DE SESS?O
  * ============================================================
 *
 * CORRE??ES v3.1 (2026-05-13):
 *   ? API_BASE usa URL absoluta (window.location.origin + '/api/') ? corrige
 *     resolu??o errada '../api/' ? /frontend/api/ (n?o existe)
 *   ? Timeout padr?o: 60 min total, sem inatividade (0 = desabilitado)
 *   ? Suporte a sessaoInativa: true = nunca faz logout autom?tico
 *   ? Countdown exibe '?' quando sess?o inativa est? ativa
 *
 * CORRE??ES v3.0 (2026-05-10):
 *   ? Envia token Bearer (localStorage.portal_token) nas requisi??es ao portal
 *   ? Suporte a timeout personalizado por usu?rio (retornado pela API)
 *   ? Ping autom?tico de atividade (a cada 2 min) para reset do timer de inatividade
 *   ? Countdown em tempo real no menu superior (decrementa a cada segundo)
 *   ? Aviso visual quando restam ? 5 minutos
 *   ? Padr?o: 60 min total, sem timeout de inatividade
 *
 * ? PRINC?PIOS:
 *   ? Sess?o ? UI (UI nunca valida ou renova sess?o)
 *   ? Apenas um fetch por requisi??o de sess?o
 *   ? Estado centralizado e compartilhado
 *   ? Listeners s?o consumidores PASSIVOS
 *   ? Logout centralizado (n?o espalhado em 24 p?ginas)
 *
 * ? RESPONSABILIDADES:
 *   ? verificarSessao() ? 1x por startup + 1x/60s
 *   ? renovarSessao()   ? ping a cada 2min (reset inatividade)
 *   ? countdown local   ? decrementa 1s/s entre checks
 *   ? logout()          ? centralizado
 *   ? emitir eventos    ? para UI reagir
 *
  * ============================================================
 */

class SessionManagerCore {
    static instance = null;
    static locked   = false;

    constructor() {
        if (SessionManagerCore.instance) {
            throw new Error('[SessionManager] ? SessionManagerCore j? foi instanciado! Use getInstance().');
        }

        // --- CONSTANTES ---
        // URL absoluta: evita resolu??o errada para /frontend/api/ quando
        // o script est? em /frontend/js/ e o browser resolve '../api/' como /frontend/api/
        this.API_BASE          = window.location.origin + '/api/';
        this.CHECK_INTERVAL    = 60000;   // 60s ? verifica??o completa com a API
        this.PING_INTERVAL     = 120000;  // 2min ? ping de atividade (reset inatividade)
        this.COUNTDOWN_TICK    = 1000;    // 1s ? decremento local do countdown
        this.TIMEOUT           = 15000;   // 15s timeout de fetch
        this.MAX_RETRIES       = 1;

        // --- ESTADO ---
        this.isAuthenticated   = false;
        this.currentUser       = null;
        this.sessionExpireTime = null;    // segundos restantes (atualizado pela API)
        this.countdownSeconds  = null;    // segundos restantes (decrementado localmente)
        this.isFetching        = false;
        this.isInitialized     = false;
        this.initializationPromise = null;
        this.lastError         = null;
        this.lastSuccessfulCheck = null;
        this.isOnline          = navigator.onLine;

        // Configura??es de timeout (preenchidas pela API)
        // Padr?o: 60 min total, sem limite de inatividade (0 = desabilitado)
        this.timeoutTotalMin      = 60;
        this.timeoutInatividadeMin = 0;   // 0 = sem timeout de inatividade
        this.avisoExpiracaoMin    = 5;
        this.sessaoInativa        = false; // true = nunca faz logout autom?tico

        // --- TIMERS ---
        this.checkTimer     = null;   // verifica??o peri?dica (60s)
        this.pingTimer      = null;   // ping de atividade (2min)
        this.countdownTimer = null;   // countdown local (1s)

        // --- EVENT SYSTEM ---
        this.listeners = new Map();
        this.listeners.set('userDataChanged', []);
        this.listeners.set('sessionExpired',  []);
        this.listeners.set('error',           []);
        this.listeners.set('sessionRenewed',  []);
        this.listeners.set('countdownTick',   []);  // NOVO: tick a cada segundo

        // --- PERSIST?NCIA ---
        this.storageKey = 'sessionManagerState_v3';

        SessionManagerCore.instance = this;
        SessionManagerCore.locked   = true;

        console.log('[SessionManager] ? SessionManagerCore v3.0 inicializado (singleton)');
    }

    // --- SINGLETON ---
    static getInstance() {
        if (!SessionManagerCore.instance) {
            SessionManagerCore.instance = new SessionManagerCore();
        }
        return SessionManagerCore.instance;
    }

    // --- OBTER TOKEN BEARER ---
    /**
     * L? o token do portal do morador salvo no localStorage ap?s o login.
     * O login.html salva: localStorage.setItem('portal_token', data.dados.token)
     */
    getPortalToken() {
        try {
            return localStorage.getItem('portal_token') || '';
        } catch (e) {
            return '';
        }
    }

    /**
     * Monta os headers de Authorization para as requisi??es.
     * Se houver token do portal, usa Bearer. Caso contr?rio, sem header extra.
     */
    getAuthHeaders() {
        const token = this.getPortalToken();
        const headers = { 'Content-Type': 'application/json' };
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }
        return headers;
    }

    // --- INICIALIZA??O ---
    async initialize() {
        if (this.isInitialized) {
            console.log('[SessionManager] ?? J? inicializado, pulando...');
            return true;
        }
        if (this.initializationPromise) {
            console.log('[SessionManager] ? Inicializa??o j? em andamento, aguardando...');
            return this.initializationPromise;
        }

        this.initializationPromise = (async () => {
            console.log('[SessionManager] Inicializando v3.0...');

            window.addEventListener('online',  () => { this.isOnline = true;  this.checkSession(); });
            window.addEventListener('offline', () => { this.isOnline = false; });

            // Detectar atividade do usu?rio para ping
            this._setupActivityListeners();

            const checkOk = await this.checkSession();

            if (!checkOk && !this.isPublicPage()) {
                console.warn('[SessionManager] ?? Verifica??o inicial falhou, redirecionando');
                this.redirectToLogin();
                return false;
            }

            if (!this.isPublicPage()) {
                this.startPeriodicChecks();
            }

            this.isInitialized = true;
            console.log('[SessionManager] ? Inicializa??o v3.0 conclu?da');
            return true;
        })();

        try {
            return await this.initializationPromise;
        } finally {
            this.initializationPromise = null;
        }
    }

    // --- LISTENERS DE ATIVIDADE ---
    /**
     * Detecta atividade do usu?rio (mouse, teclado, toque) para
     * registrar que o usu?rio est? ativo (evita expirar por inatividade).
     * O ping real ? enviado a cada PING_INTERVAL (2min).
     */
    _setupActivityListeners() {
        this._lastActivity = Date.now();
        const updateActivity = () => { this._lastActivity = Date.now(); };
        ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(evt => {
            document.addEventListener(evt, updateActivity, { passive: true });
        });
    }

    // --- VERIFICAR SESS?O ---
    async checkSession() {
        if (this.isFetching) {
            console.log('[SessionManager] ?? Verifica??o j? em progresso, aguardando...');
            return this.isAuthenticated;
        }
        this.isFetching = true;

        try {
            const controller = new AbortController();
            const timeout    = setTimeout(() => controller.abort(), this.TIMEOUT);

            const response = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                {
                    method:      'GET',
                    credentials: 'include',
                    headers:     this.getAuthHeaders(),
                    signal:      controller.signal
                }
            );
            clearTimeout(timeout);

            if (!response.ok) {
                console.warn('[SessionManager] ?? Resposta n?o OK:', response.status);
                this.handleSessionExpired('response_not_ok');
                return false;
            }

            const data = await response.json();

            if (data.sucesso && data.sessao_ativa) {
                console.log('[SessionManager] ? Sess?o ativa');

                this.isAuthenticated   = true;
                this.currentUser       = data.usuario;
                this.sessionExpireTime = data.sessao?.tempo_restante ?? data.tempo_restante_segundos ?? null;
                this.countdownSeconds  = this.sessionExpireTime;
                this.lastSuccessfulCheck = Date.now();

                // Salvar configura??es de timeout retornadas pela API
                if (data.sessao) {
                    this.timeoutTotalMin       = data.sessao.timeout_total_min       ?? 60;
                    this.timeoutInatividadeMin = data.sessao.timeout_inatividade_min ?? 0;
                    this.avisoExpiracaoMin     = data.sessao.aviso_expiracao_min     ?? 5;
                    // sessao_inativa: true = nunca faz logout autom?tico
                    this.sessaoInativa         = data.sessao.sessao_inativa          ?? false;
                }
                // Se sess?o inativa, n?o decrementar countdown (sess?o permanente)
                if (this.sessaoInativa) {
                    this.countdownSeconds = null; // null = exibe '?' no menu
                }

                this.persistState();

                // Reiniciar countdown local
                this._startCountdown();

                // Emitir evento com todos os dados necess?rios para a UI
                this.emit('userDataChanged', {
                    user:          this.currentUser,
                    usuario:       this.currentUser,
                    expireTime:    this.sessionExpireTime,
                    tempo_restante: this.sessionExpireTime,
                    sessao: {
                        tempo_restante:           this.sessionExpireTime,
                        tempo_restante_formatado: this.formatarTempoRestante(this.sessionExpireTime),
                        timeout_total_min:        this.timeoutTotalMin,
                        timeout_inatividade_min:  this.timeoutInatividadeMin,
                        aviso_expiracao_min:      this.avisoExpiracaoMin,
                        sessao_inativa:           this.sessaoInativa,
                    }
                });

                this.isFetching = false;
                return true;
            } else {
                console.warn('[SessionManager] ?? Sess?o inativa');
                this.handleSessionExpired('not_active');
                return false;
            }
        } catch (error) {
            console.error('[SessionManager] ? Erro ao verificar sess?o:', error.message);
            this.lastError = { message: error.message, type: error.name || 'unknown', timestamp: Date.now() };

            if (error.name === 'AbortError') {
                console.warn('[SessionManager] ?? Timeout na verifica??o (15s)');
                this.emit('error', { type: 'timeout', message: 'Servidor n?o respondeu em 15s' });
                this.isFetching = false;
                return this.isAuthenticated;
            }
            if (error instanceof TypeError) {
                console.warn('[SessionManager] ?? Erro de rede durante verifica??o');
                this.isOnline = false;
                this.emit('error', { type: 'network', message: error.message });
                this.isFetching = false;
                return this.isAuthenticated;
            }
            console.error('[SessionManager] ? Erro desconhecido na verifica??o:', error);
            this.handleSessionExpired('unknown_error');
            this.isFetching = false;
            return false;
        }
    }

    // --- COUNTDOWN LOCAL (1s/s) ---
    /**
     * Decrementa o countdown a cada segundo localmente (sem fetch).
     * Quando o servidor responde, o valor ? sincronizado.
     */
    _startCountdown() {
        if (this.countdownTimer) {
            clearInterval(this.countdownTimer);
            this.countdownTimer = null;
        }

        // Sess?o inativa: n?o decrementar, exibir '?'
        if (this.sessaoInativa) {
            this.emit('countdownTick', {
                segundos:  null,
                formatado: '?',
                aviso:     false,
                expirou:   false,
                permanente: true,
            });
            return;
        }

        if (this.countdownSeconds === null || this.countdownSeconds <= 0) return;

        this.countdownTimer = setInterval(() => {
            if (this.countdownSeconds > 0) {
                this.countdownSeconds--;

                // Emitir tick para a UI atualizar o display
                this.emit('countdownTick', {
                    segundos:   this.countdownSeconds,
                    formatado:  this.formatarTempoRestante(this.countdownSeconds),
                    aviso:      this.countdownSeconds <= (this.avisoExpiracaoMin * 60),
                    expirou:    false,
                });

                // Tamb?m atualizar o evento userDataChanged para compatibilidade
                if (this.currentUser) {
                    this.emit('userDataChanged', {
                        user:          this.currentUser,
                        usuario:       this.currentUser,
                        expireTime:    this.countdownSeconds,
                        tempo_restante: this.countdownSeconds,
                        sessao: {
                            tempo_restante:           this.countdownSeconds,
                            tempo_restante_formatado: this.formatarTempoRestante(this.countdownSeconds),
                            timeout_total_min:        this.timeoutTotalMin,
                            timeout_inatividade_min:  this.timeoutInatividadeMin,
                            aviso_expiracao_min:      this.avisoExpiracaoMin,
                        }
                    });
                }
            } else {
                // Countdown chegou a zero ? verificar com servidor
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
                console.warn('[SessionManager] ?? Countdown zerado ? verificando sess?o...');
                this.checkSession();
            }
        }, this.COUNTDOWN_TICK);
    }

    // --- RENOVAR SESS?O (ping de atividade) ---
    async renewSession() {
        if (!this.isAuthenticated) {
            console.log('[SessionManager] ?? N?o autenticado, pulando renova??o');
            return false;
        }
        if (this.isFetching) {
            console.log('[SessionManager] ?? Renova??o j? em progresso');
            return false;
        }

        // S? enviar ping se o usu?rio teve atividade recente (?ltimos 5 min)
        const inativo = Date.now() - (this._lastActivity || 0);
        if (inativo > 5 * 60 * 1000) {
            console.log('[SessionManager] ?? Usu?rio inativo h? ' + Math.round(inativo/60000) + 'min, pulando ping');
            return false;
        }

        try {
            console.log('[SessionManager] ? Enviando ping de atividade...');
            this.isFetching = true;

            const formData = new FormData();
            formData.append('acao', 'renovar');

            const token = this.getPortalToken();
            const headers = {};
            if (token) headers['Authorization'] = 'Bearer ' + token;

            const controller = new AbortController();
            const timeout    = setTimeout(() => controller.abort(), this.TIMEOUT);

            const response = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                {
                    method:      'POST',
                    body:        formData,
                    credentials: 'include',
                    headers:     headers,
                    signal:      controller.signal
                }
            );
            clearTimeout(timeout);

            if (response.ok) {
                const data = await response.json();
                if (data.sucesso) {
                    // Sincronizar countdown com o servidor
                    if (data.tempo_restante_segundos) {
                        this.sessionExpireTime = data.tempo_restante_segundos;
                        this.countdownSeconds  = data.tempo_restante_segundos;
                        this._startCountdown();
                    }
                    this.lastSuccessfulCheck = Date.now();
                    this.emit('sessionRenewed', {
                        expireTime: this.sessionExpireTime,
                        user:       this.currentUser
                    });
                    console.log('[SessionManager] ? Ping de atividade enviado com sucesso');
                    this.isFetching = false;
                    return true;
                }
            }
            console.warn('[SessionManager] ?? Ping falhou:', response.status);
            this.isFetching = false;
            return false;
        } catch (error) {
            console.error('[SessionManager] ? Erro no ping:', error.message);
            this.isFetching = false;
            return false;
        }
    }

    // --- LOGOUT ---
    async logout() {
        console.log('[SessionManager] ? Fazendo logout...');

        try {
            const token   = this.getPortalToken();
            const headers = {};
            if (token) headers['Authorization'] = 'Bearer ' + token;

            await fetch(`${this.API_BASE}verificar_sessao_completa.php`, {
                method:      'POST',
                credentials: 'include',
                headers:     headers,
                body:        new URLSearchParams({ acao: 'logout' })
            }).catch(() => console.warn('[SessionManager] ?? Erro ao chamar logout, mas continuando...'));
        } catch (e) {}

        console.log('[SessionManager] ? Limpando todos os caches...');
        try {
            localStorage.clear();
            sessionStorage.clear();
            if ('caches' in window) {
                caches.keys().then(names => names.forEach(n => caches.delete(n)));
            }
        } catch (e) { console.warn('[SessionManager] ?? Erro ao limpar caches:', e); }

        this.isAuthenticated   = false;
        this.currentUser       = null;
        this.sessionExpireTime = null;
        this.countdownSeconds  = null;
        this.stopPeriodicChecks();

        this.emit('sessionExpired', {});

        setTimeout(() => {
            console.log('[SessionManager] ? Redirecionando para login...');
            window.location.replace(window.location.origin + '/frontend/login.html');
        }, 300);
    }

    // --- VERIFICA??ES PERI?DICAS ---
    startPeriodicChecks() {
        if (this.checkTimer) {
            console.log('[SessionManager] ?? Verifica??es peri?dicas j? ativas');
            return;
        }
        console.log('[SessionManager] ?? Iniciando verifica??es peri?dicas v3.0');

        // Verifica??o completa a cada 60s
        this.checkTimer = setInterval(() => {
            this.checkSession();
        }, this.CHECK_INTERVAL);

        // Ping de atividade a cada 2min
        this.pingTimer = setInterval(() => {
            this.renewSession();
        }, this.PING_INTERVAL);
    }

    stopPeriodicChecks() {
        if (this.checkTimer)     { clearInterval(this.checkTimer);     this.checkTimer     = null; }
        if (this.pingTimer)      { clearInterval(this.pingTimer);      this.pingTimer      = null; }
        if (this.countdownTimer) { clearInterval(this.countdownTimer); this.countdownTimer = null; }
        console.log('[SessionManager] ?? Verifica??es peri?dicas paradas');
    }

    // --- TRATAMENTO DE EXPIRA??O ---
    handleSessionExpired(reason) {
        // Se sess?o inativa est? ativa, NUNCA faz logout autom?tico
        if (this.sessaoInativa) {
            console.log('[SessionManager] ?? Sess?o inativa ativa ? ignorando expira??o:', reason);
            // Tentar renovar a sess?o no servidor
            this.checkSession();
            return;
        }
        console.warn(`[SessionManager] ? Sess?o expirou (motivo: ${reason})`);
        this.isAuthenticated   = false;
        this.currentUser       = null;
        this.countdownSeconds  = null;
        this.clearPersistedState();
        this.stopPeriodicChecks();
        this.emit('sessionExpired', { reason });
        if (!this.isPublicPage()) {
            setTimeout(() => this.redirectToLogin(), 500);
        }
    }
    on(event, callback) {
        if (typeof callback === 'function') {
            if (!this.listeners.has(event)) this.listeners.set(event, []);
            this.listeners.get(event).push(callback);
            return () => {
                const list  = this.listeners.get(event);
                const index = list.indexOf(callback);
                if (index > -1) list.splice(index, 1);
            };
        }
    }

    onUserDataChanged(callback) { return this.on('userDataChanged', callback); }

    emit(event, data) {
        if (this.listeners.has(event)) {
            this.listeners.get(event).forEach(cb => {
                try { cb(data); } catch (e) {
                    console.error(`[SessionManager] ? Erro em listener '${event}':`, e);
                }
            });
        }
    }

    // --- GETTERS ---
    isLoggedIn()          { return this.isAuthenticated; }
    getUser()             { return this.currentUser; }
    getUserData()         { return this.currentUser; }
    getSessionExpireTime(){ return this.countdownSeconds ?? this.sessionExpireTime; }

    // --- REFRESH USER DATA ---
    async refreshUserData() {
        console.log('[SessionManager] ? Atualizando dados do usu?rio (refreshUserData)...');
        try {
            const checkOk = await this.checkSession();
            if (!checkOk) return { sucesso: false, sessao_ativa: false, usuario: null, tempo_restante: null };
            return {
                sucesso:      true,
                sessao_ativa: this.isAuthenticated,
                usuario:      this.currentUser,
                tempo_restante: this.countdownSeconds,
                sessao: {
                    tempo_restante:           this.countdownSeconds,
                    tempo_restante_formatado: this.formatarTempoRestante(this.countdownSeconds),
                }
            };
        } catch (erro) {
            console.error('[SessionManager] ? Erro ao atualizar dados do usu?rio:', erro.message);
            return { sucesso: false, sessao_ativa: false, usuario: null, tempo_restante: null, erro: erro.message };
        }
    }

    // --- FORMATAR TEMPO ---
    formatarTempoRestante(segundos) {
        if (!segundos || segundos <= 0) return '00:00';
        const horas    = Math.floor(segundos / 3600);
        const minutos  = Math.floor((segundos % 3600) / 60);
        const secs     = segundos % 60;
        if (horas > 0) {
            return `${String(horas).padStart(2,'0')}:${String(minutos).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
        }
        return `${String(minutos).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
    }

    // --- GET SESSION DATA ---
    async getSessionData() {
        try {
            const controller = new AbortController();
            const timeout    = setTimeout(() => controller.abort(), this.TIMEOUT);
            const response   = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                { method: 'GET', credentials: 'include', headers: this.getAuthHeaders(), signal: controller.signal }
            );
            clearTimeout(timeout);
            if (!response.ok) return { sucesso: false, logado: false };
            return await response.json();
        } catch (error) {
            console.error('[SessionManager] ? Erro em getSessionData:', error.message);
            return { sucesso: false, logado: false };
        }
    }

    // --- PERSIST?NCIA ---
    persistState() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify({ timestamp: Date.now() }));
        } catch (e) { console.warn('[SessionManager] ?? Erro ao persistir estado:', e.message); }
    }

    loadPersistedState() {
        try {
            const data = localStorage.getItem(this.storageKey);
            if (!data) return null;
            const parsed = JSON.parse(data);
            if (Date.now() - (parsed.timestamp || 0) > 86400000) {
                this.clearPersistedState();
                return null;
            }
            return parsed;
        } catch (e) { this.clearPersistedState(); return null; }
    }

    clearPersistedState() {
        try { localStorage.removeItem(this.storageKey); } catch (e) {}
    }

    // --- HELPERS ---
    isPublicPage() {
        const publicPages = [
            'login.html','login_morador.html','login_fornecedor.html',
            'esqueci_senha.html','redefinir_senha.html','index.html',
            'register.html','layout-base.html?page=registro'
        ];
        const pathname = window.location.pathname;
        const page     = pathname.split('/').pop();
        return publicPages.includes(page) || page === '' || page === 'frontend/';
    }

    redirectToLogin() {
        console.log('[SessionManager] ? Redirecionando para login...');
        window.location.replace(window.location.origin + '/frontend/login.html');
    }
}

// Expor classe no escopo global
window.SessionManagerCore = SessionManagerCore;

/**
  * ============================================================
 * AUTO-INICIALIZA??O
  * ============================================================
 */
(function initializeSessionManager() {
    console.log('[SessionManager] ? Script v3.0 carregado');

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', async () => {
            const manager = SessionManagerCore.getInstance();
            await manager.initialize();
            window.sessionManager = manager;
            console.log('[SessionManager] ? Anexado a window.sessionManager');
        });
    } else {
        try {
            const manager = SessionManagerCore.getInstance();
            manager.initialize().then(() => {
                window.sessionManager = manager;
                console.log('[SessionManager] ? Anexado a window.sessionManager');
            });
        } catch (e) {
            console.error('[SessionManager] ? Erro na inicializa??o:', e);
        }
    }
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = SessionManagerCore;
}
