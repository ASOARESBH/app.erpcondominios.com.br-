/**
 * =====================================================
 * JAVASCRIPT: dependentes_novo.js
 * =====================================================
 * 
 * Gerencia a funcionalidade de dependentes em página separada
 * - Carregamento de dependentes
 * - Validações de formulário
 * - Requisições AJAX
 * - Manipulação do DOM
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 28/01/2026
 * @version 1.0
 */

// Variáveis globais
let moradorIdAtual = null;
let dependenteEmEdicao = null;
const MAX_RETRIES = 3;
const TIMEOUT_REQUISICAO = 30000;

/**
 * Inicializar ao carregar a página
 */
document.addEventListener('DOMContentLoaded', function() {
    // Obter ID do morador da URL
    const params = new URLSearchParams(window.location.search);
    moradorIdAtual = params.get('morador_id');
    
    if (!moradorIdAtual) {
        mostrarAlerta('Erro', 'ID do morador não foi fornecido', 'error');
        setTimeout(() => voltarParaMoradores(), 2000);
        return;
    }
    
    console.log('[DEPENDENTES] Página carregada para morador ID:', moradorIdAtual);
    
    // Carregar informações do morador
    carregarInfoMorador();
    
    // Carregar dependentes
    carregarDependentes();
    
    // Adicionar listeners
    document.getElementById('dependenteForm').addEventListener('submit', salvarDependente);
    document.getElementById('searchNome').addEventListener('keyup', buscarDependentes);
    document.getElementById('searchCPF').addEventListener('keyup', buscarDependentes);
    document.getElementById('searchParentesco').addEventListener('change', buscarDependentes);
});

/**
 * Carregar informações do morador
 */
function carregarInfoMorador() {
    fetch(`../api/api_moradores.php?acao=obter&id=${moradorIdAtual}`, {
        method: 'GET',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.sucesso && data.dados) {
            const morador = data.dados;
            document.getElementById('morador-info').textContent = 
                `Morador: ${morador.nome} - Unidade: ${morador.unidade}`;
        }
    })
    .catch(error => console.error('Erro ao carregar morador:', error));
}

/**
 * Carregar lista de dependentes
 */
function carregarDependentes(tentativa = 1) {
    if (!moradorIdAtual) {
        console.error('[DEPENDENTES] ID do morador não definido');
        mostrarAlerta('Erro', 'ID do morador não foi definido', 'error');
        return;
    }
    
    const loading = document.getElementById('loadingDependentes');
    if (loading) loading.classList.add('active');
    
    const url = `../api/api_dependentes.php?acao=listar&morador_id=${moradorIdAtual}`;
    
    console.log('[DEPENDENTES] Carregando dependentes - Tentativa', tentativa);
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_REQUISICAO);
    
    fetch(url, {
        method: 'GET',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        signal: controller.signal
    })
    .then(response => {
        clearTimeout(timeoutId);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    })
    .then(data => {
        if (loading) loading.classList.remove('active');
        
        if (data.sucesso) {
            preencherTabelaDependentes(data.dados || []);
            console.log('[DEPENDENTES] Tabela preenchida com', (data.dados || []).length, 'dependentes');
        } else {
            mostrarAlerta('Erro', data.mensagem || 'Erro ao carregar dependentes', 'error');
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        if (loading) loading.classList.remove('active');
        
        if (tentativa < MAX_RETRIES && error.name !== 'AbortError') {
            console.log('[DEPENDENTES] Tentando novamente em 2 segundos...');
            setTimeout(() => carregarDependentes(tentativa + 1), 2000);
        } else {
            const mensagem = error.name === 'AbortError' 
                ? 'Timeout na requisição'
                : `Erro ao carregar: ${error.message}`;
            mostrarAlerta('Erro', mensagem, 'error');
        }
    });
}

/**
 * Preencher tabela de dependentes
 */
