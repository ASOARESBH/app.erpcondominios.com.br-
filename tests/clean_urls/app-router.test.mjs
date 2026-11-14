#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sourcePath = path.join(repoRoot, 'frontend/js/app-router.js');
const source = fs.readFileSync(sourcePath, 'utf8');

const location = new URL('https://erp.test/moradores/');
const history = {
    state: null,
    replaceState(nextState, _title, url) {
        this.state = nextState;
        location.href = new URL(url, location.href).href;
    },
    pushState(nextState, _title, url) {
        this.state = nextState;
        location.href = new URL(url, location.href).href;
    }
};
const listeners = {};
globalThis.window = {
    location,
    history,
    addEventListener(name, callback) { listeners[name] = callback; }
};
globalThis.localStorage = {
    values: new Map(),
    getItem(key) { return this.values.has(key) ? this.values.get(key) : null; },
    setItem(key, value) { this.values.set(key, String(value)); },
    removeItem(key) { this.values.delete(key); }
};
globalThis.document = {
    addEventListener(name, callback) { listeners[`document:${name}`] = callback; }
};

const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const { AppRouter: router } = await import(moduleUrl);

assert.equal(router.getPageFromURL(), 'moradores', 'clean slug with trailing slash resolves');
assert.ok(source.indexOf('history.pushState') < source.indexOf('module.init()'), 'navigation history is updated before a page module reads query state');
assert.equal(router.cleanURLForPage('registro', new URLSearchParams('page=registro&aba=convidados&registro_id=42&x=1'), '#lista'), '/registro?aba=convidados&registro_id=42&x=1#lista');
assert.equal(router.cleanURLForPage('registro', new URLSearchParams('page=registro&aba=ruim&registro_id=0&x=1')), '/registro?x=1');
assert.equal(router.cleanURLForPage('moradores', new URLSearchParams('page=moradores&aba=alertas&busca=Jo%C3%A3o')), '/moradores?aba=alertas&busca=Jo%C3%A3o');

location.href = 'https://erp.test/frontend/layout-base.html?page=registro&aba=convidados&registro_id=42';
assert.equal(router.getPageFromURL(), 'registro', 'legacy page query remains supported');
router.canonicalizeCurrentURL('registro');
assert.equal(location.pathname, '/registro', 'legacy shell path canonicalizes to clean route');
assert.equal(location.search, '?aba=convidados&registro_id=42', 'tab and record deep link survive canonicalization');
assert.equal(history.state.page, 'registro');

localStorage.values.set('usuario_permissao', 'super_admin');
localStorage.values.delete('superadmin_tenant_original');
location.href = 'https://erp.test/dashboard';
assert.equal(router.getPageFromURL(), 'superadmin', 'global Super-Admin dashboard behavior is preserved');
localStorage.values.set('superadmin_tenant_original', 'tenant-opaque');
assert.equal(router.getPageFromURL(), 'dashboard', 'tenant context preserves operational dashboard');
localStorage.values.clear();

const navigations = [];
router.loadPage = (...args) => navigations.push(args);
function eventFor({ href, page, ctrlKey = false, metaKey = false, shiftKey = false, altKey = false, button = 0, targetName = '', download = false }) {
    const attrs = new Map();
    if (targetName) attrs.set('target', targetName);
    if (download) attrs.set('download', '');
    const link = {
        href,
        dataset: page ? { page } : {},
        hasAttribute(name) { return attrs.has(name); },
        getAttribute(name) { return attrs.get(name) || null; }
    };
    return {
        button, ctrlKey, metaKey, shiftKey, altKey,
        defaultPrevented: false,
        target: { closest(selector) { return selector === 'a[href]' ? link : null; } },
        prevented: false,
        preventDefault() { this.prevented = true; }
    };
}
const normalClick = eventFor({ href: 'https://erp.test/registro?aba=convidados&registro_id=42', page: 'registro' });
router.handleDocumentClick(normalClick);
assert.equal(normalClick.prevented, true);
assert.equal(navigations.length, 1);
assert.equal(navigations[0][0], 'registro');
assert.equal(navigations[0][1], true);
assert.equal(navigations[0][2].searchParams.get('aba'), 'convidados');
assert.equal(navigations[0][2].searchParams.get('registro_id'), '42');
const legacyClick = eventFor({ href: 'https://erp.test/frontend/layout-base.html?page=moradores&aba=alertas' });
router.handleDocumentClick(legacyClick);
assert.equal(legacyClick.prevented, true, 'legacy links are intercepted for in-place navigation');
assert.equal(navigations.length, 2);
assert.equal(navigations[1][0], 'moradores');
assert.equal(navigations[1][2].searchParams.get('aba'), 'alertas');
for (const nativeCase of [
    { name: 'Ctrl click', ctrlKey: true },
    { name: 'Cmd click', metaKey: true },
    { name: 'Shift click', shiftKey: true },
    { name: 'Alt click', altKey: true },
    { name: 'middle click', button: 1 },
    { name: 'new tab target', targetName: '_blank' },
    { name: 'download', download: true },
]) {
    const nativeClick = eventFor({ href: 'https://erp.test/moradores', page: 'moradores', ...nativeCase });
    router.handleDocumentClick(nativeClick);
    assert.equal(nativeClick.prevented, false, `${nativeCase.name} remains browser-native`);
}
const externalClick = eventFor({ href: 'https://external.test/help', page: 'manual' });
router.handleDocumentClick(externalClick);
assert.equal(externalClick.prevented, false, 'external links remain browser-native');
assert.equal(navigations.length, 2);

const popstateNavigation = [];
location.href = 'https://erp.test/registro?aba=convidados&registro_id=42';
const historyRouter = await import(moduleUrl).then((module) => module.AppRouter);
historyRouter.init();
historyRouter.loadPage = (...args) => popstateNavigation.push(args);
location.href = 'https://erp.test/moradores/?aba=alertas';
listeners.popstate();
assert.equal(location.pathname, '/moradores', 'popstate canonicalizes a trailing slash');
assert.equal(popstateNavigation.length, 1);
assert.equal(popstateNavigation[0][0], 'moradores');
assert.equal(popstateNavigation[0][1], false);

console.log('PASS: clean routes, legacy query compatibility, query/hash preservation, Super-Admin, popstate, and native modified clicks');
