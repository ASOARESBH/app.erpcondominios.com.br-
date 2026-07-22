/**
 * =====================================================
 * JAVASCRIPT: integracao_veiculos_dependentes.js
 * =====================================================
 * 
 * Integração de dependentes no cadastro de veículos:
 * - Carregamento de dependentes ao selecionar morador
 * - Seleção de dependente como proprietário
 * - Validações
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 25/01/2026
 * @version 1.0
 */

// Variável global para armazenar dependentes
let dependentesCarregados = [];

/**
 * Inicializar integração de dependentes em veículos
 */
function inicializarIntegracaoDependentes() {
    // Adicionar listener ao select de morador
    const selectMorador = document.getElementById('morador');
    if (selectMorador) {
        selectMorador.addEventListener('change', carregarDependentesDoMorador);
    }
    
    // Criar container para select de dependentes se não existir
    criarSelectDependentes();
}

/**
 * Criar select de dependentes no formulário
 */
function criarSelectDependentes() {
    const formGrid = document.querySelector('.form-grid');
    if (!formGrid || document.getElementById('dependenteContainer')) {
        return; // Já existe
    }
    
    // Criar div para o select
    const divDependente = document.createElement('div');
    divDependente.id = 'dependenteContainer';
    divDependente.style.display = 'none';
    divDependente.innerHTML = `
        <label>Dependente (Proprietário do Veículo)</label>
        <select id="dependente">
            <option value="">Selecione um dependente (opcional)</option>
        </select>
        <small style="color: #64748b; display: block; margin-top: 0.25rem;">
            <i class="fas fa-info-circle"></i> Se deixar em branco, o veículo será do morador
        </small>
    `;
    
    // Inserir após o select de morador
    const selectMorador = document.getElementById('morador');
    if (selectMorador && selectMorador.parentElement) {
        selectMorador.parentElement.parentElement.insertBefore(divDependente, selectMorador.parentElement.nextSibling);
    }
}

/**
 * Carregar dependentes ao selecionar morador
 * @param {event} event Evento de mudança
 */
function carregarDependentesDoMorador(event) {
    const moradorId = event.target.value;
    const containerDependente = document.getElementById('dependenteContainer');
    const selectDependente = document.getElementById('dependente');
    
    if (!moradorId) {
        // Limpar e ocultar select de dependentes
        if (containerDependente) containerDependente.style.display = 'none';
        if (selectDependente) selectDependente.innerHTML = '<option value="">Selecione um dependente (opcional)</option>';
        dependentesCarregados = [];
        return;
    }
    
    // Carregar dependentes via AJAX
    fetch(`../api/api_dependentes.php?acao=listar&morador_id=${moradorId}&apenas_ativos=true`, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.sucesso && data.dados && data.dados.length > 0) {
            dependentesCarregados = data.dados;
            preencherSelectDependentes(data.dados);
            
            // Mostrar select de dependentes
            if (containerDependente) {
                containerDependente.style.display = 'block';
            }
        } else {
            // Sem dependentes
            if (containerDependente) {
                containerDependente.style.display = 'none';
            }
            if (selectDependente) {
                selectDependente.innerHTML = '<option value="">Nenhum dependente cadastrado</option>';
            }
            dependentesCarregados = [];
        }
    })
    .catch(error => {
        console.error('Erro ao carregar dependentes:', error);
        if (containerDependente) {
            containerDependente.style.display = 'none';
        }
    });
}

/**
 * Preencher select de dependentes
 * @param {array} dependentes Array de dependentes
 */
function preencherSelectDependentes(dependentes) {
    const selectDependente = document.getElementById('dependente');
    if (!selectDependente) return;
    
    let html = '<option value="">Selecione um dependente (opcional)</option>';
    
    dependentes.forEach(dependente => {
        html += `<option value="${dependente.id}">${dependente.nome_completo} (${dependente.parentesco})</option>`;
    });
    
    selectDependente.innerHTML = html;
}

/**
 * Obter dados do veículo para envio (modificar função existente)
 * Esta função deve ser integrada ao código existente de veículos.js
 * 
 * Adicionar ao objeto de dados do veículo:
 * dependente_id: document.getElementById('dependente').value || null
 */