function preencherTabelaDependentes(dependentes) {
    const tbody = document.querySelector('#tabelaDependentes tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    if (!dependentes || dependentes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem;"><i class="fas fa-inbox"></i> Nenhum dependente cadastrado</td></tr>';
        return;
    }
    
    dependentes.forEach(dependente => {
        const statusBadge = dependente.ativo === 1 
            ? '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Ativo</span>'
            : '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Inativo</span>';
        
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${dependente.id}</td>
            <td><strong>${dependente.nome_completo || 'N/A'}</strong></td>
            <td>${formatarCPF(dependente.cpf)}</td>
            <td>${dependente.parentesco || 'Outro'}</td>
            <td>${dependente.celular || '-'}</td>
            <td>${statusBadge}</td>
            <td class="actions">
                <button class="btn-edit" onclick="editarDependente(${dependente.id})" title="Editar">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn-toggle" onclick="alternarStatusDependente(${dependente.id}, ${dependente.ativo})" title="${dependente.ativo === 1 ? 'Inativar' : 'Ativar'}">
                    <i class="fas fa-${dependente.ativo === 1 ? 'ban' : 'check'}"></i>
                </button>
                <button class="btn-delete" onclick="deletarDependente(${dependente.id})" title="Deletar">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

/**
 * Salvar dependente (criar ou atualizar)
 */
function salvarDependente(event) {
    event.preventDefault();
    
    if (!validarFormulario()) return;
    
    const dados = {
        nome_completo: document.getElementById('nomeCompleto').value.trim(),
        cpf: document.getElementById('cpf').value.trim(),
        email: document.getElementById('email').value.trim(),
        telefone: document.getElementById('telefone').value.trim(),
        celular: document.getElementById('celular').value.trim(),
        data_nascimento: document.getElementById('dataNascimento').value,
        parentesco: document.getElementById('parentesco').value,
        observacao: document.getElementById('observacao').value.trim()
    };
    
    if (!dependenteEmEdicao) {
        dados.morador_id = moradorIdAtual;
    }
    
    const acao = dependenteEmEdicao ? 'atualizar' : 'criar';
    const url = dependenteEmEdicao 
        ? `../api/api_dependentes.php?acao=${acao}&id=${dependenteEmEdicao}`
        : `../api/api_dependentes.php?acao=${acao}`;
    
    const metodo = dependenteEmEdicao ? 'PUT' : 'POST';
    
    fetch(url, {
        method: metodo,
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dados)
    })
    .then(response => response.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta('Sucesso', data.mensagem, 'success');
            limparFormulario();
            carregarDependentes();
            abrirAba(null, 'lista-dependentes');
        } else {
            mostrarAlerta('Erro', data.mensagem || 'Erro ao salvar', 'error');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        mostrarAlerta('Erro', 'Erro ao salvar dependente', 'error');
    });
}

/**
 * Editar dependente
 */
function editarDependente(id) {
    fetch(`../api/api_dependentes.php?acao=obter&id=${id}`, {
        method: 'GET',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.sucesso) {
            const dep = data.dados;
            dependenteEmEdicao = dep.id;
            
            document.getElementById('nomeCompleto').value = dep.nome_completo || '';
            document.getElementById('cpf').value = dep.cpf || '';
            document.getElementById('email').value = dep.email || '';
            document.getElementById('telefone').value = dep.telefone || '';
            document.getElementById('celular').value = dep.celular || '';
            document.getElementById('dataNascimento').value = dep.data_nascimento || '';
            document.getElementById('parentesco').value = dep.parentesco || 'Outro';
            document.getElementById('observacao').value = dep.observacao || '';
            
            document.getElementById('cpf').disabled = true;
            
            abrirAba(null, 'novo-dependente');
            document.querySelector('.form-section h3').textContent = 'Editar Dependente';
            document.querySelector('#dependenteForm button[type="submit"]').innerHTML = '<i class="fas fa-save"></i> Atualizar Dependente';
        } else {
            mostrarAlerta('Erro', data.mensagem || 'Erro ao carregar dependente', 'error');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        mostrarAlerta('Erro', 'Erro ao carregar dependente', 'error');
    });
}

/**
 * Deletar dependente
 */
function deletarDependente(id) {
    if (!confirm('Tem certeza que deseja DELETAR este dependente?')) return;
    
    fetch(`../api/api_dependentes.php?acao=deletar&id=${id}`, {
        method: 'DELETE',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta('Sucesso', data.mensagem, 'success');
            carregarDependentes();
        } else {
            mostrarAlerta('Erro', data.mensagem || 'Erro ao deletar', 'error');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        mostrarAlerta('Erro', 'Erro ao deletar dependente', 'error');
    });
}

/**
 * Alternar status do dependente
 */
function alternarStatusDependente(id, statusAtual) {
    const novoStatus = statusAtual === 1 ? 0 : 1;
    const acao = novoStatus === 1 ? 'ativar' : 'inativar';
    const confirmacao = novoStatus === 1 
        ? 'Tem certeza que deseja ATIVAR este dependente?'
        : 'Tem certeza que deseja INATIVAR este dependente?';
    
    if (!confirm(confirmacao)) return;
    
    fetch(`../api/api_dependentes.php?acao=${acao}&id=${id}`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta('Sucesso', data.mensagem, 'success');
            carregarDependentes();
        } else {
            mostrarAlerta('Erro', data.mensagem || 'Erro ao alterar status', 'error');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        mostrarAlerta('Erro', 'Erro ao alterar status', 'error');
    });
}

/**
 * Validar formulário
 */
function validarFormulario() {
    const nome = document.getElementById('nomeCompleto').value.trim();
    const cpf = document.getElementById('cpf').value.trim();
    const parentesco = document.getElementById('parentesco').value;
    
    if (!nome) {
        mostrarAlerta('Validação', 'Nome completo é obrigatório', 'error');
        return false;
    }
    
    if (!cpf || cpf.length < 11) {
        mostrarAlerta('Validação', 'CPF inválido', 'error');
        return false;
    }
    
    if (!parentesco) {
        mostrarAlerta('Validação', 'Parentesco é obrigatório', 'error');
        return false;
    }
    
    return true;
}

