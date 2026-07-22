/**
 * ═══════════════════════════════════════════════════════════════════════════
 * UI COMPONENT BASE - Padrão para Listeners Defensivos
 * ═══════════════════════════════════════════════════════════════════════════
 * 
 * Todos os listeners DEVEM usar este padrão para garantir:
 * ✅ ❌ Nenhum fetch
 * ✅ Acesso defensivo a dados
 * ✅ Isolamento de erros (try/catch por listener)
 * ✅ Sem lógica de sessão
 * ✅ Apenas renderização
 */

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PADRÃO 1: Listener Simples (Dashboard, UserProfile)
 * ═══════════════════════════════════════════════════════════════════════════
 */
(function setupUserProfileListener() {
    'use strict';

    console.log('[UIComponent] ▶️ Inicializando listener de perfil');

    if (!window.sessionManager) {
        console.warn('[UIComponent] ⚠️ SessionManager não disponível');
        return;
    }

    // NUNCA fazer fetch aqui!
    // NUNCA validar sessão aqui!
    // APENAS renderizar!

    /**
     * Renderizar perfil (função PURA)
     * Entrada: user object
     * Saída: HTML renderizado
     */
    function renderUserProfile(user) {
        try {
            const section = document.getElementById('userProfileSection');
            if (!section) return;

            // ✅ Acesso defensivo
            if (!user || typeof user !== 'object') {
                section.style.display = 'none';
                console.log('[UIComponent] ℹ️ Usuário inválido, ocultando perfil');
                return;
            }

            // ✅ Defaults seguros
            const nome = (user.nome && typeof user.nome === 'string') ? user.nome : 'Usuário';
            const email = (user.email && typeof user.email === 'string') ? user.email : '';
            const funcao = (user.funcao && typeof user.funcao === 'string') ? user.funcao : user.permissao || 'Padrão';

            const inicial = nome.length > 0 ? nome.charAt(0).toUpperCase() : '?';

            // ✅ Renderizar
            section.style.display = 'block';
            
            const avatarEl = document.getElementById('userAvatar');
            if (avatarEl) avatarEl.textContent = inicial;

            const nameEl = document.getElementById('userName');
            if (nameEl) nameEl.textContent = nome;

            const funcEl = document.getElementById('userFunction');
            if (funcEl) funcEl.textContent = funcao;

            const emailEl = document.getElementById('userEmail');
            if (emailEl) emailEl.textContent = email;

            console.log('[UIComponent] ✅ Perfil renderizado');
        } catch (error) {
            console.error('[UIComponent] ❌ Erro ao renderizar perfil:', error.message);
            // Não re-lançar erro! Isolar para não afetar outros listeners
        }
    }

    /**
     * Escutar evento (passivo)
     */
    const mgr = window.sessionManager;

    // ✅ Registrar listener
    mgr.on('userDataChanged', (data) => {
        console.log('[UIComponent] 📢 Evento recebido: userDataChanged');
        
        // ✅ Defensivo: verificar data
        if (!data || typeof data !== 'object') {
            console.warn('[UIComponent] ⚠️ Dados inválidos:', data);
            return;
        }

        // ✅ Ambos os campos podem estar presentes
        const user = data.user || data.usuario || null;
        renderUserProfile(user);
    });

    // ✅ Escutar expiração
    mgr.on('sessionExpired', () => {
        console.log('[UIComponent] 🔴 Sessão expirou, limpando UI');
        try {
            const section = document.getElementById('userProfileSection');
            if (section) section.style.display = 'none';
        } catch (e) {}
    });

    console.log('[UIComponent] ✅ Listener registrado com sucesso');
})();

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PADRÃO 2: Listener com Temporizador (Session Timer)
 * ═══════════════════════════════════════════════════════════════════════════
 */
