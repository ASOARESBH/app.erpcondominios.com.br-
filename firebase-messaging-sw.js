/**
 * ============================================================
 * SERVICE WORKER — Portal do Morador PWA
 * Firebase Cloud Messaging + Cache Offline
 * Credenciais: carregadas dinamicamente via api_pwa_sw_config.php
 * ============================================================
 */

// ── IMPORTAR SDK FIREBASE PARA SERVICE WORKER ──
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js');

// ── CONFIGURAÇÃO DINÂMICA — lida do banco via API ──
// Define self.PWA_CONFIG com as credenciais Firebase sem hardcode
importScripts('/api/api_pwa_sw_config.php');

const FIREBASE_CONFIG = (self.PWA_CONFIG && self.PWA_CONFIG.firebase) ? self.PWA_CONFIG.firebase : {};

// ── VERSÃO DO CACHE — dinâmica ou fallback ──
const CACHE_VERSION = (self.PWA_CONFIG && self.PWA_CONFIG.cacheVersion)
    ? self.PWA_CONFIG.cacheVersion
    : 'portal-morador-v1';
const CACHE_STATIC  = `${CACHE_VERSION}-static`;
const CACHE_DYNAMIC = `${CACHE_VERSION}-dynamic`;

// Recursos que sempre devem estar em cache (shell do app)
const STATIC_ASSETS = [
    '/frontend/portal_morador.html',
    '/assets/css/app.css',
];

// Verifica se credenciais são reais (não placeholder)
const _firebaseOk = FIREBASE_CONFIG.apiKey
    && FIREBASE_CONFIG.projectId
    && !FIREBASE_CONFIG.apiKey.startsWith('SUBSTITUA')
    && self.PWA_CONFIG && self.PWA_CONFIG.firebaseConfigurado;

// Inicializar Firebase apenas se credenciais disponíveis e válidas
let messaging = null;
if (_firebaseOk) {
    firebase.initializeApp(FIREBASE_CONFIG);
    messaging = firebase.messaging();
    console.log('[SW] Firebase inicializado. Projeto:', FIREBASE_CONFIG.projectId);
} else {
    console.warn('[SW] Firebase não configurado — push desativado. Configure em Portal PWA → Central PWA.');
}

// ============================================================
// INSTALAÇÃO — pré-cache dos recursos estáticos
// ============================================================
self.addEventListener('install', (event) => {
    console.log('[SW] Instalando Service Worker v1...');
    event.waitUntil(
        caches.open(CACHE_STATIC).then((cache) => {
            console.log('[SW] Pré-cacheando recursos estáticos...');
            return cache.addAll(STATIC_ASSETS).catch((err) => {
                console.warn('[SW] Erro ao pré-cachear (não crítico):', err);
            });
        })
        // Não chama self.skipWaiting() aqui: o novo worker deve permanecer em
        // "waiting" até o usuário clicar em "Atualizar" no banner (que envia
        // SKIP_WAITING via postMessage — ver listener de 'message' abaixo).
        // Ativar sozinho durante a instalação corria com esse fluxo manual e
        // fazia o banner de atualização reaparecer mesmo após o clique.
    );
});

// ============================================================
// ATIVAÇÃO — limpar caches antigos
// ============================================================
self.addEventListener('activate', (event) => {
    console.log('[SW] Ativando Service Worker...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name.startsWith('portal-morador-') && name !== CACHE_STATIC && name !== CACHE_DYNAMIC)
                    .map((name) => {
                        console.log('[SW] Removendo cache antigo:', name);
                        return caches.delete(name);
                    })
            );
        }).then(() => {
            console.log('[SW] Ativado. Assumindo controle de todos os clientes...');
            return self.clients.claim();
        })
    );
});

// ============================================================
// FETCH — Estratégia: Network First com fallback para Cache
// ============================================================
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Ignorar requisições de API (sempre buscar da rede)
    if (url.pathname.startsWith('/api/') || url.pathname.includes('api_')) {
        return; // Deixa o browser lidar normalmente
    }

    // Ignorar requisições não-GET
    if (event.request.method !== 'GET') {
        return;
    }

    // Ignorar extensões de browser e chrome-extension
    if (url.protocol === 'chrome-extension:' || url.protocol === 'moz-extension:') {
        return;
    }

    event.respondWith(
        // Tentar rede primeiro
        fetch(event.request)
            .then((networkResponse) => {
                // Cachear resposta dinâmica (apenas recursos do mesmo domínio)
                if (networkResponse.ok && url.origin === self.location.origin) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_DYNAMIC).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return networkResponse;
            })
            .catch(() => {
                // Rede falhou — tentar cache
                return caches.match(event.request).then((cachedResponse) => {
                    if (cachedResponse) {
                        console.log('[SW] Servindo do cache (offline):', event.request.url);
                        return cachedResponse;
                    }
                    // Fallback para página offline se for navegação HTML
                    if (event.request.headers.get('accept')?.includes('text/html')) {
                        return caches.match('/frontend/portal_morador.html');
                    }
                    return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
                });
            })
    );
});

