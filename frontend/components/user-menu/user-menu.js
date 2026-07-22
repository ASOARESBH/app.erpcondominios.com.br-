/**
 * user-menu.js v3.2 - Modo Dual (Legacy + Web Component)
 *
 * MODO LEGADO (automatico): Atualiza #topUserName, #topUserAvatar, #userMiniProfile
 * MODO WEB COMPONENT: <app-user-menu> (para versoes futuras do layout)
 *
 * Integra com SessionManagerCore v3.0 via eventos userDataChanged e countdownTick.
 */

// ============================================================
// MODO LEGADO - Inicializacao automatica para o layout atual
// ============================================================
(function initLegacyUserMenu() {
    'use strict';

    var _initialized = false;
    var _sessaoInativa = false;
    var _countdownInterval = null;

    function _getEl(id) { return document.getElementById(id); }

    function _iniciais(nome) {
        if (!nome) return 'U';
        var p = nome.trim().split(' ');
        var a = p[0] ? p[0].charAt(0) : '';
        var b = p.length > 1 ? p[p.length - 1].charAt(0) : '';
        return (a + b).toUpperCase() || 'U';
    }

    function _formatarTempo(s) {
        if (s === null || s === undefined) return '';
        var mm = Math.floor(s / 60);
        var ss = s % 60;
        return String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    }

    function _injetarElementos() {
        var elNome = _getEl('topUserName');
        if (!elNome || _getEl('topSessionTimer')) return;

        var timer = document.createElement('span');
        timer.id = 'topSessionTimer';
        timer.style.cssText = 'display:inline-block;font-size:11px;color:#94a3b8;margin-left:8px;font-family:monospace;vertical-align:middle;';
        elNome.parentNode.insertBefore(timer, elNome.nextSibling);

        var cargo = document.createElement('span');
        cargo.id = 'topUserCargo';
        cargo.style.cssText = 'display:block;font-size:10px;color:#64748b;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;';
        elNome.parentNode.appendChild(cargo);

        elNome.style.cssText = 'font-size:13px;font-weight:600;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;display:block;';

        var avatar = _getEl('topUserAvatar');
        if (avatar) {
            avatar.style.cssText = 'width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;box-shadow:0 2px 8px rgba(37,99,235,0.4);transition:all 0.3s;';
        }

        var container = _getEl('userMiniProfile');
        if (container) {
            container.style.cssText = 'display:flex;align-items:center;gap:10px;cursor:pointer;padding:4px 10px;border-radius:8px;transition:background 0.2s;';
            container.addEventListener('mouseenter', function() { container.style.background = 'rgba(255,255,255,0.1)'; });
            container.addEventListener('mouseleave', function() { container.style.background = ''; });
        }
    }

    function _atualizarUI(nome, cargo, segundos, permanente) {
        var elNome = _getEl('topUserName');
        var elAvatar = _getEl('topUserAvatar');
        var elCargo = _getEl('topUserCargo');
        var elTimer = _getEl('topSessionTimer');

        if (elNome) {
            var primeiroNome = nome ? nome.split(' ')[0] : 'Usuario';
            elNome.textContent = primeiroNome;
            elNome.title = nome || 'Usuario';
        }
        if (elAvatar) {
            elAvatar.textContent = _iniciais(nome);
        }
        if (elCargo && cargo) {
            elCargo.textContent = cargo;
        }

        if (!elTimer) return;

        if (permanente || _sessaoInativa) {
            elTimer.textContent = 'Sem limite';
            elTimer.style.color = '#16a34a';
            elTimer.style.fontWeight = '600';
            if (elAvatar) elAvatar.style.background = 'linear-gradient(135deg,#16a34a,#15803d)';
            return;
        }

        if (segundos !== null && segundos !== undefined) {
            elTimer.textContent = _formatarTempo(segundos);
            elTimer.title = 'Sessao expira em ' + _formatarTempo(segundos);
            if (segundos <= 300) {
                elTimer.style.color = '#dc2626';
                elTimer.style.fontWeight = '700';
                if (elAvatar) elAvatar.style.background = 'linear-gradient(135deg,#dc2626,#b91c1c)';
            } else {
                elTimer.style.color = '#94a3b8';
                elTimer.style.fontWeight = '400';
                if (elAvatar) elAvatar.style.background = '';
            }
        }
    }

    function _iniciarContador(segundosInicio) {
        if (_countdownInterval) clearInterval(_countdownInterval);
        if (segundosInicio === null || segundosInicio === undefined || _sessaoInativa) return;

        var s = parseInt(segundosInicio, 10);
        _countdownInterval = setInterval(function() {
            if (s <= 0) { clearInterval(_countdownInterval); return; }
            s--;
            var elTimer = _getEl('topSessionTimer');
            if (!elTimer) return;
            elTimer.textContent = _formatarTempo(s);
            if (s <= 300) {
                elTimer.style.color = '#dc2626';
                elTimer.style.fontWeight = '700';
            }
        }, 1000);
    }

    function _integrar() {
        var sm = window.SessionManagerCore;
        if (!sm || !sm.instance) {
            setTimeout(_integrar, 300);
            return;
        }
        var inst = sm.instance;

        // Dados ja disponiveis (sessao verificada antes deste script)
        if (inst.currentUser) {
            var u = inst.currentUser;
            _sessaoInativa = inst.sessaoInativa || false;
            _atualizarUI(u.nome || u.name || 'Usuario', u.funcao || u.departamento || '', inst.countdownSeconds, _sessaoInativa);
            if (!_sessaoInativa && inst.countdownSeconds) _iniciarContador(inst.countdownSeconds);
        }

        // Evento: dados completos apos verificacao com API
        inst.on('userDataChanged', function(dados) {
            var user = dados.user || dados.usuario;
            if (!user) return;
            var nome = user.nome || user.name || 'Usuario';
            var cargo = user.funcao || user.departamento || user.permissao || '';
            _sessaoInativa = (dados.sessao && dados.sessao.sessao_inativa) || inst.sessaoInativa || false;
            var expire = dados.expireTime || dados.tempo_restante || null;
            _atualizarUI(nome, cargo, expire, _sessaoInativa);
            if (!_sessaoInativa && expire) _iniciarContador(expire);
            console.log('[UserMenu] Usuario: ' + nome + ' | Cargo: ' + cargo + ' | Sessao inativa: ' + _sessaoInativa);
        });

        // Evento: tick do countdown (a cada segundo)
        inst.on('countdownTick', function(dados) {
            var elTimer = _getEl('topSessionTimer');
            if (!elTimer) return;
            if (dados.permanente || _sessaoInativa) {
                elTimer.textContent = 'Sem limite';
                elTimer.style.color = '#16a34a';
            } else {
                elTimer.textContent = _formatarTempo(dados.segundos);
                elTimer.title = 'Sessao expira em ' + _formatarTempo(dados.segundos);
                if (dados.aviso || dados.segundos <= 300) {
                    elTimer.style.color = '#dc2626';
                    elTimer.style.fontWeight = '700';
                } else {
                    elTimer.style.color = '#94a3b8';
                    elTimer.style.fontWeight = '400';
                }
            }
        });

        console.log('[UserMenu] Integrado ao SessionManagerCore');
    }

    function _init() {
        if (_initialized) return;
        _initialized = true;
        _injetarElementos();
        _integrar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _init);
    } else {
        setTimeout(_init, 50);
    }
})();

