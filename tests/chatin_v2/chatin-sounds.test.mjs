import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

class FakeAudioContext {
    constructor() { this.state = 'suspended'; this.currentTime = 10; this.notes = []; this.destination = {}; FakeAudioContext.last = this; }
    async resume() { this.state = 'running'; }
    createOscillator() {
        const note = { frequency: { setValueAtTime(value, time) { note.frequencyHz = value; note.startAt = time; } }, connect() {}, start(time) { note.startedAt = time; }, stop(time) { note.stoppedAt = time; }, disconnect() {} };
        this.notes.push(note);
        return note;
    }
    createGain() {
        return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} };
    }
}

const source = fs.readFileSync(new URL('../../frontend/js/chatin-sons.js', import.meta.url), 'utf8');
function createSons() {
    const listeners = [];
    const window = {
        AudioContext: FakeAudioContext,
        document: { addEventListener: (...args) => listeners.push(args) },
    };
    vm.runInNewContext(source, { window, document: window.document, setTimeout, clearTimeout });
    return { sons: window.ChatInSons, window, listeners };
}

test('AudioContext só toca depois do gesto de desbloqueio', async () => {
    const { sons, window, listeners } = createSons();
    assert.equal(listeners.some(([event]) => event === 'pointerdown'), true);
    assert.equal(listeners.some(([event]) => event === 'keydown'), true);
    assert.equal(sons.tocar('classico', 70), false);
    assert.equal(await sons.desbloquear(), true);
    assert.equal(sons.tocar('classico', 70), true);
    assert.equal(window.ChatInSons, sons);
});

test('gera quatro timbres locais com contagem e duração abaixo de 800 ms', async () => {
    const { sons } = createSons();
    await sons.desbloquear();
    const ctx = await sons.desbloquear();
    assert.equal(ctx, true);
    const audioContext = (() => {
        // O shim guarda os contextos criados; a instância é obtida sem expor APIs externas.
        return FakeAudioContext.last;
    })();
    assert.ok(audioContext);
    const before = audioContext.notes.length;
    assert.equal(sons.tocar('classico', 70), true);
    assert.equal(sons.tocar('campainha', 70), true);
    assert.equal(sons.tocar('suave', 70), true);
    assert.equal(sons.tocar('cutucar', 70), true);
    assert.equal(sons.tocar('classico', 0), false);
    const notes = audioContext.notes.slice(before);
    assert.equal(notes.length, 10);
    for (const note of notes) assert.ok(note.stoppedAt - note.startedAt <= 0.8);
    assert.ok(Math.max(...notes.map(note => note.stoppedAt)) - Math.min(...notes.map(note => note.startedAt)) <= 0.8);
    assert.ok(new Set(notes.map(note => note.frequencyHz)).size >= 5);
});
