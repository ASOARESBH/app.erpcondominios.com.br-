/**
 * =====================================================
 * GERENCIADOR DE SESSÃO AUTOMÁTICO - COM AUTO-INJECTOR
 * =====================================================
 * 
 * Centraliza a segurança e o carregamento da UI Refactor.
 */

// 1. Auto-injector de scripts de UI
(function () {
    const scriptsNeeded = [
        'js/user-profile-sidebar.js',
        'js/user-display.js',
        'js/logout-seguro.js'
    ];

    scriptsNeeded.forEach(src => {
        // Verificar se o script já existe (pelo src)
        const exists = Array.from(document.scripts).some(s => s.src && s.src.includes(src));
        if (!exists) {
            const script = document.createElement('script');
            script.src = src;
            script.async = false; // Manter ordem
            document.head.appendChild(script);
        }
    });
})();

class SessaoManager {
    constructor() {
        this.intervaloVerificacao = 60000;
        this.intervaloRenovacao = 300000;
        this.apiBase = '../api/';
        this.timeoutId = null;
        this.renovacaoId = null;
        this.sessaoAtiva = false;
        this.tipoUsuario = this.detectarTipoUsuario();

        this.iniciar();
    }

    detectarTipoUsuario() {
        const caminhoAtual = window.location.pathname;
        if (caminhoAtual.includes('painel_fornecedor') || caminhoAtual.includes('login_fornecedor')) {
            return 'fornecedor';
        }
        return 'comum';
    }

    obterUrlLogin() {
        if (this.tipoUsuario === 'fornecedor') return 'login_fornecedor.html';
        return 'login.html';
    }

    iniciar() {
        this.verificarSessao();
        this.timeoutId = setInterval(() => this.verificarSessao(), this.intervaloVerificacao);
        this.renovacaoId = setInterval(() => this.renovarSessao(), this.intervaloRenovacao);
        this.configurarRenovacaoPorAtividade();
    }

    parar() {
        if (this.timeoutId) clearInterval(this.timeoutId);
        if (this.renovacaoId) clearInterval(this.renovacaoId);
    }

    async verificarSessao() {
        try {
            const response = await fetch(this.apiBase + 'verificar_sessao_completa.php', {
                method: 'GET',
                credentials: 'include'
            });

            if (!response.ok) {
                this.sessaoAtiva = false;
                return false;
            }

            const data = await response.json();

            if (data.sucesso && data.sessao_ativa) {
                this.sessaoAtiva = true;
                this.atualizarInterfaceUsuario(data.usuario);
                return true;
            } else {
                this.sessaoAtiva = false;
                // Só redireciona se não for tela de login
                if (!window.location.pathname.includes('login.html')) {
                    this.redirecionarParaLogin();
                }
                return false;
            }
        } catch (error) {
            this.sessaoAtiva = false;
            return false;
        }
    }

    async renovarSessao() {
        if (!this.sessaoAtiva) return false;
        try {
            const formData = new FormData();
            formData.append('acao', 'renovar');
            const response = await fetch(this.apiBase + 'verificar_sessao_completa.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            return response.ok;
        } catch (error) {
            return false;
        }
    }

    configurarRenovacaoPorAtividade() {
        let ultimaAtividade = Date.now();
        const eventos = ['mousedown', 'keydown', 'scroll', 'touchstart'];
        const atualizarAtividade = () => {
            const agora = Date.now();
            if (agora - ultimaAtividade > 300000) this.renovarSessao();
            ultimaAtividade = agora;
        };
        eventos.forEach(evento => document.addEventListener(evento, atualizarAtividade, { passive: true }));
    }

    redirecionarParaLogin() {
        this.parar();
        const urlLogin = this.obterUrlLogin();
        window.location.href = urlLogin;
    }

    atualizarInterfaceUsuario(usuario) {
        // Alvos sincronizados com user-profile-sidebar.js e user-display.js
        const targets = {
            name: document.querySelectorAll('.header-user-name, #topUserName, .user-name'),
            avatar: document.querySelectorAll('.top-user-avatar, #topUserAvatar, .user-avatar')
        };

        const inicial = (usuario.nome || 'U').charAt(0).toUpperCase();

        targets.name.forEach(el => el.textContent = usuario.nome);
        targets.avatar.forEach(el => el.textContent = inicial);
    }

    /**
     * FLUXO DE LOGOUT SEGURO
     * Limpeza total de dados (Fase 3: Refactor)
     */
    async finalizarSessao() {
        console.log('[SessaoManager] Iniciando Fluxo de Logout Seguro...');

        try {
            // 1. Chamar API de logout (Caminho master)
            await fetch(this.apiBase + 'logout.php', { method: 'POST', credentials: 'include' }).catch(() => { });

            // 2. Limpeza de Cookies (token_acesso e Sessão PHP)
            document.cookie = "token_acesso=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            document.cookie = "PHPSESSID=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

            // 3. Limpeza total de Storages (Conforme solicitado)
            localStorage.clear();
            sessionStorage.clear();

            console.log('[SessaoManager] Limpeza total concluída.');

            // 4. Redirecionamento
            window.location.href = this.obterUrlLogin();
        } catch (error) {
            console.error('[SessaoManager] Erro no logout:', error);
            localStorage.clear();
            sessionStorage.clear();
            window.location.href = 'login.html';
        }
    }
}

// Inicializar globalmente
document.addEventListener('DOMContentLoaded', () => {
    if (!window.location.pathname.includes('login')) {
        window.sessaoManager = new SessaoManager();
    }
});

// IDENTIDADE VISUAL (Fallback & Login)
async function carregarIdentidadeVisualSessao() {
    try {
        const response = await fetch('../api/api_empresa.php?action=obter');
        if (!response.ok) return;
        const data = await response.json();

        if (data.sucesso && data.dados) {
            const empresa = data.dados;
            const logoUrl = empresa.logo_url ? '../' + empresa.logo_url : null;
            const nomeEmpresa = empresa.nome_fantasia || empresa.razao_social || 'Serra da Liberdade';

            // Sidebar Logo
            const sidebarLogo = document.getElementById('dynamicSidebarLogo');
            if (sidebarLogo && logoUrl) {
                sidebarLogo.src = logoUrl;
                sidebarLogo.alt = nomeEmpresa;
            }

            // Aba Title
            if (document.title.includes('Serra da Liberdade')) {
                document.title = document.title.replace('Serra da Liberdade', nomeEmpresa);
            }
        }
    } catch (error) { }
}

document.addEventListener('DOMContentLoaded', carregarIdentidadeVisualSessao);
