/**
 * Auth Guard
 * Protege páginas que requerem autenticação.
 *
 * ✅ PRINCÍPIO: Usa URLs absolutas (window.location.origin) para evitar
 * ambiguidade de path relativo que causa /frontend/frontend/ ou similar.
 */
(function () {
    'use strict';

    // Não verificar na página de login ou recuperação de senha
    const publicPages = ['login.html', 'esqueci_senha.html', 'redefinir_senha.html', 'index.html'];
    const path = window.location.pathname;
    const pageObj = path.split('/').pop();

    // Se for página pública, não fazer nada
    if (publicPages.includes(pageObj) || pageObj === '') {
        return;
    }

    // ✅ URL absoluta: elimina ambiguidade de path relativo
    const API_URL = window.location.origin + '/api/verificar_sessao_completa.php';

    // Verificar sessão
    fetch(API_URL, {
        method: 'GET',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' }
    })
        .then(response => response.json())
        .then(data => {
            if (!data.sucesso || !data.sessao_ativa) {
                console.warn('⛔ Acesso negado. Redirecionando para login...');
                localStorage.clear();
                sessionStorage.clear();
                // ✅ URL absoluta: elimina /frontend/frontend/
                window.location.replace(window.location.origin + '/frontend/login.html');
            } else {
                console.log('✅ Acesso autorizado');
            }
        })
        .catch(error => {
            console.error('Erro ao verificar autenticação:', error);
        });
})();
