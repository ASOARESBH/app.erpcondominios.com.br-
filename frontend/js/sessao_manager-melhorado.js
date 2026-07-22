/**
 * =====================================================
 * GERENCIADOR DE SESSÃO MELHORADO - Com Logout Modal
 * =====================================================
 * Versão melhorada com integração ao logout-modal-manager.js
 * 
 * MELHORIAS:
 * 1. Função logout() agora limpa dados locais
 * 2. Integração com localStorage/sessionStorage
 * 3. Suporte a token_acesso
 * 4. Redirecionamento seguro
 * 5. Compatibilidade com modal de logout
 */

class SessaoManager {
    constructor() {
        this.intervaloVerificacao = 60000; // Verificar a cada 1 minuto
        this.intervaloRenovacao = 300000; // Renovar a cada 5 minutos
        this.apiBase = '../api/'; // Caminho correto
        this.timeoutId = null;
        this.renovacaoId = null;
        this.sessaoAtiva = false;
        this.tipoUsuario = this.detectarTipoUsuario();
        
        // Iniciar automaticamente
        this.iniciar();
    }
    
    /**
     * Detectar tipo de usuário baseado na página atual
     */
    detectarTipoUsuario() {
        const caminhoAtual = window.location.pathname;
        
        if (caminhoAtual.includes('painel_fornecedor') || 
            caminhoAtual.includes('login_fornecedor')) {
            console.log('[SessaoManager] Tipo de usuário: FORNECEDOR');
            return 'fornecedor';
        }
        
        console.log('[SessaoManager] Tipo de usuário: COMUM');
        return 'comum';
    }
    
    /**
     * Obter URL de login apropriada
     */
    obterUrlLogin() {
        if (this.tipoUsuario === 'fornecedor') {
            return 'login_fornecedor.html';
        }
        return 'login.html';
    }
    
    /**
     * Iniciar gerenciador de sessão
     */
    iniciar() {
        console.log('[SessaoManager] Iniciando gerenciador de sessão');
        console.log('[SessaoManager] API Base:', this.apiBase);
        console.log('[SessaoManager] Tipo de usuário:', this.tipoUsuario);
        
        // Verificar sessão imediatamente
        this.verificarSessao();
        
        // Configurar verificação periódica
        this.timeoutId = setInterval(() => {
            this.verificarSessao();
        }, this.intervaloVerificacao);
        
        // Configurar renovação automática
        this.renovacaoId = setInterval(() => {
            this.renovarSessao();
        }, this.intervaloRenovacao);
        
        // Renovar sessão em atividade do usuário
        this.configurarRenovacaoPorAtividade();
    }
    
    /**
     * Parar gerenciador de sessão
     */
    parar() {
        if (this.timeoutId) {
            clearInterval(this.timeoutId);
            this.timeoutId = null;
        }
        
        if (this.renovacaoId) {
            clearInterval(this.renovacaoId);
            this.renovacaoId = null;
        }
        
        console.log('[SessaoManager] Gerenciador parado');
    }
    
    /**
     * Verificar se sessão está ativa
     */
    async verificarSessao() {
        try {
            const response = await fetch(this.apiBase + 'verificar_sessao_completa.php', {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                console.warn('[SessaoManager] Erro HTTP:', response.status);
                this.sessaoAtiva = false;
                return false;
            }
            
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('[SessaoManager] Erro ao fazer parse do JSON:', parseError);
                this.sessaoAtiva = false;
                return false;
            }
            
            if (data.sucesso && data.sessao_ativa) {
                this.sessaoAtiva = true;
                console.log('[SessaoManager] Sessão ativa:', data.usuario.nome);
                console.log('[SessaoManager] Tempo restante:', data.tempo_restante_formatado);
                
                // Atualizar informações do usuário na interface
                this.atualizarInterfaceUsuario(data.usuario);
                
                // Avisar se sessão está prestes a expirar (menos de 10 minutos)
                if (data.tempo_restante_segundos < 600) {
                    this.alertarExpiracaoProxima(data.tempo_restante_segundos);
                }
                
                return true;
            } else {
                this.sessaoAtiva = false;
                console.warn('[SessaoManager] Sessão inválida ou expirada');
                this.redirecionarParaLogin();
                return false;
            }
        } catch (error) {
            console.error('[SessaoManager] Erro ao verificar sessão:', error);
            this.sessaoAtiva = false;
            return false;
        }
    }
    
    /**
     * Renovar sessão
     */
    async renovarSessao() {
        if (!this.sessaoAtiva) {
            console.log('[SessaoManager] Sessão inativa, não renovando');
            return false;
        }
        
        try {
            const formData = new FormData();
            formData.append('acao', 'renovar');
            
            const response = await fetch(this.apiBase + 'verificar_sessao_completa.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            
            if (!response.ok) {
                console.warn('[SessaoManager] Erro ao renovar sessão:', response.status);
                return false;
            }
            
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('[SessaoManager] Erro ao fazer parse do JSON:', parseError);
                return false;
            }
            
            if (data.sucesso) {
                console.log('[SessaoManager] Sessão renovada com sucesso');
                return true;
            } else {
                console.warn('[SessaoManager] Falha ao renovar sessão');
                return false;
            }
        } catch (error) {
            console.error('[SessaoManager] Erro ao renovar sessão:', error);
            return false;
        }
    }
    
