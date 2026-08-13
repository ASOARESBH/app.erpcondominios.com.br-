/**
 * Adaptador de escopo do Service Worker.
 * O navegador exige este arquivo na raiz para que o Portal do Morador,
 * localizado em /frontend/, permaneça dentro do escopo do PWA.
 * A implementação organizada está em /PWA/firebase-messaging-sw.js.
 */
importScripts('/PWA/firebase-messaging-sw.js');
