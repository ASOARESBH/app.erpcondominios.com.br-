/**
 * meu_perfil.js — Módulo Meu Perfil v1.0
 *
 * APIs utilizadas:
 *   GET  /api/api_usuario_logado.php
 *     → { sucesso, logado, usuario: { id, nome, email, funcao, departamento, permissao },
 *         sessao: { tempo_decorrido, tempo_restante, tempo_decorrido_formatado,
 *                   tempo_restante_formatado, percentual_usado, ip_address, data_login } }
 *
 *   POST /api/api_usuario_logado.php?acao=renovar
 *     → Renova o timestamp da sessão
 *
 *   PUT  /api/api_usuarios.php
 *     → { id, nome, email, funcao, departamento, permissao, ativo, senha }
 *     → Altera senha do próprio usuário
 */
'use strict';

const _API_LOG = window.location.origin + '/api/api_usuario_logado.php';
const _API_USR = window.location.origin + '/api/api_usuarios.php';

let _state = {
    usuarioLogado: null,
    sessao: null,
};

let _listeners = [];
let _sessaoTimer = null;

// ============================================================
// CICLO DE VIDA
// ============================================================

export function init() {
    console.log('[MeuPerfil] Inicializando módulo v1.0...');
    _setupFormSenha();
    _setupBtnRenovar();
    _carregarPerfil();
    console.log('[MeuPerfil] Módulo pronto.');
}

export function destroy() {
    console.log('[MeuPerfil] Destruindo módulo...');
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
    if (_sessaoTimer) {
        clearInterval(_sessaoTimer);
        _sessaoTimer = null;
    }
    _state = { usuarioLogado: null, sessao: null };
    console.log('[MeuPerfil] Módulo destruído.');
}

// ============================================================
// UTILITÁRIOS
// ============================================================

async function _fetch(url, opts = {}) {
    const res = await fetch(url, Object.assign({ credentials: 'include' }, opts));
    if (res.status === 401) {
        window.location.href = '/login.html';
        throw new Error('Sessão expirada');
    }
    return res.json();
}

function _setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val ?? '';
}

function _on(el, ev, fn) {
    if (!el) return;
    el.addEventListener(ev, fn);
    _listeners.push({ el, ev, fn });
}

function _toast(msg, tipo = 'success') {
    const box = document.getElementById('mpAlertBox');
    if (!box) return;

    const cores = { success: '#166534', error: '#991b1b', warning: '#92400e' };
    const fundos = { success: '#dcfce7', error: '#fef2f2', warning: '#fffbeb' };
    const bordas = { success: '#bbf7d0', error: '#fecaca', warning: '#fde68a' };
    const icones = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle' };

    box.innerHTML = `
        <div style="background:${fundos[tipo]};color:${cores[tipo]};border:1px solid ${bordas[tipo]};
             padding:12px 16px;border-radius:8px;margin-bottom:1rem;font-size:0.9rem;font-weight:500;
             display:flex;align-items:center;gap:8px;">
            <i class="fas ${icones[tipo]}"></i> ${msg}
        </div>`;

    setTimeout(() => { if (box) box.innerHTML = ''; }, 5000);
}

// ============================================================
// CARREGAR PERFIL
// ============================================================

async function _carregarPerfil() {
    try {
        console.log('[MeuPerfil] Carregando dados do usuário logado...');
        const data = await _fetch(_API_LOG);

        if (!data.sucesso || !data.logado) {
            window.location.href = '/login.html';
            return;
        }

        const u = data.usuario;
        const s = data.sessao;

        _state.usuarioLogado = u;
        _state.sessao = s;

        // Avatar com inicial do nome
        const inicial = (u.nome || 'U').charAt(0).toUpperCase();
        _setText('mpAvatar', inicial);
        _setText('mpNome', u.nome || '--');
        _setText('mpEmail', u.email || '--');
        _setText('mpFuncao', u.funcao || '--');
        _setText('mpDepartamento', u.departamento || '--');

        const labels = { admin: 'Administrador', gerente: 'Gerente', operador: 'Operador' };
        _setText('mpPermissao', labels[u.permissao] || u.permissao || '--');

        // Sessão
        _atualizarSessao(s);

        // Timer local
        if (_sessaoTimer) clearInterval(_sessaoTimer);
        _sessaoTimer = setInterval(_tickSessao, 1000);

        console.log('[MeuPerfil] Perfil carregado:', u.nome);
    } catch (e) {
        console.error('[MeuPerfil] Erro ao carregar perfil:', e);
        _toast('Erro ao carregar dados do perfil.', 'error');
    }
}

