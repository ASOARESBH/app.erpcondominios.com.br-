/**
 * Legacy Page Redirect - Auto-redirect para layout-base.html
 * Inserir no topo das páginas antigas para redirecionar automaticamente
 * 
 * Uso:
 * <script>
 *   const PAGE_NAME = 'dashboard'; // Nome da página sem extensão
 * </script>
 * <script src="js/legacy-redirect.js"></script>
 */

(function () {
    'use strict';

    // Verificar se PAGE_NAME foi definido
    if (typeof PAGE_NAME === 'undefined') {
        console.error('[LegacyRedirect] PAGE_NAME não definido. Defina antes de carregar este script.');
        return;
    }

    // Verificar se já estamos no layout-base.html
    const currentPage = window.location.pathname.split('/').pop();
    if (currentPage === 'layout-base.html') {
        console.log('[LegacyRedirect] Já está no layout-base, ignorando redirect.');
        return;
    }

    // Redirecionar para layout-base.html com parâmetro de página
    const newUrl = `layout-base.html?page=${PAGE_NAME}`;
    console.log(`[LegacyRedirect] Redirecionando ${currentPage} → ${newUrl}`);
    window.location.href = newUrl;
})();
