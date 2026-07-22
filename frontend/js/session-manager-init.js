/**
 * SESSION MANAGER INITIALIZATION - Manual bootstrap for dashboard.
 */

(function initializeSessionManagerManually() {
    'use strict';

    console.log('[SessionManagerInit] Starting manual SessionManager initialization...');

    if (typeof window.SessionManagerCore === 'undefined') {
        console.error('[SessionManagerInit] SessionManagerCore not found');
        return;
    }

    if (window.sessionManager && window.sessionManager.isInitialized) {
        console.log('[SessionManagerInit] SessionManager already initialized');
        return;
    }

    const manager = window.SessionManagerCore.getInstance();

    if (manager.isInitialized) {
        window.sessionManager = manager;
        document.dispatchEvent(new CustomEvent('sessionManagerReady', { detail: { manager: manager } }));
        return;
    }

    async function doInitialize() {
        try {
            console.log('[SessionManagerInit] Initializing SessionManager...');

            const ok = await manager.initialize();
            window.sessionManager = manager;

            if (!ok) {
                console.warn('[SessionManagerInit] Initialization returned invalid state');
                return false;
            }

            console.log('[SessionManagerInit] SessionManager initialized');

            document.dispatchEvent(new CustomEvent('sessionManagerReady', {
                detail: { manager: manager }
            }));

            return true;
        } catch (error) {
            console.error('[SessionManagerInit] Failed to initialize SessionManager:', error);
            return false;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', doInitialize);
    } else {
        doInitialize();
    }

    window.reinitializeSessionManager = async function reinitializeSessionManager() {
        return doInitialize();
    };
})();
