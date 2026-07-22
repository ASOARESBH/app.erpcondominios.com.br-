/**
 * USER DISPLAY v2.0 - Dynamic Header with Session Countdown
 * 
 * Features:
 * - Display full name (split from single name field)
 * - Show initials automatically (e.g., João Silva → JS)
 * - Real-time session countdown timer
 * - Auto-logout when session expires
 * - Integration with SessionManagerCore
 */
(function () {
    'use strict';

    let inicializado = false;
    let countdownInterval = null;

    async function inicializar() {
        if (inicializado) return; // Prevent multiple initializations
        inicializado = true;

        console.log('[UserDisplay] Inicializando...');

        if (typeof window.SessionManagerCore === 'undefined') {
            console.warn('[UserDisplay] SessionManagerCore indisponivel nesta pagina');
            return;
        }

        // Get SessionManager instance
        const sessionManager = SessionManagerCore.getInstance();

        // Fetch initial session data
        const data = await sessionManager.getSessionData();

        if (!data.sucesso || !data.sessao_ativa) {
            console.warn('[UserDisplay] ⚠️ Sessão inativa');
            return;
        }

        // Extract user data
        const usuario = data.usuario || {};
        const nomeCompleto = usuario.nome || 'Usuário';

        // Split name into parts for initials
        const partesNome = nomeCompleto.trim().split(' ');
        const primeiroNome = partesNome[0] || '';
        const sobrenome = partesNome.length > 1 ? partesNome[partesNome.length - 1] : '';

        // Update name in header
        const topUserName = document.getElementById('topUserName') ||
            document.querySelector('.header-user-name');
        if (topUserName) {
            topUserName.innerText = nomeCompleto;
        }

        // Update avatar with initials
        const topUserAvatar = document.getElementById('topUserAvatar') ||
            document.querySelector('.user-avatar');
        if (topUserAvatar) {
            const inicialPrimeiro = primeiroNome.charAt(0).toUpperCase();
            const inicialSobrenome = sobrenome.charAt(0).toUpperCase();

            const iniciais = inicialPrimeiro + (inicialSobrenome || '');
            topUserAvatar.innerText = iniciais || 'U';
        }

        // Start session countdown
        const tempoRestante = data.tempo_restante_segundos || 0;
        iniciarContadorSessao(tempoRestante);

        // Listen for session changes
        sessionManager.onUserDataChanged((dados) => {
            if (dados.user) {
                const nome = dados.user.nome || 'Usuário';
                if (topUserName) {
                    topUserName.innerText = nome;
                }

                // Update avatar if name changed
                const partes = nome.trim().split(' ');
                const primeiro = partes[0] || '';
                const ultimo = partes.length > 1 ? partes[partes.length - 1] : '';
                const novasIniciais = primeiro.charAt(0).toUpperCase() +
                    (ultimo.charAt(0).toUpperCase() || '');

                if (topUserAvatar) {
                    topUserAvatar.innerText = novasIniciais || 'U';
                }
            }
        });

        console.log('[UserDisplay] ✅ Inicializado com sucesso');
    }

    /**
     * Initialize session countdown timer
     * @param {number} segundos - Remaining time in seconds
     */
    function iniciarContadorSessao(segundos) {
        let tempo = parseInt(segundos) || 0;

        // Create countdown element
        const contadorElemento = document.createElement('span');
        contadorElemento.id = 'sessionCountdown';
        contadorElemento.style.fontSize = '12px';
        contadorElemento.style.display = 'block';
        contadorElemento.style.opacity = '0.7';
        contadorElemento.style.marginTop = '2px';

        // Find user info container and append countdown
        const userInfo = document.querySelector('.user-info-mini');
        if (userInfo) {
            // Remove existing countdown if any
            const existing = document.getElementById('sessionCountdown');
            if (existing) existing.remove();

            userInfo.appendChild(contadorElemento);
        } else {
            console.warn('[UserDisplay] ⚠️ Elemento .user-info-mini não encontrado');
            return;
        }

        // Clear any existing countdown interval
        if (countdownInterval) {
            clearInterval(countdownInterval);
        }

        // Update countdown every second
        countdownInterval = setInterval(() => {
            if (tempo <= 0) {
                clearInterval(countdownInterval);
                contadorElemento.innerText = 'Sessão expirada';

                console.log('[UserDisplay] ⏰ Sessão expirada - executando logout...');

                // Auto-logout when session expires
                const sessionManager = SessionManagerCore.getInstance();
                sessionManager.logout();
                return;
            }

            tempo--;

            // Format time as MM:SS
            const minutos = Math.floor(tempo / 60);
            const segundosRestantes = tempo % 60;

            contadorElemento.innerText =
                `Sessão: ${String(minutos).padStart(2, '0')}:${String(segundosRestantes).padStart(2, '0')}`;

            // Warning color when less than 5 minutes
            if (tempo <= 300) {
                contadorElemento.style.color = '#fca5a5'; // Light red
            }

        }, 1000);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            inicializar().catch((err) => {
                console.error('[UserDisplay] Falha ao inicializar:', err);
            });
        });
    } else {
        inicializar().catch((err) => {
            console.error('[UserDisplay] Falha ao inicializar:', err);
        });
    }
})();
