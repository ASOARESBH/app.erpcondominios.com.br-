#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const navigations = [];
const originalLog = console.log;

class Control {
    constructor(page) {
        this.dataset = { page };
        this.listeners = new Map();
    }

    addEventListener(name, fn) {
        const current = this.listeners.get(name) || [];
        current.push(fn);
        this.listeners.set(name, current);
    }

    removeEventListener(name, fn) {
        this.listeners.set(name, (this.listeners.get(name) || []).filter(item => item !== fn));
    }

    dispatch(name, event = {}) {
        const dispatched = {
            key: event.key,
            prevented: false,
            preventDefault() { this.prevented = true; }
        };
        for (const fn of this.listeners.get(name) || []) fn(dispatched);
        return dispatched;
    }
}

const configCards = [new Control('usuarios'), new Control('sistema')];
const configTabs = [new Control('empresa'), new Control('usuarios')];
const adminCards = [new Control('protocolos'), new Control('eventos')];
const adminTabs = [new Control('protocolos'), new Control('contratos')];

const controlsBySelector = new Map([
    ['.page-configuracao .page-card.interactive[data-page]', configCards],
    ['.page-configuracao .tabs .tab-button[data-page]', configTabs],
    ['.page-administrativa .page-card[data-page]', adminCards],
    ['.page-administrativa [data-page]', [...adminTabs, ...adminCards]]
]);

globalThis.window = {
    AppRouter: {
        loadPage(page) { navigations.push(page); }
    }
};
globalThis.document = {
    querySelectorAll(selector) { return controlsBySelector.get(selector) || []; }
};

async function loadModule(relativePath) {
    const source = fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
    const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
    return import(moduleUrl);
}

try {
    console.log = () => {};

    const config = await loadModule('frontend/js/pages/configuracao.js');
    config.init();
    configCards[0].dispatch('click');
    assert.equal(navigations.at(-1), 'usuarios', 'Configuração: clique no cartão abre o módulo');
    const tabClick = configTabs[0].dispatch('click');
    assert.equal(tabClick.prevented, true, 'Configuração: clique na aba previne ação padrão');
    assert.equal(navigations.at(-1), 'empresa', 'Configuração: aba encaminha a rota');
    const enter = configCards[1].dispatch('keydown', { key: 'Enter' });
    assert.equal(enter.prevented, true);
    assert.equal(navigations.at(-1), 'sistema', 'Configuração: Enter continua funcionando');
    configCards[0].dispatch('keydown', { key: ' ' });
    assert.equal(navigations.at(-1), 'usuarios', 'Configuração: Espaço continua funcionando');
    config.destroy();
    const callsAfterConfigDestroy = navigations.length;
    configCards[0].dispatch('click');
    configTabs[0].dispatch('click');
    assert.equal(navigations.length, callsAfterConfigDestroy, 'Configuração: destroy remove handlers');

    const admin = await loadModule('frontend/js/pages/administrativa.js');
    admin.init();
    adminCards[0].dispatch('click');
    assert.equal(navigations.at(-1), 'protocolos', 'Administrativo: clique no cartão abre o módulo');
    adminTabs[1].dispatch('click');
    assert.equal(navigations.at(-1), 'contratos', 'Administrativo: clique na aba encaminha a rota');
    const adminEnter = adminCards[1].dispatch('keydown', { key: 'Enter' });
    assert.equal(adminEnter.prevented, true);
    assert.equal(navigations.at(-1), 'eventos', 'Administrativo: Enter continua funcionando');
    admin.destroy();
    const callsAfterAdminDestroy = navigations.length;
    adminCards[0].dispatch('click');
    adminTabs[1].dispatch('click');
    assert.equal(navigations.length, callsAfterAdminDestroy, 'Administrativo: destroy remove handlers');

    originalLog('PASS: clique, abas, teclado e cleanup dos hubs Configuração/Administrativo');
} finally {
    console.log = originalLog;
}
