/**
 * Marketplace - Gerenciamento de Fornecedores - Módulo de Página
 * Gerencia fornecedores e ramos de atividade
 */

let fornecedores = [];
let fornecedoresFiltrados = [];
let tabAtiva = 'fornecedores';

/**
 * Inicializar página
 */
export function init() {
    console.log('[Marketplace] Inicializando...');

    carregarEstatisticas();
    carregarFornecedores();

    // Configurar listener do formulário
    const formRamo = document.getElementById('formRamo');
    if (formRamo) {
        formRamo.addEventListener('submit', handleSubmitRamo);
    }

    // Expor funções globais
    window.MarketplacePage = {
        mudarTab,
        aplicarFiltros,
        limparFiltros,
        carregarFornecedores,
        aprovar,
        rejeitar,
        toggleStatus,
        abrirModalRamo,
        fecharModalRamo,
        editarRamo,
        excluirRamo
    };

    console.log('[Marketplace] ✅ Inicializado');
}

/**
 * Destruir página (cleanup)
 */
export function destroy() {
    console.log('[Marketplace] Destruindo...');

    // Remover event listeners
    const formRamo = document.getElementById('formRamo');
    if (formRamo) {
        formRamo.removeEventListener('submit', handleSubmitRamo);
    }

    // Limpar namespace global
    if (window.MarketplacePage) {
        delete window.MarketplacePage;
    }

    // Limpar dados
    fornecedores = [];
    fornecedoresFiltrados = [];
    tabAtiva = 'fornecedores';

    console.log('[Marketplace] ✅ Destruído');
}

// ========== GERENCIAMENTO DE TABS ==========

function mudarTab(tab) {
    // Atualizar tabs visuais
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    event.target.closest('.tab').classList.add('active');

    // Atualizar conteúdo
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    if (tab === 'fornecedores') {
        document.getElementById('tabFornecedores').classList.add('active');
    } else if (tab === 'ramos') {
        document.getElementById('tabRamos').classList.add('active');
        carregarRamos();
    }

    tabAtiva = tab;
}

// ========== ESTATÍSTICAS ==========

function carregarEstatisticas() {
    fetch('../api/api_admin_fornecedores.php?acao=estatisticas')
        .then(response => response.json())
        .then(data => {
            if (data.sucesso) {
                const elemTotal = document.getElementById('statTotal');
                const elemAtivos = document.getElementById('statAtivos');
                const elemInativos = document.getElementById('statInativos');
                const elemPendentes = document.getElementById('statPendentes');

                if (elemTotal) elemTotal.textContent = data.dados.total_fornecedores || 0;
                if (elemAtivos) elemAtivos.textContent = data.dados.fornecedores_ativos || 0;
                if (elemInativos) elemInativos.textContent = data.dados.fornecedores_inativos || 0;
                if (elemPendentes) elemPendentes.textContent = data.dados.fornecedores_pendentes || 0;

                console.log('[Marketplace] ✅ Estatísticas carregadas');
            }
        })
        .catch(error => console.error('[Marketplace] ❌ Erro ao carregar estatísticas:', error));
}

// ========== FORNECEDORES ==========

function carregarFornecedores() {
    const loading = document.getElementById('loading');
    if (loading) {
        loading.classList.add('active');
    }

    fetch('../api/api_admin_fornecedores.php?acao=listar_todos')
        .then(response => response.json())
        .then(data => {
            if (loading) {
                loading.classList.remove('active');
            }

            if (data.sucesso) {
                fornecedores = data.dados;
                fornecedoresFiltrados = fornecedores;
                renderizarTabelaFornecedores();
                console.log('[Marketplace] ✅ Fornecedores carregados:', fornecedores.length);
            } else {
                mostrarAlerta('Erro ao carregar fornecedores: ' + data.mensagem, 'error');
            }
        })
        .catch(error => {
            if (loading) {
                loading.classList.remove('active');
            }
            mostrarAlerta('Erro ao carregar fornecedores', 'error');
            console.error('[Marketplace] ❌ Erro:', error);
        });
}

