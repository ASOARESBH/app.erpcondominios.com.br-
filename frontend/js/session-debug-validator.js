/**
 * =====================================================
 * VALIDAÇÃO DE CORREÇÃO - Loop Infinito de Requisições
 * =====================================================
 * 
 * Classe para validação pós-correção do sistema.
 * Verifica se não há loops agressivos ou requisições concorrentes.
 */

class SessionDebugValidator {
    constructor() {
        this.requestLog = [];
        this.maxRequestsPerMinute = 2; // Máximo aceitável por minuto (relacionado a sessão)
        this.startTime = Date.now();
    }

    /**
     * Iniciar interceptação de requisições
     */
    startMonitoring() {
        console.log('[SessionDebugValidator] Iniciando monitoramento de requisições...');
        
        // Interceptar fetch globalmente
        const originalFetch = window.fetch;
        window.fetch = (...args) => {
            const url = typeof args[0] === 'string' ? args[0] : args[0].url;
            const timestamp = Date.now();
            
            // Logar requisições de API
            if (url.includes('/api/')) {
                this.requestLog.push({
                    timestamp,
                    url,
                    elapsed: timestamp - this.startTime
                });
                
                // Logar info
                console.log(`[FETCH] ${url} em +${Math.floor((timestamp - this.startTime) / 1000)}s`);
            }
            
            return originalFetch(...args);
        };
        
        // Interceptar XMLHttpRequest
        const originalXHROpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method, url, ...rest) {
            if (typeof url === 'string' && url.includes('/api/')) {
                const timestamp = Date.now();
                this.requestLog = this.requestLog || [];
                this.requestLog.push({
                    timestamp,
                    url,
                    method,
                    elapsed: timestamp - (window.__validatorStartTime || Date.now())
                });
                console.log(`[XHR] ${method} ${url}`);
            }
            return originalXHROpen.call(this, method, url, ...rest);
        };
        
        window.__validatorStartTime = this.startTime;
    }

    /**
     * Analisar log de requisições
     */
    analyzeLog() {
        const agora = Date.now();
        const duracao = (agora - this.startTime) / 1000; // segundos
        const reqsPorMinuto = (this.requestLog.length / duracao) * 60;
        
        console.log('\n╔════════════════════════════════════════════════════════════════╗');
        console.log('║                    RELATÓRIO DE REQUISIÇÕES                       ║');
        console.log('╠════════════════════════════════════════════════════════════════╣');
        console.log(`║ Duração do monitoramento: ${duracao.toFixed(2)}s`);
        console.log(`║ Total de requisições de API: ${this.requestLog.length}`);
        console.log(`║ Requisições por minuto: ${reqsPorMinuto.toFixed(2)}`);
        console.log(`║ Status: ${reqsPorMinuto <= this.maxRequestsPerMinute ? '✅ OK' : '❌ CRÍTICO'}`);
        console.log('╚════════════════════════════════════════════════════════════════╝\n');
        
        // Agrupar por URL
        const porUrl = {};
        this.requestLog.forEach(req => {
            porUrl[req.url] = (porUrl[req.url] || 0) + 1;
        });
        
        console.log('📊 Requisições por URL:');
        Object.entries(porUrl).forEach(([url, count]) => {
            const frequencia = (count / duracao) * 60;
            const status = frequencia > 2 ? '⚠️ AGRESSIVO' : '✅ OK';
            console.log(`   ${url}: ${count} vezes (${frequencia.toFixed(1)} req/min) ${status}`);
        });
        
        // Alertas
        if (reqsPorMinuto > this.maxRequestsPerMinute) {
            console.error('\n❌ ALERTA: Requisições acima do esperado!');
            console.error(`   Máximo aceitável: ${this.maxRequestsPerMinute} req/min`);
            console.error(`   Observado: ${reqsPorMinuto.toFixed(2)} req/min`);
        } else {
            console.log('\n✅ Sistema validado: Sem loops agressivos detectados');
        }
    }

    /**
     * Log resumido a cada minuto
     */
    logSummary() {
        const agora = Date.now();
        const duracao = (agora - this.startTime) / 1000;
        const reqsPorMinuto = (this.requestLog.length / duracao) * 60;
        
        console.log(`\n[${new Date().toLocaleTimeString()}] Requisições: ${this.requestLog.length} | Média: ${reqsPorMinuto.toFixed(1)} req/min`);
        
        const porUrl = {};
        this.requestLog.forEach(req => {
            porUrl[req.url] = (porUrl[req.url] || 0) + 1;
        });
        Object.entries(porUrl).forEach(([url, count]) => {
            const nomeUrl = url.split('/').pop().replace('.php', '');
            console.log(`   └─ ${nomeUrl}: ${count}x`);
        });
    }
}

// Inicializar validador globalmente
document.addEventListener('DOMContentLoaded', () => {
    // Expor validador global apenas se em desenvolvimento (URL com localhost ou debugmode)
    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' || window.location.search.includes('debugmode')) {
        window.sessionValidator = new SessionDebugValidator();
        window.sessionValidator.startMonitoring();
        
        // Log a cada 60s
        setInterval(() => {
            window.sessionValidator.logSummary();
        }, 60000);
        
        console.log('🔍 SessionDebugValidator ativado. Use window.sessionValidator.analyzeLog() para ver relatório completo.');
    }
});
