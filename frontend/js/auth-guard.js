/**
 * Auth Guard v2.0 — ERP Condomínio
 * Protege páginas que requerem autenticação.
 *
 * CORREÇÕES v2.0:
 *   ✅ Detecta layout-base.html corretamente (pathname sem query string)
 *   ✅ Proteção anti-loop: flag _authGuardRedirecting no sessionStorage
 *   ✅ Não redireciona se já está na página de login
 *   ✅ Timeout de 10s para não bloquear o sistema em caso de API lenta
 */
(function () {
    'use strict';

    // Páginas públicas (não precisam de sessão)
    const publicPages = [
        'login.html',
        'login_morador.html',
        'login_fornecedor.html',
        'esqueci_senha.html',
        'redefinir_senha.html',
        'index.html',
        'register.html'
    ];

    // Extrair apenas o nome do arquivo sem query string
    const pathname = window.location.pathname;
    const pageFile = pathname.split('/').pop().split('?')[0];  // ← FIX: remove ?page=dashboard

    // Se for página pública, não fazer nada
    if (publicPages.includes(pageFile) || pageFile === '' || pageFile === 'frontend') {
        return;
    }

    // Proteção anti-loop: se já está redirecionando, não redirecionar novamente
    if (sessionStorage.getItem('_authGuardRedirecting') === '1') {
        sessionStorage.removeItem('_authGuardRedirecting');
        console.warn('[AuthGuard] ⚠️ Loop detectado — redirecionamento cancelado');
        return;
    }

    // URL absoluta: evita ambiguidade de path relativo
    const API_URL = window.location.origin + '/api/verificar_sessao_completa.php';

    // Timeout de 10s para não bloquear em caso de API lenta
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);

    fetch(API_URL, {
        method: 'GET',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        signal: controller.signal
    })
    .then(function(response) {
        clearTimeout(timeout);
        // HTTP 401 = não autenticado → redirecionar para login
        if (response.status === 401) {
            _redirectToLogin('401_unauthorized');
            return null;
        }
        // Outros erros HTTP (500, etc.) → não redirecionar, deixar o sistema tentar
        if (!response.ok) {
            console.warn('[AuthGuard] ⚠️ API retornou HTTP ' + response.status + ' — não redirecionando');
            return null;
        }
        return response.json();
    })
    .then(function(data) {
        if (!data) return; // já tratado acima
        if (!data.sucesso || !data.sessao_ativa) {
            _redirectToLogin('sessao_inativa');
        } else {
            console.log('[AuthGuard] ✅ Acesso autorizado —', data.usuario?.nome || 'usuário');
        }
    })
    .catch(function(error) {
        clearTimeout(timeout);
        if (error.name === 'AbortError') {
            console.warn('[AuthGuard] ⏱️ Timeout (10s) — não redirecionando');
            return;
        }
        // Erro de rede → não redirecionar (pode ser offline temporário)
        console.warn('[AuthGuard] ⚠️ Erro de rede — não redirecionando:', error.message);
    });

    function _redirectToLogin(motivo) {
        console.warn('[AuthGuard] ⛔ Acesso negado (' + motivo + '). Redirecionando para login...');
        sessionStorage.setItem('_authGuardRedirecting', '1');
        localStorage.clear();
        window.location.replace(window.location.origin + '/frontend/login.html');
    }

})();