function renderizarTabelaFornecedores() {
    const tbody = document.getElementById('fornecedoresBody');
    if (!tbody) return;

    if (fornecedoresFiltrados.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 2rem;">Nenhum fornecedor encontrado</td></tr>';
        return;
    }

    tbody.innerHTML = fornecedoresFiltrados.map(f => `
        <tr>
            <td>${f.id}</td>
            <td><strong>${f.nome_estabelecimento}</strong></td>
            <td>${f.cpf_cnpj}</td>
            <td><i class="fas ${f.ramo_icone}"></i> ${f.ramo_atividade}</td>
            <td>${f.telefone || '-'}</td>
            <td>${f.email || '-'}</td>
            <td><span class="badge badge-${f.is_ativo == 1 ? 'success' : 'danger'}">${f.is_ativo == 1 ? 'Ativo' : 'Inativo'}</span></td>
            <td><span class="badge badge-${f.is_aprovado == 1 ? 'success' : 'warning'}">${f.is_aprovado == 1 ? 'Aprovado' : 'Pendente'}</span></td>
            <td>${formatarData(f.data_cadastro)}</td>
            <td class="actions">
                ${f.is_aprovado == 0 ? `
                    <button class="btn-success btn-sm" onclick="window.MarketplacePage.aprovar(${f.id})"><i class="fas fa-check"></i></button>
                    <button class="btn-danger btn-sm" onclick="window.MarketplacePage.rejeitar(${f.id})"><i class="fas fa-times"></i></button>
                ` : `
                    <button class="btn-warning btn-sm" onclick="window.MarketplacePage.toggleStatus(${f.id}, ${f.is_ativo})"><i class="fas fa-${f.is_ativo == 1 ? 'ban' : 'check'}"></i></button>
                `}
            </td>
        </tr>
    `).join('');
}

function aplicarFiltros() {
    const status = document.getElementById('filtroStatus').value;
    const aprovacao = document.getElementById('filtroAprovacao').value;
    const busca = document.getElementById('filtroBusca').value.toLowerCase();

    fornecedoresFiltrados = fornecedores.filter(f => {
        if (status !== '' && f.is_ativo != status) return false;
        if (aprovacao !== '' && f.is_aprovado != aprovacao) return false;
        if (busca && !f.nome_estabelecimento.toLowerCase().includes(busca) && !f.cpf_cnpj.includes(busca)) return false;
        return true;
    });

    renderizarTabelaFornecedores();
}

function limparFiltros() {
    document.getElementById('filtroStatus').value = '';
    document.getElementById('filtroAprovacao').value = '';
    document.getElementById('filtroBusca').value = '';
    fornecedoresFiltrados = fornecedores;
    renderizarTabelaFornecedores();
}

async function aprovar(id) {
    if (!confirm('Aprovar este fornecedor?')) return;

    try {
        const response = await fetch('../api/api_admin_fornecedores.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'aprovar', id })
        });

        const data = await response.json();
        if (data.sucesso) {
            mostrarAlerta('Fornecedor aprovado com sucesso!', 'success');
            carregarFornecedores();
            carregarEstatisticas();
        } else {
            mostrarAlerta(data.mensagem, 'error');
        }
    } catch (error) {
        console.error('[Marketplace] ❌ Erro ao aprovar:', error);
        mostrarAlerta('Erro ao aprovar fornecedor', 'error');
    }
}

async function rejeitar(id) {
    if (!confirm('Rejeitar este fornecedor?')) return;

    try {
        const response = await fetch('../api/api_admin_fornecedores.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'rejeitar', id })
        });

        const data = await response.json();
        if (data.sucesso) {
            mostrarAlerta('Fornecedor rejeitado', 'success');
            carregarFornecedores();
            carregarEstatisticas();
        } else {
            mostrarAlerta(data.mensagem, 'error');
        }
    } catch (error) {
        console.error('[Marketplace] ❌ Erro ao rejeitar:', error);
        mostrarAlerta('Erro ao rejeitar fornecedor', 'error');
    }
}

async function toggleStatus(id, statusAtual) {
    const novoStatus = statusAtual == 1 ? 0 : 1;
    const acao = novoStatus == 1 ? 'ativar' : 'desativar';

    try {
        const response = await fetch('../api/api_admin_fornecedores.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao, id })
        });

        const data = await response.json();
        if (data.sucesso) {
            mostrarAlerta(`Fornecedor ${acao}do com sucesso!`, 'success');
            carregarFornecedores();
            carregarEstatisticas();
        } else {
            mostrarAlerta(data.mensagem, 'error');
        }
    } catch (error) {
        console.error('[Marketplace] ❌ Erro ao alterar status:', error);
        mostrarAlerta('Erro ao alterar status', 'error');
    }
}

// ========== RAMOS DE ATIVIDADE ==========