/**
 * Buscar dependentes
 */
function buscarDependentes() {
    const nome = document.getElementById('searchNome').value.toLowerCase();
    const cpf = document.getElementById('searchCPF').value.toLowerCase();
    const parentesco = document.getElementById('searchParentesco').value.toLowerCase();
    
    const rows = document.querySelectorAll('#tabelaDependentes tbody tr');
    
    rows.forEach(row => {
        const rowNome = row.cells[1]?.textContent.toLowerCase() || '';
        const rowCpf = row.cells[2]?.textContent.toLowerCase() || '';
        const rowParentesco = row.cells[3]?.textContent.toLowerCase() || '';
        
        const match = (!nome || rowNome.includes(nome)) &&
                     (!cpf || rowCpf.includes(cpf)) &&
                     (!parentesco || rowParentesco.includes(parentesco));
        
        row.style.display = match ? '' : 'none';
    });
}

/**
 * Limpar filtros
 */
function limparFiltros() {
    document.getElementById('searchNome').value = '';
    document.getElementById('searchCPF').value = '';
    document.getElementById('searchParentesco').value = '';
    carregarDependentes();
}

/**
 * Limpar formulário
 */
function limparFormulario() {
    document.getElementById('dependenteForm').reset();
    document.getElementById('cpf').disabled = false;
    dependenteEmEdicao = null;
    document.querySelector('.form-section h3').textContent = 'Novo Dependente';
    document.querySelector('#dependenteForm button[type="submit"]').innerHTML = '<i class="fas fa-save"></i> Salvar Dependente';
}

/**
 * Formatar CPF
 */
function formatarCPF(cpf) {
    if (!cpf) return '-';
    cpf = cpf.replace(/[^\d]/g, '');
    if (cpf.length !== 11) return cpf;
    return cpf.substring(0, 3) + '.' + cpf.substring(3, 6) + '.' + cpf.substring(6, 9) + '-' + cpf.substring(9);
}

/**
 * Formatar entrada de CPF
 */
function formatarInputCPF(element) {
    let valor = element.value.replace(/[^\d]/g, '');
    if (valor.length > 11) valor = valor.substring(0, 11);
    if (valor.length > 9) {
        valor = valor.substring(0, 3) + '.' + valor.substring(3, 6) + '.' + valor.substring(6, 9) + '-' + valor.substring(9);
    } else if (valor.length > 6) {
        valor = valor.substring(0, 3) + '.' + valor.substring(3, 6) + '.' + valor.substring(6);
    } else if (valor.length > 3) {
        valor = valor.substring(0, 3) + '.' + valor.substring(3);
    }
    element.value = valor;
}

/**
 * Abrir aba
 */
function abrirAba(event, abaNome) {
    if (event) event.preventDefault();
    
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
    
    document.getElementById(abaNome).classList.add('active');
    event?.target?.classList.add('active');
    
    // Encontrar o botão correto e marcar como ativo
    document.querySelectorAll('.tab-button').forEach(btn => {
        if (btn.textContent.includes(abaNome === 'novo-dependente' ? 'Novo' : 'Lista')) {
            btn.classList.add('active');
        }
    });
}

/**
 * Mostrar alerta
 */
function mostrarAlerta(titulo, mensagem, tipo = 'info') {
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5); display: flex; align-items: center;
        justify-content: center; z-index: 10000;
    `;
    
    const cores = {
        success: { bg: '#dcfce7', border: '#22c55e', text: '#166534' },
        error: { bg: '#fee2e2', border: '#f87171', text: '#b91c1c' },
        info: { bg: '#dbeafe', border: '#3b82f6', text: '#1e40af' }
    };
    
    const cor = cores[tipo] || cores.info;
    
    const popup = document.createElement('div');
    popup.style.cssText = `
        background: white; padding: 2rem; border-radius: 12px;
        border-left: 4px solid ${cor.border}; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        max-width: 400px; animation: slideIn 0.3s ease;
    `;
    
    popup.innerHTML = `
        <h3 style="color: ${cor.text}; margin-bottom: 0.5rem;">${titulo}</h3>
        <p style="color: #64748b; margin-bottom: 1rem;">${mensagem}</p>
        <button onclick="this.closest('[style*=fixed]').remove()" style="
            background: ${cor.border}; color: white; border: none;
            padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;
        ">OK</button>
    `;
    
    overlay.appendChild(popup);
    document.body.appendChild(overlay);
    
    setTimeout(() => overlay.remove(), 5000);
}

/**
 * Voltar para moradores
 */
function voltarParaMoradores() {
    window.location.href = 'moradores.html';
}

console.log('[DEPENDENTES_NOVO] Script carregado com sucesso');
