/**
 * SERVICE WORKER - Console de Acesso PWA
 * Service worker simples para permitir instalação do PWA
 * Sem cache para garantir sempre a versão mais recente
 */

const CACHE_NAME = 'console-acesso-v1';

// Instalação do Service Worker
self.addEventListener('install', (event) => {
    console.log('[SW] Service Worker instalado');
    // Ativa imediatamente sem esperar
    self.skipWaiting();
});

// Ativação do Service Worker
self.addEventListener('activate', (event) => {
    console.log('[SW] Service Worker ativado');
    // Assume controle imediatamente
    event.waitUntil(clients.claim());
});

// Interceptação de requisições (sem cache)
self.addEventListener('fetch', (event) => {
    // Sempre busca da rede (sem cache)
    event.respondWith(
        fetch(event.request)
            .catch((error) => {
                console.error('[SW] Erro ao buscar:', error);
                // Retorna resposta de erro
                return new Response('Offline - Verifique sua conexão', {
                    status: 503,
                    statusText: 'Service Unavailable',
                    headers: new Headers({
                        'Content-Type': 'text/plain'
                    })
                });
            })
    );
});

// Mensagens do cliente
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
