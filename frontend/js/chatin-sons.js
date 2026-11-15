/**
 * ERP CONDOMÍNIOS — sons originais do Chat-In (Web Audio API; sem assets de terceiros).
 * Os timbres são síntese local, nunca enviados ao servidor.
 */
(function (global) {
    'use strict';
    let context = null;
    let unlockPromise = null;

    function contexto() {
        if (context) return context;
        const AudioContextCtor = global.AudioContext || global.webkitAudioContext;
        if (!AudioContextCtor) return null;
        try { context = new AudioContextCtor(); } catch (_) { return null; }
        return context;
    }

    async function desbloquear() {
        const ctx = contexto();
        if (!ctx) return false;
        if (ctx.state === 'running') return true;
        if (!unlockPromise) unlockPromise = ctx.resume().then(() => ctx.state === 'running').catch(() => false).finally(() => { unlockPromise = null; });
        return unlockPromise;
    }

    function nota(frequencia, inicio, duracao, volume, forma, decaimento) {
        const ctx = contexto();
        if (!ctx || ctx.state !== 'running') return false;
        const oscillator = ctx.createOscillator();
        const envelope = ctx.createGain();
        const t0 = ctx.currentTime + inicio;
        oscillator.type = forma || 'sine';
        oscillator.frequency.setValueAtTime(frequencia, t0);
        envelope.gain.setValueAtTime(0.0001, t0);
        envelope.gain.exponentialRampToValueAtTime(Math.max(0.0002, volume), t0 + 0.014);
        envelope.gain.exponentialRampToValueAtTime(0.0001, t0 + duracao * (decaimento || 0.86));
        oscillator.connect(envelope);
        envelope.connect(ctx.destination);
        oscillator.start(t0);
        oscillator.stop(t0 + duracao);
        oscillator.onended = () => { oscillator.disconnect(); envelope.disconnect(); };
        return true;
    }

    function tocar(tipo, volume = 70) {
        const escala = Math.max(0, Math.min(100, Number(volume) || 0)) / 100;
        if (escala <= 0) return false;
        const base = 0.13 * escala;
        switch (tipo) {
            case 'campainha':
                return nota(659.25, 0, 0.42, base, 'sine', 0.96)
                    && nota(523.25, 0.22, 0.56, base * 0.86, 'sine', 0.97);
            case 'suave':
                return nota(880, 0, 0.18, base * 0.56, 'sine', 0.9)
                    && nota(1174.66, 0.16, 0.22, base * 0.42, 'sine', 0.92)
                    && nota(1567.98, 0.33, 0.3, base * 0.28, 'sine', 0.94);
            case 'cutucar':
                return [0, 0.17, 0.34].every((t, i) => nota(i === 1 ? 1046.5 : 932.33, t, 0.105, base * 0.92, 'triangle', 0.7));
            case 'classico':
            default:
                // Duas notas ascendentes e curtas, timbre pop próprio do ERP.
                return nota(587.33, 0, 0.16, base, 'triangle', 0.72)
                    && nota(783.99, 0.12, 0.2, base * 0.86, 'triangle', 0.78);
        }
    }

    global.ChatInSons = { desbloquear, tocar };
    if (global.document) {
        const liberar = () => { desbloquear(); };
        global.document.addEventListener('pointerdown', liberar, { passive: true });
        global.document.addEventListener('keydown', liberar);
    }
})(window);
