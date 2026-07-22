/**
 * =====================================================
 * UNIFIED HEADER SYNC - Sincronização de Perfil no Cabeçalho
 * =====================================================
 * Sincroniza dados do usuário no novo cabeçalho unificado
 * Mantém integridade de IDs de sistema
 * 
 * IDs Preservados:
 * - userProfileSection
 * - userAvatar
 * - userName
 * - userFunction
 * - sessionTimer
 * - sessionStatus
 * - btn-logout
 * 
 * Fluxo:
 * 1. Buscar dados do usuário via API
 * 2. Injetar no cabeçalho (lado direito)
 * 3. Sincronizar com sidebar (se existir)
 * 4. Atualizar timer de sessão
 */

(function () {
    'use strict';

    const CONFIG = {
        headerProfileId: 'headerUserProfile',
        sidebarProfileId: 'userProfileSection',
        sessionTimerId: 'sessionTimer',
        sessionStatusId: 'sessionStatus'
    };

    let inicializado = false;

    /**
     * Inicializar sincronização
     * ✅ CORRIGIDO (v6.0): Usa SessionManagerSingleton em vez de polling agressivo (1s)
     */
    function inicializar() {
        if (inicializado) return; // Evitar múltiplas inicializações
        inicializado = true;

        console.log('🔄 Unified Header Sync inicializado');

        // Criar estrutura HTML do cabeçalho se não existir
        criarHeaderProfile();

        // Obter SessionManager Singleton
        const sessionMgr = SessionManagerCore.getInstance();

        // Carregar dados iniciais
        const usuarioAtual = sessionMgr.getUserData();
        if (usuarioAtual) {
            sincronizarDados({ usuario: usuarioAtual });
        }

        // Em vez de polling a cada 1s, escuta eventos do gerenciador central
        sessionMgr.onUserDataChanged((dados) => {
            sincronizarDados(dados);
        });

        // Listeners de visibilidade
        document.addEventListener('visibilitychange', handleVisibilidadeChange);
        window.addEventListener('beforeunload', limpar);

        console.log('✅ Unified Header Sync pronto (Gerenciador Centralizado)');
    }

    /**
     * Criar estrutura HTML do perfil no cabeçalho
     */
    function criarHeaderProfile() {
        // Verificar se já existe
        if (document.getElementById(CONFIG.headerProfileId)) {
            return;
        }

        // Encontrar o header
        const header = document.querySelector('.header');
        if (!header) {
            console.warn('Header não encontrado');
            return;
        }

        // Verificar se já tem o bloco de perfil
        if (header.querySelector('.header-user-profile')) {
            return;
        }

        // HTML do perfil
        const profileHTML = `
            <div class="header-user-profile" id="${CONFIG.headerProfileId}">
                <!-- Avatar -->
                <div class="header-user-avatar" id="userAvatar">-</div>

                <!-- Informações do usuário -->
                <div class="header-user-info">
                    <div class="header-user-name" id="userName">Carregando...</div>
                    <div class="header-user-function" id="userFunction">
                        <span>USUÁRIO</span>
                    </div>
                    <div class="header-user-status">
                        <span class="status-indicator"></span>
                        <span id="sessionStatus">Ativo</span>
                    </div>
                </div>

                <!-- Informações de sessão -->
                <div class="header-session-info">
                    <div class="session-timer" id="sessionTimer">00:00:00</div>
                    <div class="session-status">SESSÃO</div>
                </div>
            </div>
        `;

        // Inserir antes do botão de logout (se existir) ou no final
        const btnLogout = header.querySelector('#btn-logout');
        if (btnLogout) {
            btnLogout.parentElement.insertBefore(
                document.createRange().createContextualFragment(profileHTML),
                btnLogout
            );
        } else {
            header.insertAdjacentHTML('beforeend', profileHTML);
        }

        // Adicionar CSS se não existir
        adicionarCSS();
    }

    /**
     * Adicionar CSS do cabeçalho unificado
     */
    function adicionarCSS() {
        if (document.getElementById('unified-header-css')) {
            return;
        }

        const linkElement = document.createElement('link');
        linkElement.id = 'unified-header-css';
        linkElement.rel = 'stylesheet';
        linkElement.href = '../css/unified-header.css';
        document.head.appendChild(linkElement);
    }

    /**
     * Sincronizar dados do usuário
     * ✅ CORRIGIDO: Recebe dados como parâmetro (não faz fetch)
     * @param {Object} dados - { usuario: {}, sessao: {} }
     */
    function sincronizarDados(dados) {
        const usuario = dados && dados.usuario ? dados.usuario : null;
        const tempo = (typeof (dados && dados.tempo_restante) !== 'undefined') ? dados.tempo_restante : (dados && dados.sessao ? dados.sessao.tempo_restante : null);

        try {
            atualizarUI(usuario, tempo);
        } catch (error) {
            console.warn('[UnifiedHeaderSync] Erro ao sincronizar dados:', error);
        }
    }

    /**
     * Atualizar interface com dados do usuário
     */
    function atualizarUI(usuario, tempoRestante) {
        // Extrair dados (defensivo)
        const nomeRaw = usuario && usuario.nome ? usuario.nome : '';
        const inicial = nomeRaw.length ? nomeRaw.charAt(0).toUpperCase() : '-';
        const nome = nomeRaw ? nomeRaw.toUpperCase() : 'CONVIDADO';
        const funcao = (usuario && (usuario.funcao || usuario.permissao)) ? (usuario.funcao || usuario.permissao).toUpperCase() : 'USUÁRIO';

        // Atualizar avatar
        const avatar = document.getElementById('userAvatar');
        if (avatar) {
            avatar.textContent = inicial;
            avatar.classList.remove('loading');
        }

        // Atualizar nome
        const nameElement = document.getElementById('userName');
        if (nameElement) {
            nameElement.textContent = nome;
        }

        // Atualizar função
        const funcaoElement = document.getElementById('userFunction');
        if (funcaoElement) {
            const span = funcaoElement.querySelector('span');
            if (span) {
                span.textContent = funcao;
            }
        }

        // Atualizar status de sessão
        const statusElement = document.getElementById('sessionStatus');
        if (statusElement) {
            statusElement.textContent = usuario ? 'Ativo' : 'Indisponível';
        }

        // Atualizar timer de sessão
        if (tempoRestante !== undefined) {
            tempoSessaoRestante = tempoRestante;
            atualizarTimer();
        }

        // Sincronizar com sidebar se existir
        sincronizarComSidebar(inicial, nome, funcao);
    }

    /**
     * Atualizar timer de sessão
     */
    function atualizarTimer() {
        if (tempoSessaoRestante === null) {
            return;
        }

        const timerElement = document.getElementById(CONFIG.sessionTimerId);
        if (!timerElement) {
            return;
        }

        // Converter segundos para HH:MM:SS
        const horas = Math.floor(tempoSessaoRestante / 3600);
        const minutos = Math.floor((tempoSessaoRestante % 3600) / 60);
        const segundos = tempoSessaoRestante % 60;

        const tempo = `${String(horas).padStart(2, '0')}:${String(minutos).padStart(2, '0')}:${String(segundos).padStart(2, '0')}`;
        timerElement.textContent = tempo;

        // Avisar se tempo está acabando (menos de 5 minutos)
        if (tempoSessaoRestante < 300) {
            timerElement.style.color = '#fca5a5';
        } else if (tempoSessaoRestante < 600) {
            timerElement.style.color = '#fbbf24';
        } else {
            timerElement.style.color = '#fbbf24';
        }

        // Decrementar tempo
        tempoSessaoRestante--;
    }

    /**
     * Sincronizar com sidebar
     */
    function sincronizarComSidebar(inicial, nome, funcao) {
        // Avatar
        const sidebarAvatar = document.querySelector(`#${CONFIG.sidebarProfileId} .user-avatar`);
        if (sidebarAvatar) {
            sidebarAvatar.textContent = inicial;
        }

        // Nome
        const sidebarName = document.querySelector(`#${CONFIG.sidebarProfileId} .user-name`);
        if (sidebarName) {
            sidebarName.textContent = nome;
        }

        // Função
        const sidebarFunction = document.querySelector(`#${CONFIG.sidebarProfileId} .user-function`);
        if (sidebarFunction) {
            sidebarFunction.textContent = funcao;
        }
    }

    /**
     * Lidar com mudança de visibilidade
     */
    function handleVisibilidadeChange() {
        if (document.hidden) {
            if (intervaloSincronizacao) {
                clearInterval(intervaloSincronizacao);
            }
        } else {
            sincronizarDados();
            intervaloSincronizacao = setInterval(sincronizarDados, CONFIG.syncInterval);
        }
    }

    /**
     * Limpar recursos
     */
    function limpar() {
        if (intervaloSincronizacao) {
            clearInterval(intervaloSincronizacao);
        }
    }

    /**
     * Inicializar quando DOM estiver pronto
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

})();
