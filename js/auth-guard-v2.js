/**
 * ═══════════════════════════════════════════════════════════════════════════
 * AUTH GUARD v2.0 - CORRIGIDO (NÃO faz fetch!)
 * ═══════════════════════════════════════════════════════════════════════════
 * 
 * ✅ PRINCÍPIO: SessionManagerCore já faz fetch, auth-guard apenas consulta
 * ❌ NUNCA fazer fetch aqui! SessionManagerCore é o ÚNICO ponto de controle
 * 
 * Responsabilidades:
 * • Aguardar SessionManagerCore ficar pronto
 * • Consultar estado (SEM fazer fetch!)
 * • Redirecionar se não autenticado
 * • Escutar eventos de expiração
 */

(function() {
    'use strict';

    console.log('[AuthGuard] ▶️ Inicializando');

    // Páginas que não precisam de autenticação
    const publicPages = [
        'login.html',
        'login_morador.html',
        'index.html',
        'esqueci_senha.html',
        'register.html'
    ];

    // Identificar página atual
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';

    // Se for página pública, sair
    if (publicPages.includes(currentPage)) {
        console.log('[AuthGuard] ℹ️ Página pública, pulando verificação');
        return;
    }

    console.log('[AuthGuard] 🔒 Página protegida detectada:', currentPage);

    /**
     * Verificar proteção quando SessionManager está pronto
     */
    function checkProtection() {
        // ✅ NÃO fazer fetch aqui!
        // Apenas aguardar que SessionManagerCore ficou pronto

        if (!window.sessionManager) {
            console.log('[AuthGuard] ⏳ SessionManager não disponível, aguardando...');
            setTimeout(checkProtection, 100);
            return;
        }

        const manager = window.sessionManager;

        console.log('[AuthGuard] ✅ SessionManager detectado');

        // Consultar estado (sem fazer fetch!)
        if (!manager.isLoggedIn()) {
            console.warn('[AuthGuard] ❌ Usuário não autenticado');
            sessionStorage.clear();
            window.location.href = '../login.html';
        } else {
            console.log('[AuthGuard] ✅ Usuário autenticado, permitindo acesso');

            // Disparar evento para compatibilidade com código antigo
            const event = new CustomEvent('usuarioAutenticado', {
                detail: manager.getUser()
            });
            document.dispatchEvent(event);
        }
    }

    /**
     * Iniciar verificação quando documento estiver pronto
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkProtection);
    } else {
        checkProtection();
    }

    /**
     * Escutar eventos de expiração (SessionManager emite)
     */
    function setupExpirationListener() {
        if (!window.sessionManager) {
            setTimeout(setupExpirationListener, 100);
            return;
        }

        const manager = window.sessionManager;

        // Escutar evento de expiração
        manager.on('sessionExpired', () => {
            console.log('[AuthGuard] ❌ Sessão expirada (evento recebido)');
            sessionStorage.clear();
            window.location.href = '../login.html';
        });

        console.log('[AuthGuard] ✅ Listener de expiração registrado');
    }

    setupExpirationListener();

    console.log('[AuthGuard] ✅ Inicialização concluída (SEM fetch!)');
})();