function carregarRamos() {
    const loading = document.getElementById('loadingRamos');
    if (loading) {
        loading.classList.add('active');
    }

    fetch('../api/api_ramos_atividade.php?acao=listar')
        .then(response => response.json())
        .then(data => {
            if (loading) {
                loading.classList.remove('active');
            }

            if (data.sucesso) {
                renderizarTabelaRamos(data.dados);
                console.log('[Marketplace] ✅ Ramos carregados:', data.dados.length);
            }
        })
        .catch(error => {
            if (loading) {
                loading.classList.remove('active');
            }
            console.error('[Marketplace] ❌ Erro ao carregar ramos:', error);
        });
}

function renderizarTabelaRamos(ramos) {
    const tbody = document.getElementById('ramosBody');
    if (!tbody) return;

    if (ramos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 2rem;">Nenhum ramo encontrado</td></tr>';
        return;
    }

    tbody.innerHTML = ramos.map(r => `
        <tr>
            <td>${r.id}</td>
            <td><i class="fas ${r.icone}"></i></td>
            <td><strong>${r.nome}</strong></td>
            <td>${r.descricao || '-'}</td>
            <td>${r.total_fornecedores || 0}</td>
            <td><span class="badge badge-${r.is_ativo == 1 ? 'success' : 'danger'}">${r.is_ativo == 1 ? 'Ativo' : 'Inativo'}</span></td>
            <td>${formatarData(r.data_cadastro)}</td>
            <td class="actions">
                <button class="btn-warning btn-sm" onclick="window.MarketplacePage.editarRamo(${r.id})"><i class="fas fa-edit"></i></button>
                <button class="btn-danger btn-sm" onclick="window.MarketplacePage.excluirRamo(${r.id})"><i class="fas fa-trash"></i></button>
            </td>
        </tr>
    `).join('');
}

function abrirModalRamo(id = null) {
    document.getElementById('modalRamo').classList.add('active');

    if (id) {
        // Modo edição - carregar dados
        fetch(`../api/api_ramos_atividade.php?acao=obter&id=${id}`)
            .then(r => r.json())
            .then(data => {
                if (data.sucesso) {
                    document.getElementById('ramoId').value = data.dados.id;
                    document.getElementById('ramoNome').value = data.dados.nome;
                    document.getElementById('ramoDescricao').value = data.dados.descricao || '';
                    document.getElementById('ramoIcone').value = data.dados.icone || '';
                    document.getElementById('modalRamoTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Ramo de Atividade';
                }
            });
    } else {
        // Modo criação - limpar form
        document.getElementById('formRamo').reset();
        document.getElementById('ramoId').value = '';
        document.getElementById('modalRamoTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Ramo de Atividade';
    }
}

function fecharModalRamo() {
    document.getElementById('modalRamo').classList.remove('active');
    document.getElementById('formRamo').reset();
}

function handleSubmitRamo(e) {
    e.preventDefault();

    const formData = {
        acao: document.getElementById('ramoId').value ? 'editar' : 'criar',
        id: document.getElementById('ramoId').value,
        nome: document.getElementById('ramoNome').value,
        descricao: document.getElementById('ramoDescricao').value,
        icone: document.getElementById('ramoIcone').value
    };

    fetch('../api/api_ramos_atividade.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
    })
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                mostrarAlerta('Ramo salvo com sucesso!', 'success');
                fecharModalRamo();
                carregarRamos();
            } else {
                mostrarAlerta(data.mensagem, 'error');
            }
        })
        .catch(error => {
            console.error('[Marketplace] ❌ Erro ao salvar ramo:', error);
            mostrarAlerta('Erro ao salvar ramo', 'error');
        });
}

function editarRamo(id) {
    abrirModalRamo(id);
}

async function excluirRamo(id) {
    if (!confirm('Excluir este ramo de atividade?')) return;

    try {
        const response = await fetch('../api/api_ramos_atividade.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'excluir', id })
        });

        const data = await response.json();
        if (data.sucesso) {
            mostrarAlerta('Ramo excluído com sucesso!', 'success');
            carregarRamos();
        } else {
            mostrarAlerta(data.mensagem, 'error');
        }
    } catch (error) {
        console.error('[Marketplace] ❌ Erro ao excluir ramo:', error);
        mostrarAlerta('Erro ao excluir ramo', 'error');
    }
}

// ========== UTILITÁRIOS ==========

function formatarData(dt) {
    if (!dt) return '-';
    const d = new Date(dt);
    return d.toLocaleDateString('pt-BR');
}

function mostrarAlerta(mensagem, tipo) {
    const container = document.getElementById('alertContainer');
    if (!container) return;

    const div = document.createElement('div');
    div.className = `alert alert-${tipo}`;
    div.textContent = mensagem;
    container.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}
