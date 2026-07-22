/**
 * Relatórios de Inventário - Módulo de Página
 * Gera relatórios e estatísticas do inventário patrimonial
 */

let todosItens = [];
let usuarios = [];

/**
 * Inicializar página
 */
export function init() {
    console.log('[RelatoriosInventario] Inicializando...');

    carregarUsuarios();
    carregarDados();

    // Configurar listeners dos filtros para atualizar automaticamente
    document.getElementById('tipoRelatorio').addEventListener('change', atualizarRelatorio);
    document.getElementById('filtroSituacao').addEventListener('change', atualizarRelatorio);
    document.getElementById('filtroStatus').addEventListener('change', atualizarRelatorio);
    document.getElementById('filtroResponsavel').addEventListener('change', atualizarRelatorio);

    // Expor funções globais
    window.RelatoriosInventarioPage = {
        gerarRelatorio,
        exportarExcel
    };

    console.log('[RelatoriosInventario] ✅ Inicializado');
}

/**
 * Destruir página (cleanup)
 */
export function destroy() {
    console.log('[RelatoriosInventario] Destruindo...');

    // Remover event listeners
    const selects = ['tipoRelatorio', 'filtroSituacao', 'filtroStatus', 'filtroResponsavel'];
    selects.forEach(id => {
        const elem = document.getElementById(id);
        if (elem) {
            elem.removeEventListener('change', atualizarRelatorio);
        }
    });

    // Limpar namespace global
    if (window.RelatoriosInventarioPage) {
        delete window.RelatoriosInventarioPage;
    }

    // Limpar dados
    todosItens = [];
    usuarios = [];

    console.log('[RelatoriosInventario] ✅ Destruído');
}

// ========== FUNÇÕES DE CARREGAMENTO ==========

function carregarUsuarios() {
    fetch('../api/api_usuarios.php')
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                usuarios = data.dados.filter(u => u.ativo == 1);
                const select = document.getElementById('filtroResponsavel');
                if (select) {
                    select.innerHTML = '<option value="">Todos</option>';
                    usuarios.forEach(u => {
                        select.innerHTML += `<option value="${u.id}">${u.nome}</option>`;
                    });
                }
                console.log('[RelatoriosInventario] ✅ Usuários carregados:', usuarios.length);
            }
        })
        .catch(err => {
            console.error('[RelatoriosInventario] ❌ Erro ao carregar usuários:', err);
        });
}

function carregarDados() {
    fetch('../api/api_inventario.php')
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                todosItens = data.dados;
                atualizarCards();
                atualizarRelatorio();
                console.log('[RelatoriosInventario] ✅ Dados carregados:', todosItens.length, 'itens');
            }
        })
        .catch(err => {
            console.error('[RelatoriosInventario] ❌ Erro ao carregar dados:', err);
        });
}

// ========== ATUALIZAÇÃO DE CARDS ==========

function atualizarCards() {
    const totalItens = todosItens.length;
    const totalAtivos = todosItens.filter(i => i.status === 'ativo').length;
    const totalInativos = todosItens.filter(i => i.status === 'inativo').length;
    const valorTotal = todosItens.reduce((sum, i) => sum + parseFloat(i.valor || 0), 0);

    const elemTotalItens = document.getElementById('totalItens');
    const elemTotalAtivos = document.getElementById('totalAtivos');
    const elemTotalInativos = document.getElementById('totalInativos');
    const elemValorTotal = document.getElementById('valorTotal');

    if (elemTotalItens) elemTotalItens.textContent = totalItens;
    if (elemTotalAtivos) elemTotalAtivos.textContent = totalAtivos;
    if (elemTotalInativos) elemTotalInativos.textContent = totalInativos;
    if (elemValorTotal) elemValorTotal.textContent = `R$ ${valorTotal.toFixed(2).replace('.', ',')}`;
}

// ========== ATUALIZAÇÃO DE RELATÓRIO ==========

