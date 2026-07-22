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
    const publicPages = ['login.html', 'login_morador.html', 'index.html', 'esqueci_senha.html', 'register.html'];

    // Identificar página atual
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';

    // Se for página pública, sair
    if (publicPages.includes(currentPage)) {
        console.log('[AuthGuard] ℹ️ Página pública, pulando verificação');
        return;
    }

    console.log('[AuthGuard] 🔒 Página protegida detectada:', currentPage);

    /**
     * Função para verificar proteção (chamada quando SessionManager está pronto)
     */
    function checkProtection() {
        // Verificar se SessionManager existe e está inicializado
        if (!window.sessionManager) {
            console.log('[AuthGuard] ⏳ SessionManager não disponível, aguardando...');
            setTimeout(checkProtection, 100);
            return;
        }

        const manager = window.sessionManager;

        console.log('[AuthGuard] ✅ SessionManager detectado');

        // ✅ NÃO fazer fetch aqui! Apenas consultar estado.
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
            } else {
                // Muitas tentativas falhadas, redirecionar para login
                sessionStorage.clear();
                window.location.replace('login.html');
            }
        });
    }
    
    // Verificar sessão ao carregar a página
    verificarSessao(false);
    
    // Verificar sessão periodicamente (a cada 2 minutos)
    setInterval(function() {
        verificarSessao(true);
    }, 120000); // 120 segundos = 2 minutos
    
    // Resetar contador de tentativas quando houver interação do usuário
    ['click', 'keypress', 'mousemove', 'scroll'].forEach(evento => {
        document.addEventListener(evento, function() {
            sessionStorage.setItem('tentativas_verificacao', '0');
        }, { once: true });
    });
    
})();