    /**
     * Fazer logout com limpeza segura
     */
    async logout() {
        console.log('[SessaoManager] Iniciando logout seguro...');
        
        try {
            const formData = new FormData();
            formData.append('acao', 'logout');
            
            const response = await fetch(this.apiBase + 'verificar_sessao_completa.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            
            if (!response.ok) {
                console.warn('[SessaoManager] Erro ao fazer logout:', response.status);
                // Mesmo com erro, fazer limpeza local
                this.limparDadosLocais();
                return false;
            }
            
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('[SessaoManager] Erro ao fazer parse do JSON:', parseError);
                // Mesmo com erro, fazer limpeza local
                this.limparDadosLocais();
                return false;
            }
            
            if (data.sucesso) {
                console.log('[SessaoManager] Logout realizado pela API');
                this.limparDadosLocais();
                return true;
            } else {
                console.warn('[SessaoManager] Falha ao fazer logout pela API');
                this.limparDadosLocais();
                return false;
            }
        } catch (error) {
            console.error('[SessaoManager] Erro ao fazer logout:', error);
            // Mesmo com erro, fazer limpeza local
            this.limparDadosLocais();
            return false;
        }
    }
    
    /**
     * Limpar dados locais de forma segura
     */
    limparDadosLocais() {
        console.log('[SessaoManager] 🧹 Limpando dados locais...');
        
        // Parar gerenciador
        this.parar();
        
        // Limpar localStorage
        try {
            localStorage.clear();
            console.log('[SessaoManager] ✅ localStorage limpo');
        } catch (error) {
            console.error('[SessaoManager] Erro ao limpar localStorage:', error);
        }
        
        // Limpar sessionStorage
        try {
            sessionStorage.clear();
            console.log('[SessaoManager] ✅ sessionStorage limpo');
        } catch (error) {
            console.error('[SessaoManager] Erro ao limpar sessionStorage:', error);
        }
        
        // Remover token_acesso específico
        try {
            if (localStorage.getItem('token_acesso')) {
                localStorage.removeItem('token_acesso');
                console.log('[SessaoManager] ✅ token_acesso removido');
            }
        } catch (error) {
            console.error('[SessaoManager] Erro ao remover token_acesso:', error);
        }
        
        // Redirecionar após limpeza
        this.redirecionarParaLogin();
    }
    
    /**
     * Configurar renovação por atividade do usuário
     */
    configurarRenovacaoPorAtividade() {
        let ultimaAtividade = Date.now();
        const eventos = ['mousedown', 'keydown', 'scroll', 'touchstart'];
        
        const atualizarAtividade = () => {
            const agora = Date.now();
            const tempoDecorrido = agora - ultimaAtividade;
            
            // Se passou mais de 5 minutos desde última atividade, renovar
            if (tempoDecorrido > 300000) {
                console.log('[SessaoManager] Atividade detectada, renovando sessão');
                this.renovarSessao();
            }
            
            ultimaAtividade = agora;
        };
        
        // Adicionar listeners
        eventos.forEach(evento => {
            document.addEventListener(evento, atualizarAtividade, { passive: true });
        });
    }
    
    /**
     * Alertar que sessão está prestes a expirar
     */
    alertarExpiracaoProxima(segundosRestantes) {
        const minutos = Math.floor(segundosRestantes / 60);
        
        if (minutos === 10 && !this.alertaMostrado) {
            this.alertaMostrado = true;
            
            console.warn('[SessaoManager] Sessão expira em', minutos, 'minutos');
            
            // Mostrar notificação
            const notificacao = document.getElementById('notificacao-sessao');
            if (notificacao) {
                notificacao.textContent = `Sua sessão expira em ${minutos} minutos. Salve seu trabalho.`;
                notificacao.style.display = 'block';
            }
        }
    }
    
    /**
     * Redirecionar para login
     */
    redirecionarParaLogin() {
        console.log('[SessaoManager] 🔄 Redirecionando para login...');
        
        // Obter URL de login apropriada
        const urlLogin = this.obterUrlLogin();
        
        // Aguardar 500ms e redirecionar
        setTimeout(() => {
            window.location.href = urlLogin;
        }, 500);
    }
    
    /**
     * Atualizar interface com informações do usuário
     */
    atualizarInterfaceUsuario(usuario) {
        // Atualizar nome do usuário
        const nomeUsuario = document.getElementById('nome-usuario');
        if (nomeUsuario) {
            nomeUsuario.textContent = usuario.nome;
        }
        
        // Atualizar email
        const emailUsuario = document.getElementById('email-usuario');
        if (emailUsuario) {
            emailUsuario.textContent = usuario.email;
        }
        
        // Atualizar função
        const funcaoUsuario = document.getElementById('funcao-usuario');
        if (funcaoUsuario) {
            funcaoUsuario.textContent = usuario.funcao;
        }
    }
}

// Criar instância global
let sessaoManager = null;

// Iniciar automaticamente quando página carregar
document.addEventListener('DOMContentLoaded', function() {
    // Não iniciar na página de login
    if (!window.location.pathname.includes('login.html') && 
        !window.location.pathname.includes('login_fornecedor.html')) {
        sessaoManager = new SessaoManager();
        
        // Disponibilizar globalmente
        window.sessaoManager = sessaoManager;
        
        console.log('[SessaoManager] Gerenciador iniciado automaticamente');
    }
});