function atualizarRelatorio() {
    const tipo = document.getElementById('tipoRelatorio').value;
    const situacao = document.getElementById('filtroSituacao').value;
    const status = document.getElementById('filtroStatus').value;
    const responsavel = document.getElementById('filtroResponsavel').value;

    let itensFiltrados = todosItens.filter(item => {
        if (situacao && item.situacao !== situacao) return false;
        if (status && item.status !== status) return false;
        if (responsavel && item.tutela_usuario_id != responsavel) return false;
        return true;
    });

    // Atualizar título
    const titulos = {
        'geral': 'Relatório Geral',
        'situacao': 'Relatório por Situação',
        'status': 'Relatório por Status',
        'responsavel': 'Relatório por Responsável',
        'baixas': 'Relatório de Itens Baixados'
    };

    const elemTitulo = document.getElementById('tituloRelatorio');
    if (elemTitulo) {
        elemTitulo.textContent = titulos[tipo];
    }

    // Filtrar por tipo
    if (tipo === 'baixas') {
        itensFiltrados = itensFiltrados.filter(i => i.status === 'inativo');
        renderizarTabelaBaixas(itensFiltrados);
        const secaoBaixas = document.getElementById('secaoBaixas');
        if (secaoBaixas) {
            secaoBaixas.style.display = 'block';
        }
    } else {
        const secaoBaixas = document.getElementById('secaoBaixas');
        if (secaoBaixas) {
            secaoBaixas.style.display = 'none';
        }
    }

    renderizarTabela(itensFiltrados);
}

// ========== RENDERIZAÇÃO ==========

function renderizarTabela(itens) {
    const tbody = document.getElementById('tabelaRelatorio');
    if (!tbody) return;

    if (itens.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Nenhum item encontrado</td></tr>';
        return;
    }

    tbody.innerHTML = itens.map(item => `
        <tr>
            <td><strong>${item.numero_patrimonio}</strong></td>
            <td>${item.nome_item}</td>
            <td>${item.fabricante || '-'}</td>
            <td><span class="badge badge-${item.situacao === 'imobilizado' ? 'primary' : 'warning'}">${item.situacao === 'imobilizado' ? 'Imobilizado' : 'Circulante'}</span></td>
            <td><span class="badge badge-${item.status === 'ativo' ? 'success' : 'danger'}">${item.status === 'ativo' ? 'Ativo' : 'Inativo'}</span></td>
            <td>R$ ${parseFloat(item.valor || 0).toFixed(2)}</td>
            <td>${item.tutela_nome || '-'}</td>
        </tr>
    `).join('');
}

function renderizarTabelaBaixas(itens) {
    const container = document.getElementById('listaBaixas');
    if (!container) return;

    if (itens.length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #64748b;">Nenhum item baixado encontrado</p>';
        return;
    }

    container.innerHTML = itens.map(item => `
        <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #ef4444;">
            <h4 style="color: #1e293b; margin-bottom: 0.5rem;">${item.numero_patrimonio} - ${item.nome_item}</h4>
            <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 0.5rem;">
                <strong>Data da Baixa:</strong> ${item.data_baixa_formatada || item.data_baixa || 'Não informada'}
            </p>
            <p style="color: #64748b; font-size: 0.9rem;">
                <strong>Motivo:</strong> ${item.motivo_baixa || 'Não informado'}
            </p>
        </div>
    `).join('');
}

// ========== EXPORTAÇÃO ==========

function gerarRelatorio() {
    alert('Funcionalidade de geração de PDF será implementada em breve!');
    // Aqui você pode integrar com uma biblioteca de PDF como jsPDF
    console.log('[RelatoriosInventario] Gerar PDF solicitado');
}

function exportarExcel() {
    alert('Funcionalidade de exportação para Excel será implementada em breve!');
    // Aqui você pode integrar com uma biblioteca como SheetJS
    console.log('[RelatoriosInventario] Exportar Excel solicitado');
}