// ============================================================
// PUSH NOTIFICATIONS — Receber mensagens em background
// ============================================================
if (messaging) messaging.onBackgroundMessage((payload) => {
    console.log('[SW] Mensagem recebida em background:', payload);

    const { notification, data } = payload;

    // Dados da notificação
    const titulo   = notification?.title  || data?.title  || 'Portal do Morador';
    const corpo    = notification?.body   || data?.body   || 'Você tem uma nova mensagem.';
    const icone    = notification?.icon   || '/ico/icon-192x192.png';
    const badge    = '/ico/icon-72x72.png';
    const tipo     = data?.tipo           || 'geral';
    const url_dest = data?.url            || '/frontend/portal_morador.html';
    const tag      = data?.tag            || `notif-${tipo}-${Date.now()}`;

    // Definir cor do badge por tipo
    const CORES_TIPO = {
        visitante:    '#22c55e',  // verde
        inadimplencia:'#ef4444', // vermelho
        comunicado:   '#2563eb', // azul
        aviso:        '#f59e0b', // amarelo
        os:           '#f97316', // laranja
        geral:        '#6366f1'  // roxo
    };

    const opcoes = {
        body:    corpo,
        icon:    icone,
        badge:   badge,
        tag:     tag,
        renotify: true,
        requireInteraction: tipo === 'inadimplencia' || tipo === 'urgente',
        vibrate: [200, 100, 200],
        data: {
            url:  url_dest,
            tipo: tipo,
            timestamp: Date.now()
        },
        actions: _obterAcoes(tipo)
    };

    return self.registration.showNotification(titulo, opcoes);
});

// ============================================================
// CLIQUE NA NOTIFICAÇÃO — Abrir/focar o portal
// ============================================================
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notificação clicada:', event.notification.tag, 'ação:', event.action);
    event.notification.close();

    const urlDestino = event.notification.data?.url || '/frontend/portal_morador.html';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Verificar se já existe uma janela aberta com o portal
            for (const client of clientList) {
                if (client.url.includes('portal_morador') && 'focus' in client) {
                    client.postMessage({
                        type: 'NOTIFICATION_CLICK',
                        url:  urlDestino,
                        tipo: event.notification.data?.tipo
                    });
                    return client.focus();
                }
            }
            // Abrir nova janela
            if (clients.openWindow) {
                return clients.openWindow(urlDestino);
            }
        })
    );
});

// ============================================================
// FECHAR NOTIFICAÇÃO — Registrar dismiss
// ============================================================
self.addEventListener('notificationclose', (event) => {
    console.log('[SW] Notificação descartada:', event.notification.tag);
});

// ============================================================
// MENSAGENS DO CLIENTE — Comunicação bidirecional
// ============================================================
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data?.type === 'GET_VERSION') {
        event.ports[0]?.postMessage({ version: CACHE_VERSION });
    }
});

// ============================================================
// HELPERS
// ============================================================
function _obterAcoes(tipo) {
    const acoes = {
        visitante: [
            { action: 'ver', title: '👁 Ver Visitante', icon: '/ico/icon-72x72.png' },
            { action: 'fechar', title: '✕ Fechar' }
        ],
        inadimplencia: [
            { action: 'ver', title: '💰 Ver Débito', icon: '/ico/icon-72x72.png' },
            { action: 'fechar', title: '✕ Fechar' }
        ],
        os: [
            { action: 'ver', title: '🔧 Ver Chamado', icon: '/ico/icon-72x72.png' },
            { action: 'fechar', title: '✕ Fechar' }
        ],
        comunicado: [
            { action: 'ver', title: '📢 Ler Comunicado', icon: '/ico/icon-72x72.png' },
            { action: 'fechar', title: '✕ Fechar' }
        ]
    };
    return acoes[tipo] || [
        { action: 'ver', title: '👁 Ver', icon: '/ico/icon-72x72.png' },
        { action: 'fechar', title: '✕ Fechar' }
    ];
}