(function setupSessionTimerListener() {
    'use strict';

    console.log('[SessionTimer] ▶️ Inicializando listener de temporizador');

    if (!window.sessionManager) {
        console.warn('[SessionTimer] ⚠️ SessionManager não disponível');
        return;
    }

    const mgr = window.sessionManager;

    /**
     * Formatar tempo em MM:SS
     */
    function formatTime(seconds) {
        if (typeof seconds !== 'number' || seconds < 0) {
            return '--:--';
        }
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    /**
     * Renderizar temporizador
     */
    function renderSessionTimer(expireTime) {
        try {
            const timerEl = document.getElementById('sessionTimer');
            if (!timerEl) return;

            // ✅ Defensivo
            if (typeof expireTime !== 'number' || expireTime < 0) {
                timerEl.textContent = '--:--';
                timerEl.style.color = '#999';
                return;
            }

            const formatted = formatTime(expireTime);
            timerEl.textContent = formatted;

            // ✅ Mudar cor se próximo da expiração
            if (expireTime < 300) { // Menos de 5min
                timerEl.style.color = '#ff6b6b';
            } else if (expireTime < 600) { // Menos de 10min
                timerEl.style.color = '#ffa500';
            } else {
                timerEl.style.color = '#28a745';
            }
        } catch (error) {
            console.error('[SessionTimer] ❌ Erro ao renderizar:', error.message);
        }
    }

    // ✅ Listener
    mgr.on('userDataChanged', (data) => {
        console.log('[SessionTimer] 📢 Atualizando timer');

        // ✅ Defensivo: aceitar ambos os formatos
        let expireTime = null;

        if (data && typeof data === 'object') {
            expireTime = data.expireTime || data.tempo_restante || 
                         (data.sessao ? data.sessao.tempo_restante : null);
        }

        renderSessionTimer(expireTime);
    });

    console.log('[SessionTimer] ✅ Listener registrado');
})();

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PADRÃO 3: Listener com Múltiplos Elementos (Dashboard Content)
 * ═══════════════════════════════════════════════════════════════════════════
 */
(function setupDashboardContentListener() {
    'use strict';

    console.log('[DashboardContent] ▶️ Inicializando listener de conteúdo');

    if (!window.sessionManager) {
        console.warn('[DashboardContent] ⚠️ SessionManager não disponível');
        return;
    }

    const mgr = window.sessionManager;

    /**
     * Renderizar widgets de dashboard
     * ❌ NÃO faz fetch!
     * ❌ NÃO renova sessão!
     * ✅ APENAS renderiza dados recebidos do SessionManager
     */
    function renderDashboardWidgets(userData) {
        try {
            // ✅ Defensivo: usuário válido?
            if (!userData || typeof userData !== 'object') {
                console.log('[DashboardContent] ℹ️ Dados inválidos, limpando widgets');
                clearWidgets();
                return;
            }

            // ✅ Renderizar cada widget isoladamente (se um falhar, outro não quebra)
            try {
                renderQuickStats(userData);
            } catch (e) {
                console.error('[DashboardContent] ❌ Erro em renderQuickStats:', e);
            }

            try {
                renderRecentActivity(userData);
            } catch (e) {
                console.error('[DashboardContent] ❌ Erro em renderRecentActivity:', e);
            }

            try {
                renderUserNotifications(userData);
            } catch (e) {
                console.error('[DashboardContent] ❌ Erro em renderUserNotifications:', e);
            }

            console.log('[DashboardContent] ✅ Widgets renderizados');
        } catch (error) {
            console.error('[DashboardContent] ❌ Erro geral em renderizar:', error.message);
        }
    }

    function renderQuickStats(user) {
        const statsEl = document.getElementById('quickStats');
        if (!statsEl) return;

        const permissao = (user.funcao && typeof user.funcao === 'string') ? user.funcao : (user.permissao || 'Usuário');
        const nome = (user.nome && typeof user.nome === 'string') ? user.nome : 'Usuário Anônimo';

        statsEl.innerHTML = `
            <div class="stat">
                <span class="label">Seu Nível:</span>
                <span class="value">${permissao}</span>
            </div>
            <div class="stat">
                <span class="label">Bem-vindo:</span>
                <span class="value">${nome}</span>
            </div>
        `;
    }

    function renderRecentActivity(user) {
        const activityEl = document.getElementById('recentActivity');
        if (!activityEl) return;

        // ✅ Defensivo
        const activities = Array.isArray(user.activities) ? user.activities : [];
        const html = activities.slice(0, 5).map(a => `
            <div class="activity-item">
                <span>${a.descricao || 'Atividade'}</span>
                <time>${a.data || 'Data desconhecida'}</time>
            </div>
        `).join('');

        activityEl.innerHTML = html || '<p>Nenhuma atividade recente</p>';
    }

    function renderUserNotifications(user) {
        const noteEl = document.getElementById('userNotifications');
        if (!noteEl) return;

        // ✅ Defensivo
        const notifications = Array.isArray(user.notifications) ? user.notifications : [];
        const unreadCount = notifications.filter(n => !n.lido).length;

        noteEl.innerHTML = `
            <span class="count">${unreadCount}</span>
            <span class="label">Notificações</span>
        `;
    }

    function clearWidgets() {
        try {
            document.getElementById('quickStats').innerHTML = '';
            document.getElementById('recentActivity').innerHTML = '';
            document.getElementById('userNotifications').innerHTML = '0';
        } catch (e) {}
    }

    // ✅ Listener PASSIVO
    mgr.on('userDataChanged', (data) => {
        console.log('[DashboardContent] 📢 Renderizando conteúdo');

        // ✅ Defensivo: aceitar múltiplos formatos
        const userData = data && typeof data === 'object' ? (data.user || data.usuario) : null;
        renderDashboardWidgets(userData);
    });

    // ✅ Limpar ao expirar
    mgr.on('sessionExpired', clearWidgets);

    console.log('[DashboardContent] ✅ Listener registrado');
})();

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * CHECKLIST: Como implementar listeners defensivos em páginas existentes
 * ═══════════════════════════════════════════════════════════════════════════
 * 
 * Em dashboard.html, protocolo.html, estoque.html, etc:
 * 
 * ❌ NÃO FAZER:
 *   sessionMgr.onUserDataChanged((dados) => {
 *       fetch API (❌ Proibido!)
 *       renovarSessao() (❌ Proibido!)
 *       if (!dados) redirect (❌ Proibido!)
 *   });
 * 
 * ✅ FAZER:
 *   sessionMgr.onUserDataChanged((dados) => {
 *       try {
 *           const user = dados?.user || dados?.usuario;
 *           if (!user) return;
 *           renderizar(user);
 *       } catch (e) {
 *           console.error("Erro no listener:", e);
 *       }
 *   });
 * 
 * ✅ PADRÃO:
 *   • Defensivo: verificar tipo, verificar null
 *   • Isolado: try/catch por listener
 *   • Passivo: apenas render, sem fetch
 *   • Reativo: escuta evento, renderiza
 * 
 * ═══════════════════════════════════════════════════════════════════════════
 */
