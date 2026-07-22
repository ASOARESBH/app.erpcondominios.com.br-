/**
 * Header Profile Right - Gerenciador de Perfil no Cabeçalho
 * 
 * Responsabilidades:
 * - Injetar HTML do perfil no cabeçalho
 * - Sincronizar dados do usuário
 * - Atualizar timer de sessão
 * - Gerenciar estado do perfil
 */

class HeaderProfileRight {
    constructor() {
        this.profileContainer = null;
        this.updateInterval = null;
        this.userData = null;
        this.init();
    }

    /**
     * Inicializar o gerenciador
     */
    init() {
        // Aguardar DOM estar pronto
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setup());
        } else {
            this.setup();
        }
    }

    /**
     * Configurar o perfil no cabeçalho
     */
    setup() {
        // Encontrar o header
        const header = document.querySelector('.header');
        if (!header) {
            console.warn('[HeaderProfileRight] Header não encontrado');
            return;
        }

        // Criar container do perfil
        this.createProfileContainer();
        
        // Adicionar ao header
        header.appendChild(this.profileContainer);

        // Carregar dados do usuário
        this.loadUserData();

        // Iniciar atualização do timer
        this.startSessionTimer();
    }

    /**
     * Criar container do perfil
     */
    createProfileContainer() {
        this.profileContainer = document.createElement('div');
        this.profileContainer.className = 'header-profile-container';
        this.profileContainer.id = 'headerProfileContainer';
        this.profileContainer.innerHTML = `
            <div class="header-profile-avatar" id="headerProfileAvatar">A</div>
            <div class="header-profile-info">
                <div class="header-profile-name" id="headerProfileName">Carregando...</div>
                <div class="header-profile-role" id="headerProfileRole">Carregando...</div>
                <div class="header-profile-status" id="headerProfileStatus">
                    <span class="status-indicator"></span>
                    <span id="headerStatusText">Ativo</span>
                </div>
            </div>
            <div class="header-session-timer" id="headerSessionTimer">00:00:00</div>
        `;
    }

    /**
     * Carregar dados do usuário
     */
    async loadUserData() {
        try {
            // Tentar obter dados da sessão
            const response = await fetch('api/usuario_logado.php', {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.sucesso) {
                this.userData = data.usuario;
                this.updateProfileDisplay();
            } else {
                console.warn('[HeaderProfileRight] Erro ao carregar dados:', data.mensagem);
                this.setDefaultProfile();
            }
        } catch (error) {
            console.error('[HeaderProfileRight] Erro ao carregar usuário:', error);
            this.setDefaultProfile();
        }
    }

    /**
     * Atualizar exibição do perfil
     */
    updateProfileDisplay() {
        if (!this.userData) return;

        // Avatar - primeira letra do nome
        const avatar = document.getElementById('headerProfileAvatar');
        if (avatar) {
            const firstLetter = (this.userData.nome || 'U').charAt(0).toUpperCase();
            avatar.textContent = firstLetter;
        }

        // Nome
        const nameEl = document.getElementById('headerProfileName');
        if (nameEl) {
            nameEl.textContent = (this.userData.nome || 'Usuário').toUpperCase();
        }

        // Função/Permissão
        const roleEl = document.getElementById('headerProfileRole');
        if (roleEl) {
            const role = this.userData.funcao || this.userData.permissao || 'Usuário';
            roleEl.textContent = role.toUpperCase();
        }

        // Status
        this.updateStatusDisplay();
    }

    /**
     * Atualizar exibição do status
     */
    updateStatusDisplay() {
        const statusEl = document.getElementById('headerProfileStatus');
        const statusText = document.getElementById('headerStatusText');

        if (!statusEl || !statusText) return;

        // Determinar status
        let status = 'Ativo';
        let statusClass = 'active';

        if (this.userData.status === 'inativo') {
            status = 'Inativo';
            statusClass = 'inactive';
        } else if (this.userData.status === 'ausente') {
            status = 'Ausente';
            statusClass = 'away';
        }

        // Atualizar classe
        statusEl.className = `header-profile-status ${statusClass}`;
        statusText.textContent = status;
    }

    /**
     * Definir perfil padrão
     */
    setDefaultProfile() {
        const nameEl = document.getElementById('headerProfileName');
        if (nameEl) {
            nameEl.textContent = 'USUÁRIO';
        }

        const roleEl = document.getElementById('headerProfileRole');
        if (roleEl) {
            roleEl.textContent = 'USUÁRIO DO SISTEMA';
        }
    }

    /**
     * Iniciar timer de sessão
     */
    startSessionTimer() {
        // Obter tempo de sessão do sessionStorage
        const sessionStartTime = sessionStorage.getItem('sessionStartTime');
        
        if (!sessionStartTime) {
            // Se não houver, usar tempo atual
            sessionStorage.setItem('sessionStartTime', Date.now());
        }

        // Atualizar timer a cada segundo
        this.updateInterval = setInterval(() => {
            this.updateSessionTimer();
        }, 1000);

        // Atualizar imediatamente
        this.updateSessionTimer();
    }

    /**
     * Atualizar timer de sessão
     */
    updateSessionTimer() {
        const timerEl = document.getElementById('headerSessionTimer');
        if (!timerEl) return;

        const sessionStartTime = sessionStorage.getItem('sessionStartTime');
        if (!sessionStartTime) return;

        // Calcular tempo decorrido
        const elapsed = Math.floor((Date.now() - parseInt(sessionStartTime)) / 1000);

        // Converter para HH:MM:SS
        const hours = Math.floor(elapsed / 3600);
        const minutes = Math.floor((elapsed % 3600) / 60);
        const seconds = elapsed % 60;

        const timeString = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

        timerEl.textContent = timeString;

        // Avisar se sessão está perto do fim (ex: 55 minutos)
        if (elapsed > 3300 && elapsed < 3360) { // Entre 55 e 56 minutos
            this.warnSessionExpiring();
        }
    }

    /**
     * Avisar que a sessão está perto de expirar
     */
    warnSessionExpiring() {
        const statusEl = document.getElementById('headerProfileStatus');
        if (statusEl && !statusEl.classList.contains('warning')) {
            statusEl.classList.add('warning');
            console.warn('[HeaderProfileRight] Sessão expirando em breve');
        }
    }

    /**
     * Atualizar dados do usuário (chamado por outros scripts)
     */
    updateUserData(userData) {
        this.userData = userData;
        this.updateProfileDisplay();
    }

    /**
     * Obter dados do usuário
     */
    getUserData() {
        return this.userData;
    }

    /**
     * Limpar recursos
     */
    destroy() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
        }
        if (this.profileContainer) {
            this.profileContainer.remove();
        }
    }
}

// Instanciar globalmente
const headerProfileRight = new HeaderProfileRight();

// Expor para uso global
window.headerProfileRight = headerProfileRight;
