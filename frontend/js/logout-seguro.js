/**
 * FLUXO DE LOGOUT SEGURO
 * Gerencia a intercepção de cliques e a exibição do modal de confirmação.
 */
(function () {
    'use strict';

    function setupLogoutModal() {
        // 1. Criar Modal se não existir
        if (!document.getElementById('modalLogout')) {
            const modalHTML = `
                <div class="modal-logout-overlay" id="modalLogout">
                    <div class="modal-logout-content">
                        <div class="modal-logout-icon">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                        <h3 class="modal-logout-title">Encerrar Sessão?</h3>
                        <p class="modal-logout-text">Sua sessão será encerrada com segurança e seus dados locais serão limpos.</p>
                        <div class="modal-logout-buttons">
                            <button class="btn-modal btn-modal-cancel" id="btnCancelLogout">Cancelar</button>
                            <button class="btn-modal btn-modal-confirm" id="btnConfirmLogout">Sair Agora</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);

            // Evento Cancelar
            document.getElementById('btnCancelLogout').onclick = () => {
                const modal = document.getElementById('modalLogout');
                if (modal) {
                    modal.classList.remove('show');
                    setTimeout(() => modal.style.display = 'none', 300);
                }
            };

            // Evento Confirmar (Cérebro do Logout)
            document.getElementById('btnConfirmLogout').onclick = () => {
                const btn = document.getElementById('btnConfirmLogout');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saindo...';

                // Chamar o limpador central do SessaoManager
                if (window.sessaoManager && typeof window.sessaoManager.finalizarSessao === 'function') {
                    window.sessaoManager.finalizarSessao();
                } else {
                    // Fallback se o manager não estiver pronto
                    localStorage.clear();
                    sessionStorage.clear();
                    window.location.href = window.location.origin + '/frontend/login.html';
                }
            };
        }

        // 2. Delegação de Eventos para Intercepção Global
        // Captura cliques em qualquer elemento que pareça um logout
        document.addEventListener('click', (e) => {
            const logoutTrigger = e.target.closest('#btn-logout, .nav-link-logout, [onclick*="fazerLogout"], .logout-button');

            if (logoutTrigger) {
                e.preventDefault();
                e.stopImmediatePropagation(); // Impedir que outros scripts disparem (como o inline fazerLogout)

                const modal = document.getElementById('modalLogout');
                if (modal) {
                    modal.style.display = 'flex';
                    setTimeout(() => modal.classList.add('show'), 10);
                }
            }
        }, true); // UseCapture para interceptar antes de outros handlers
    }

    // Inicializar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupLogoutModal);
    } else {
        setupLogoutModal();
    }
})();