// ============================================================
// WEB COMPONENT - Para versoes futuras do layout-base.html
// ============================================================
if (!customElements.get('app-user-menu')) {
    class AppUserMenu extends HTMLElement {
        constructor() {
            super();
            this.unsubscribeFunctions = [];
            this.ui = { avatar: null, name: null, subinfo: null, countdown: null, dropdown: null, toggleBtn: null, logoutBtn: null };
        }
        connectedCallback() {
            this.innerHTML = '<div class="app-user-menu-container"><div class="user-avatar">U</div><div class="user-info"><span class="user-name">Carregando...</span><span class="user-subinfo"></span><span class="session-countdown"></span></div><div class="user-dropdown"><button class="menu-toggle"><i class="fas fa-chevron-down"></i></button><div class="dropdown-menu"><button data-action="manual" class="menu-item-btn"><i class="fas fa-book-open"></i> Manual do Sistema</button><hr class="dropdown-divider"><button data-action="logout" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Sair</button></div></div></div>';
            this.ui.avatar    = this.querySelector('.user-avatar');
            this.ui.name      = this.querySelector('.user-name');
            this.ui.subinfo   = this.querySelector('.user-subinfo');
            this.ui.countdown = this.querySelector('.session-countdown');
            this.ui.dropdown  = this.querySelector('.dropdown-menu');
            this.ui.toggleBtn = this.querySelector('.menu-toggle');
            this.ui.logoutBtn = this.querySelector('[data-action="logout"]');
            var self = this;
            if (this.ui.toggleBtn) this.ui.toggleBtn.addEventListener('click', function(e) { e.stopPropagation(); if (self.ui.dropdown) self.ui.dropdown.classList.toggle('active'); });
            if (this.ui.logoutBtn) this.ui.logoutBtn.addEventListener('click', function() { var sm = window.SessionManagerCore; if (sm && sm.instance) sm.instance.logout(); else window.location.href = '/frontend/login.html'; });
            var manualBtn = this.querySelector('[data-action="manual"]');
            if (manualBtn) manualBtn.addEventListener('click', function() { if (self.ui.dropdown) self.ui.dropdown.classList.remove('active'); if (window.AppRouter) window.AppRouter.loadPage('manual'); else window.location.href = '../frontend/layout-base.html?page=manual'; });
            this._integrar();
        }
        disconnectedCallback() { this.unsubscribeFunctions.forEach(function(u) { u(); }); }
        _integrar() {
            var self = this;
            var sm = window.SessionManagerCore;
            if (!sm || !sm.instance) { setTimeout(function() { self._integrar(); }, 300); return; }
            var inst = sm.instance;
            if (inst.currentUser) this._atualizar(inst.currentUser, inst.countdownSeconds, inst.sessaoInativa);
            inst.on('userDataChanged', function(d) { self._atualizar(d.user || d.usuario, d.expireTime || d.tempo_restante, inst.sessaoInativa); });
            inst.on('countdownTick', function(d) {
                if (!self.ui.countdown) return;
                if (d.permanente) { self.ui.countdown.textContent = 'Sem limite'; self.ui.countdown.style.color = '#16a34a'; }
                else if (d.segundos !== undefined) { self.ui.countdown.textContent = String(Math.floor(d.segundos/60)).padStart(2,'0') + ':' + String(d.segundos%60).padStart(2,'0'); }
            });
        }
        _atualizar(user, expire, inativa) {
            if (!user) return;
            var nome = user.nome || 'Usuario';
            var p = nome.trim().split(' ');
            var ini = ((p[0]||'').charAt(0) + ((p.length>1?p[p.length-1]:'')||'').charAt(0)).toUpperCase() || 'U';
            if (this.ui.name) this.ui.name.textContent = nome;
            if (this.ui.avatar) this.ui.avatar.textContent = ini;
            if (this.ui.subinfo) this.ui.subinfo.textContent = user.funcao || user.departamento || '';
            if (this.ui.countdown) {
                if (inativa) { this.ui.countdown.textContent = 'Sem limite'; this.ui.countdown.style.color = '#16a34a'; }
                else if (expire) { this.ui.countdown.textContent = String(Math.floor(expire/60)).padStart(2,'0') + ':' + String(expire%60).padStart(2,'0'); }
            }
        }
    }
    customElements.define('app-user-menu', AppUserMenu);
}
