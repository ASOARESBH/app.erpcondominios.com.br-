/**
 * Global Configuration
 * Detects the base path of the application to ensure assets are loaded correctly
 * regardless of the deployment folder (root or subdirectory).
 * 
 * CORRIGIDO: 13/02/2026 - Usa apenas window.location.origin
 * para evitar duplicação de path em servidores compartilhados
 */
(function () {
    'use strict';

    // ✅ CORREÇÃO: Usar apenas window.location.origin + '/'
    // Isso é independente do pathname do servidor
    const basePath = window.location.origin + '/';

    window.APP_BASE_PATH = basePath;
    console.log('✅ APP_BASE_PATH detected:', window.APP_BASE_PATH);

})();
