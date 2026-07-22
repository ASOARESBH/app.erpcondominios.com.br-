/**
 * LOGOUT UNIVERSAL - Funciona em todas as páginas sem modificar HTML
 */

(function() {
    'use strict';
    
    console.log('🔧 Logout Universal carregado');
    
    // Função de logout
    function fazerLogout(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        if (!confirm('Deseja realmente sair do sistema?')) {
            return;
        }
        
        fetch('../api/logout.php', {
            method: 'POST',
            credentials: 'include'
        })
        .finally(() => {
            sessionStorage.clear();
            window.location.href = 'login.html';
        });
    }
    
    // Adicionar botão de logout dinamicamente
    function adicionarBotaoLogout() {
        const sidebar = document.querySelector('.sidebar');
        const navMenu = document.querySelector('.nav-menu');
        
        if (!sidebar || !navMenu) {
            console.log('❌ Menu lateral não encontrado');
            return;
        }
        
        // Verificar se já existe botão de logout
        const existingLogout = sidebar.querySelector('[data-logout-universal]');
        if (existingLogout) {
            console.log('✅ Botão de logout já existe');
            return;
        }
        
        // Criar botão de logout
        const logoutItem = document.createElement('li');
        logoutItem.className = 'nav-item';
        logoutItem.style.marginTop = '2rem';
        logoutItem.style.borderTop = '1px solid rgba(255,255,255,0.1)';
        logoutItem.style.paddingTop = '1rem';
        logoutItem.setAttribute('data-logout-universal', 'true');
        
        const logoutLink = document.createElement('a');
        logoutLink.href = '#';
        logoutLink.className = 'nav-link';
        logoutLink.style.background = 'rgba(239, 68, 68, 0.1)';
        logoutLink.style.color = '#fca5a5';
        
        const logoutIcon = document.createElement('i');
        logoutIcon.className = 'fas fa-sign-out-alt';
        
        const logoutText = document.createTextNode(' Sair');
        
        logoutLink.appendChild(logoutIcon);
        logoutLink.appendChild(logoutText);
        logoutLink.addEventListener('click', fazerLogout);
        
        logoutItem.appendChild(logoutLink);
        navMenu.appendChild(logoutItem);
        
        console.log('✅ Botão de logout adicionado automaticamente');
    }
    
    // Adicionar listener para links de logout existentes
    function configurarLogoutsExistentes() {
        // Procurar por qualquer elemento que possa ser um botão de logout
        const selectors = [
            'a[href*="logout"]',
            'a[onclick*="logout"]',
            'a[onclick*="Logout"]',
            '.logout-btn',
            '#btn-logout',
            'button:contains("Sair")'
        ];
        
        selectors.forEach(selector => {
            try {
                document.querySelectorAll(selector).forEach(element => {
                    element.addEventListener('click', fazerLogout);
                    element.style.cursor = 'pointer';
                });
            } catch (e) {
                // Seletor inválido, continuar
            }
        });
    }
    
    // Inicializar quando a página carregar
    document.addEventListener('DOMContentLoaded', function() {
        adicionarBotaoLogout();
        configurarLogoutsExistentes();
        
        // Também expor a função globalmente
        window.fazerLogout = fazerLogout;
        window.fazerLogoutGlobal = fazerLogout;
        
        console.log('✅ Logout Universal inicializado');
    });
    
})();