function obterDadosVeiculoComDependente() {
    return {
        placa: document.getElementById('placa').value,
        modelo: document.getElementById('modelo').value,
        cor: document.getElementById('cor').value,
        tag: document.getElementById('tag').value,
        morador_id: document.getElementById('morador').value,
        dependente_id: document.getElementById('dependente').value || null
    };
}

/**
 * Validar seleção de dependente
 * @returns {boolean} True se válido
 */
function validarSelecaoDependente() {
    const moradorId = document.getElementById('morador').value;
    const dependenteId = document.getElementById('dependente').value;
    
    // Se não selecionou morador, erro
    if (!moradorId) {
        return false;
    }
    
    // Se selecionou dependente, validar se pertence ao morador
    if (dependenteId) {
        const dependente = dependentesCarregados.find(d => d.id == dependenteId);
        if (!dependente) {
            console.error('Dependente selecionado não pertence ao morador');
            return false;
        }
    }
    
    return true;
}

/**
 * Limpar seleção de dependente ao cancelar edição
 */
function limparSelecaoDependente() {
    const selectDependente = document.getElementById('dependente');
    if (selectDependente) {
        selectDependente.value = '';
    }
}

/**
 * Ao editar veículo, carregar dependentes e selecionar o correto
 * @param {object} veiculo Dados do veículo
 */
function preencherSelectDependenteEmEdicao(veiculo) {
    const moradorId = veiculo.morador_id;
    const dependenteId = veiculo.dependente_id;
    
    if (!moradorId) return;
    
    // Carregar dependentes
    fetch(`../api/api_dependentes.php?acao=listar&morador_id=${moradorId}&apenas_ativos=true`, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.sucesso && data.dados) {
            dependentesCarregados = data.dados;
            preencherSelectDependentes(data.dados);
            
            // Selecionar dependente se houver
            if (dependenteId) {
                const selectDependente = document.getElementById('dependente');
                if (selectDependente) {
                    selectDependente.value = dependenteId;
                }
            }
            
            // Mostrar container
            const containerDependente = document.getElementById('dependenteContainer');
            if (containerDependente && data.dados.length > 0) {
                containerDependente.style.display = 'block';
            }
        }
    })
    .catch(error => {
        console.error('Erro ao carregar dependentes:', error);
    });
}

/**
 * Exibir informações do dependente selecionado
 */
function exibirInfoDependente() {
    const selectDependente = document.getElementById('dependente');
    if (!selectDependente || !selectDependente.value) return;
    
    const dependente = dependentesCarregados.find(d => d.id == selectDependente.value);
    if (!dependente) return;
    
    // Criar badge com informações
    let infoDependente = `
        <div style="background: #dbeafe; border: 1px solid #3b82f6; padding: 0.75rem; border-radius: 8px; margin-top: 0.5rem;">
            <strong>${dependente.nome_completo}</strong><br>
            <small>Parentesco: ${dependente.parentesco}</small><br>
            <small>CPF: ${formatarCPF(dependente.cpf)}</small>
        </div>
    `;
    
    // Adicionar após o select
    const containerDependente = document.getElementById('dependenteContainer');
    let infoDiv = containerDependente.querySelector('.info-dependente');
    
    if (!infoDiv) {
        infoDiv = document.createElement('div');
        infoDiv.className = 'info-dependente';
        containerDependente.appendChild(infoDiv);
    }
    
    infoDiv.innerHTML = infoDependente;
}

/**
 * Formatar CPF para exibição
 * @param {string} cpf CPF a formatar
 * @returns {string} CPF formatado
 */
function formatarCPF(cpf) {
    if (!cpf) return '-';
    cpf = cpf.replace(/[^\d]/g, '');
    if (cpf.length !== 11) return cpf;
    return cpf.substring(0, 3) + '.' + cpf.substring(3, 6) + '.' + cpf.substring(6, 9) + '-' + cpf.substring(9);
}

// Inicializar quando documento estiver pronto
document.addEventListener('DOMContentLoaded', function() {
    inicializarIntegracaoDependentes();
});

// Exportar funções para uso global
window.carregarDependentesDoMorador = carregarDependentesDoMorador;
window.obterDadosVeiculoComDependente = obterDadosVeiculoComDependente;
window.validarSelecaoDependente = validarSelecaoDependente;
window.limparSelecaoDependente = limparSelecaoDependente;
window.preencherSelectDependenteEmEdicao = preencherSelectDependenteEmEdicao;
window.exibirInfoDependente = exibirInfoDependente;