function _atualizarSessao(s) {
    if (!s) return;
    _setText('mpSessaoDecorrido', s.tempo_decorrido_formatado || '--');
    _setText('mpSessaoRestante', s.tempo_restante_formatado || '--');
    _setText('mpSessaoIP', s.ip_address || '--');

    if (s.data_login) {
        try {
            _setText('mpSessaoLogin', new Date(s.data_login).toLocaleString('pt-BR'));
        } catch {
            _setText('mpSessaoLogin', s.data_login);
        }
    }

    _atualizarBarra(s.percentual_usado || 0);
}

function _atualizarBarra(pct) {
    const bar = document.getElementById('mpProgressBar');
    if (!bar) return;
    const p = Math.min(100, Math.max(0, pct));
    bar.style.width = p + '%';
    if (p > 80) bar.style.background = 'linear-gradient(90deg, #dc2626, #ef4444)';
    else if (p > 60) bar.style.background = 'linear-gradient(90deg, #f59e0b, #fbbf24)';
    else bar.style.background = 'linear-gradient(90deg, #4f46e5, #7c3aed)';
}

function _tickSessao() {
    if (!_state.sessao) return;

    _state.sessao.tempo_decorrido = (_state.sessao.tempo_decorrido || 0) + 1;
    _state.sessao.tempo_restante = Math.max(0, (_state.sessao.tempo_restante || 0) - 1);

    const fmt = (s) => {
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
    };

    _setText('mpSessaoDecorrido', fmt(_state.sessao.tempo_decorrido));
    _setText('mpSessaoRestante', fmt(_state.sessao.tempo_restante));

    const pct = Math.min(100, (_state.sessao.tempo_decorrido / 7200) * 100);
    _atualizarBarra(pct);

    if (_state.sessao.tempo_restante === 300) {
        _toast('Sua sessão expira em 5 minutos. Clique em "Renovar Sessão" para continuar.', 'warning');
    }
}

// ============================================================
// RENOVAR SESSÃO
// ============================================================

function _setupBtnRenovar() {
    const btn = document.getElementById('mpBtnRenovar');
    _on(btn, 'click', async () => {
        try {
            const data = await _fetch(_API_LOG + '?acao=renovar', { method: 'POST' });
            if (data.sucesso) {
                if (_state.sessao) {
                    _state.sessao.tempo_decorrido = 0;
                    _state.sessao.tempo_restante = 7200;
                }
                _toast('Sessão renovada com sucesso!', 'success');
            } else {
                _toast(data.mensagem || 'Erro ao renovar sessão.', 'error');
            }
        } catch (e) {
            _toast('Erro ao renovar sessão: ' + e.message, 'error');
        }
    });
}

// ============================================================
// ALTERAR SENHA
// ============================================================

function _setupFormSenha() {
    const form = document.getElementById('mpFormSenha');
    _on(form, 'submit', async (e) => {
        e.preventDefault();
        await _alterarSenha();
    });
}

async function _alterarSenha() {
    const senhaAtual = document.getElementById('mpSenhaAtual')?.value || '';
    const senhaNova = document.getElementById('mpSenhaNova')?.value || '';
    const senhaConfirm = document.getElementById('mpSenhaConfirm')?.value || '';

    if (!senhaAtual || !senhaNova || !senhaConfirm) {
        _toast('Preencha todos os campos de senha.', 'warning');
        return;
    }

    if (senhaNova.length < 6) {
        _toast('A nova senha deve ter pelo menos 6 caracteres.', 'warning');
        return;
    }

    if (senhaNova !== senhaConfirm) {
        _toast('As novas senhas não coincidem.', 'warning');
        return;
    }

    if (!_state.usuarioLogado) {
        _toast('Dados do usuário não carregados. Recarregue a página.', 'error');
        return;
    }

    const btn = document.querySelector('#mpFormSenha button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Alterando...';
    }

    try {
        const u = _state.usuarioLogado;
        const data = await _fetch(_API_USR, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: u.id,
                nome: u.nome,
                email: u.email,
                funcao: u.funcao,
                departamento: u.departamento || '',
                permissao: u.permissao,
                ativo: 1,
                senha: senhaNova,
            }),
        });

        if (data.sucesso) {
            _toast('Senha alterada com sucesso!', 'success');
            document.getElementById('mpFormSenha')?.reset();
        } else {
            _toast(data.mensagem || 'Erro ao alterar senha.', 'error');
        }
    } catch (e) {
        _toast('Erro ao alterar senha: ' + e.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-key"></i> Alterar Senha';
        }
    }
}
