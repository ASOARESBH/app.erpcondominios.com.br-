/**
 * =====================================================
 * JAVASCRIPT: dependentes.js
 * =====================================================
 * 
 * Gerencia a funcionalidade de dependentes com:
 * - Tratamento robusto de erros
 * - Popups informativos
 * - Logging de erros
 * - Validações completas
 * - Requisições AJAX com retry
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 25/01/2026
 * @version 2.0
 */

// Variáveis globais (declaradas no moradores.html)
// let moradorIdAtual = null;  // Vem do moradores.html
// let dependenteEmEdicao = null;  // Será declarado aqui se necessário
let dependenteEmEdicao = null;
const MAX_RETRIES = 3;
const TIMEOUT_REQUISICAO = 30000; // 30 segundos

/**
 * Inicializar funcionalidade de dependentes
 * @param {number} moradorId ID do morador
 */
function inicializarDependentes(moradorId) {
    try {
        moradorIdAtual = moradorId;
        dependenteEmEdicao = null;
        
        // Limpar formulário
        limparFormularioDependente();
        
        // Carregar lista de dependentes
        carregarDependentes();
        
        // Adicionar listener ao formulário
        const form = document.getElementById('dependenteForm');
        if (form) {
            form.removeEventListener('submit', salvarDependente);
            form.addEventListener('submit', salvarDependente);
        }
        
        console.log('[DEPENDENTES] Inicialização concluída para morador ID:', moradorId);
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro na inicialização:', error);
        registrarErroCliente('inicializarDependentes', error.message, error);
    }
}

/**
 * Carregar lista de dependentes via AJAX com retry
 */
function carregarDependentes(tentativa = 1) {
    if (!moradorIdAtual) {
        console.error('[DEPENDENTES] ID do morador não definido');
        mostrarPopupErro('Erro', 'ID do morador não foi definido. Por favor, recarregue a página.');
        return;
    }
    
    const loading = document.getElementById('loadingDependentes');
    if (loading) loading.classList.add('active');
    
    const url = `../api/api_dependentes.php?acao=listar&morador_id=${moradorIdAtual}`;
    
    console.log('[DEPENDENTES] Carregando dependentes - Tentativa', tentativa, '- URL:', url);
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_REQUISICAO);
    
    fetch(url, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json'
        },
        signal: controller.signal
    })
    .then(response => {
        clearTimeout(timeoutId);
        
        console.log('[DEPENDENTES] Resposta recebida - Status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return response.json();
    })
    .then(data => {
        if (loading) loading.classList.remove('active');
        
        console.log('[DEPENDENTES] Dados recebidos:', data);
        
        if (data.sucesso) {
            preencherTabelaDependentes(data.dados);
            console.log('[DEPENDENTES] Tabela preenchida com', data.dados.length, 'dependentes');
        } else {
            const mensagem = data.mensagem || 'Erro desconhecido ao carregar dependentes';
            console.error('[DEPENDENTES] Erro na resposta:', mensagem);
            mostrarPopupErro('Erro ao Carregar', mensagem);
            registrarErroCliente('carregarDependentes', mensagem, {acao: 'listar', morador_id: moradorIdAtual});
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        if (loading) loading.classList.remove('active');
        
        console.error('[DEPENDENTES] Erro na requisição:', error);
        
        // Retry automático
        if (tentativa < MAX_RETRIES && error.name !== 'AbortError') {
            console.log('[DEPENDENTES] Tentando novamente em 2 segundos...');
            setTimeout(() => carregarDependentes(tentativa + 1), 2000);
        } else {
            const mensagem = error.name === 'AbortError' 
                ? 'Timeout na requisição. O servidor pode estar indisponível.'
                : `Erro ao carregar dependentes: ${error.message}`;
            
            mostrarPopupErro('Erro de Conexão', mensagem);
            registrarErroCliente('carregarDependentes', mensagem, {
                tentativa,
                erro: error.message,
                morador_id: moradorIdAtual
            });
        }
    });
}

/**
 * Preencher tabela de dependentes
 * @param {array} dependentes Array de dependentes
 */
function preencherTabelaDependentes(dependentes) {
    try {
        const tbody = document.querySelector('#tabelaDependentes tbody');
        if (!tbody) {
            console.error('[DEPENDENTES] Elemento tbody não encontrado');
            return;
        }
        
        tbody.innerHTML = '';
        
        if (!dependentes || dependentes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem;"><i class="fas fa-inbox"></i> Nenhum dependente cadastrado</td></tr>';
            return;
        }
        
        dependentes.forEach(dependente => {
            try {
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
            } catch (erro) {
                console.error('[DEPENDENTES] Erro ao processar dependente:', dependente, erro);
            }
        });
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro ao preencher tabela:', error);
        mostrarPopupErro('Erro', 'Erro ao exibir dependentes na tabela');
        registrarErroCliente('preencherTabelaDependentes', error.message, error);
    }
}

/**
 * Editar dependente
 * @param {number} id ID do dependente
 */
function editarDependente(id) {
    try {
        console.log('[DEPENDENTES] Iniciando edição do dependente ID:', id);
        
        const url = `../api/api_dependentes.php?acao=obter&id=${id}`;
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_REQUISICAO);
        
        fetch(url, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('[DEPENDENTES] Dados do dependente recebidos:', data);
            
            if (data.sucesso) {
                const dependente = data.dados;
                dependenteEmEdicao = dependente.id;
                
                // Preencher formulário
                document.getElementById('dependenteId').value = dependente.id;
                document.getElementById('nomeCompleto').value = dependente.nome_completo || '';
                document.getElementById('cpf').value = dependente.cpf || '';
                document.getElementById('email').value = dependente.email || '';
                document.getElementById('telefone').value = dependente.telefone || '';
                document.getElementById('celular').value = dependente.celular || '';
                document.getElementById('dataNascimento').value = dependente.data_nascimento || '';
                document.getElementById('parentesco').value = dependente.parentesco || 'Outro';
                document.getElementById('observacao').value = dependente.observacao || '';
                
                // Atualizar botões
                document.getElementById('formTitleDependente').textContent = 'Editar Dependente';
                document.getElementById('btnSalvarDependente').innerHTML = '<i class="fas fa-save"></i> Atualizar Dependente';
                document.getElementById('btnCancelarDependente').style.display = 'inline-block';
                
                // Desabilitar CPF em edição
                document.getElementById('cpf').disabled = true;
                
                // Scroll para formulário
                document.querySelector('#dependentes').scrollIntoView({ behavior: 'smooth' });
                
                console.log('[DEPENDENTES] Formulário preenchido para edição');
                
            } else {
                const mensagem = data.mensagem || 'Erro ao carregar dependente';
                console.error('[DEPENDENTES] Erro na resposta:', mensagem);
                mostrarPopupErro('Erro', mensagem);
                registrarErroCliente('editarDependente', mensagem, {id});
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            console.error('[DEPENDENTES] Erro ao editar dependente:', error);
            
            const mensagem = error.name === 'AbortError' 
                ? 'Timeout na requisição'
                : `Erro ao carregar dependente: ${error.message}`;
            
            mostrarPopupErro('Erro', mensagem);
            registrarErroCliente('editarDependente', mensagem, {id, erro: error.message});
        });
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro na função editarDependente:', error);
        mostrarPopupErro('Erro', 'Erro ao iniciar edição');
        registrarErroCliente('editarDependente', error.message, error);
    }
}

/**
 * Salvar dependente (criar ou atualizar)
 * @param {event} event Evento do formulário
 */
function salvarDependente(event) {
    event.preventDefault();
    
    try {
        console.log('[DEPENDENTES] Iniciando salvamento de dependente');
        
        // Validar formulário
        if (!validarFormularioDependente()) {
            console.warn('[DEPENDENTES] Validação do formulário falhou');
            return;
        }
        
        // Preparar dados
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
        
        console.log('[DEPENDENTES] Dados preparados:', dados);
        
        // Determinar ação
        const acao = dependenteEmEdicao ? 'atualizar' : 'criar';
        const url = dependenteEmEdicao 
            ? `../api/api_dependentes.php?acao=${acao}&id=${dependenteEmEdicao}`
            : `../api/api_dependentes.php?acao=${acao}`;
        
        const metodo = dependenteEmEdicao ? 'PUT' : 'POST';
        
        console.log('[DEPENDENTES] Enviando requisição - Método:', metodo, '- URL:', url);
        
        // Mostrar indicador de carregamento
        const btnSalvar = document.getElementById('btnSalvarDependente');
        const textoOriginal = btnSalvar.innerHTML;
        btnSalvar.disabled = true;
        btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_REQUISICAO);
        
        // Enviar requisição
        fetch(url, {
            method: metodo,
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(dados),
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            
            console.log('[DEPENDENTES] Resposta recebida - Status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('[DEPENDENTES] Resposta da API:', data);
            
            btnSalvar.disabled = false;
            btnSalvar.innerHTML = textoOriginal;
            
            if (data.sucesso) {
                console.log('[DEPENDENTES] Dependente salvo com sucesso');
                console.log('[DEPENDENTES] Dados retornados:', data.dados);
                
                // Verificar se foi confirmado no banco
                const confirmado = data.dados && data.dados.confirmado === true;
                const dependenteId = data.dados && data.dados.id;
                
                if (confirmado && dependenteId) {
                    console.log('[DEPENDENTES] Dependente confirmado no banco - ID:', dependenteId);
                    mostrarPopupSucesso('✅ Sucesso!', data.mensagem || 'Dependente salvo com sucesso!');
                    
                    // Limpar formulário
                    limparFormularioDependente();
                    
                    // Recarregar lista
                    carregarDependentes();
                } else {
                    console.warn('[DEPENDENTES] Dependente salvo mas não confirmado no banco');
                    mostrarPopupAviso('Atenção', 'Dependente pode não ter sido salvo corretamente. Verifique a lista.');
                    carregarDependentes();
                }
            } else {
                const mensagem = data.mensagem || 'Erro desconhecido ao salvar dependente';
                console.error('[DEPENDENTES] Erro na resposta:', mensagem);
                mostrarPopupErro('Erro ao Salvar', mensagem);
                registrarErroCliente('salvarDependente', mensagem, {acao, dados});
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            
            btnSalvar.disabled = false;
            btnSalvar.innerHTML = textoOriginal;
            
            console.error('[DEPENDENTES] Erro ao salvar dependente:', error);
            
            const mensagem = error.name === 'AbortError' 
                ? 'Timeout na requisição. O servidor pode estar indisponível.'
                : `Erro ao salvar dependente: ${error.message}`;
            
            mostrarPopupErro('Erro de Conexão', mensagem);
            registrarErroCliente('salvarDependente', mensagem, {
                acao,
                dados,
                erro: error.message
            });
        });
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro na função salvarDependente:', error);
        mostrarPopupErro('Erro', 'Erro ao processar formulário');
        registrarErroCliente('salvarDependente', error.message, error);
    }
}

/**
 * Alterar status do dependente (ativar/inativar)
 * @param {number} id ID do dependente
 * @param {number} statusAtual Status atual (1=ativo, 0=inativo)
 */
function alternarStatusDependente(id, statusAtual) {
    try {
        const novoStatus = statusAtual === 1 ? 0 : 1;
        const acao = novoStatus === 1 ? 'ativar' : 'inativar';
        const confirmacao = novoStatus === 1 
            ? 'Tem certeza que deseja ATIVAR este dependente?'
            : 'Tem certeza que deseja INATIVAR este dependente? Seus veículos também serão inativados.';
        
        if (!confirm(confirmacao)) {
            return;
        }
        
        console.log('[DEPENDENTES] Alterando status do dependente ID:', id, '- Novo status:', novoStatus);
        
        const url = `../api/api_dependentes.php?acao=${acao}&id=${id}`;
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_REQUISICAO);
        
        fetch(url, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('[DEPENDENTES] Resposta ao alterar status:', data);
            
            if (data.sucesso) {
                mostrarPopupSucesso('Sucesso!', data.mensagem || 'Status alterado com sucesso!');
                carregarDependentes();
            } else {
                const mensagem = data.mensagem || 'Erro ao alterar status';
                console.error('[DEPENDENTES] Erro na resposta:', mensagem);
                mostrarPopupErro('Erro', mensagem);
                registrarErroCliente('alternarStatusDependente', mensagem, {id, acao});
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            console.error('[DEPENDENTES] Erro ao alterar status:', error);
            
            const mensagem = error.name === 'AbortError' 
                ? 'Timeout na requisição'
                : `Erro ao alterar status: ${error.message}`;
            
            mostrarPopupErro('Erro', mensagem);
            registrarErroCliente('alternarStatusDependente', mensagem, {id, acao, erro: error.message});
        });
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro na função alternarStatusDependente:', error);
        mostrarPopupErro('Erro', 'Erro ao alterar status');
        registrarErroCliente('alternarStatusDependente', error.message, error);
    }
}

/**
 * Deletar dependente
 * @param {number} id ID do dependente
 */
function deletarDependente(id) {
    try {
        if (!confirm('Tem certeza que deseja DELETAR este dependente? Seus veículos também serão deletados.')) {
            return;
        }
        
        console.log('[DEPENDENTES] Deletando dependente ID:', id);
        
        const url = `../api/api_dependentes.php?acao=deletar&id=${id}`;
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_REQUISICAO);
        
        fetch(url, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('[DEPENDENTES] Resposta ao deletar:', data);
            
            if (data.sucesso) {
                mostrarPopupSucesso('Sucesso!', data.mensagem || 'Dependente deletado com sucesso!');
                carregarDependentes();
            } else {
                const mensagem = data.mensagem || 'Erro ao deletar dependente';
                console.error('[DEPENDENTES] Erro na resposta:', mensagem);
                mostrarPopupErro('Erro', mensagem);
                registrarErroCliente('deletarDependente', mensagem, {id});
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            console.error('[DEPENDENTES] Erro ao deletar dependente:', error);
            
            const mensagem = error.name === 'AbortError' 
                ? 'Timeout na requisição'
                : `Erro ao deletar dependente: ${error.message}`;
            
            mostrarPopupErro('Erro', mensagem);
            registrarErroCliente('deletarDependente', mensagem, {id, erro: error.message});
        });
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro na função deletarDependente:', error);
        mostrarPopupErro('Erro', 'Erro ao deletar dependente');
        registrarErroCliente('deletarDependente', error.message, error);
    }
}

/**
 * Validar formulário de dependente
 * @returns {boolean} True se válido
 */
function validarFormularioDependente() {
    try {
        const nomeCompleto = document.getElementById('nomeCompleto').value.trim();
        const cpf = document.getElementById('cpf').value.trim();
        const celular = document.getElementById('celular').value.trim();
        const email = document.getElementById('email').value.trim();
        
        // Validar nome
        if (!nomeCompleto || nomeCompleto.length < 3) {
            mostrarPopupAviso('Validação', 'Nome completo deve ter pelo menos 3 caracteres');
            return false;
        }
        
        // Validar CPF
        if (!cpf || cpf.length < 11) {
            mostrarPopupAviso('Validação', 'CPF é obrigatório e deve ter 11 dígitos');
            return false;
        }
        
        if (!validarCPF(cpf)) {
            mostrarPopupAviso('Validação', 'CPF inválido. Verifique o número digitado.');
            return false;
        }
        
        // Validar email se preenchido
        if (email && !validarEmail(email)) {
            mostrarPopupAviso('Validação', 'Email inválido');
            return false;
        }
        
        // Validar celular se preenchido
        if (celular && celular.length < 10) {
            mostrarPopupAviso('Validação', 'Celular deve ter pelo menos 10 dígitos');
            return false;
        }
        
        return true;
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro ao validar formulário:', error);
        mostrarPopupErro('Erro', 'Erro ao validar formulário');
        return false;
    }
}

/**
 * Validar CPF (algoritmo módulo 11)
 * @param {string} cpf CPF a validar
 * @returns {boolean} True se válido
 */
function validarCPF(cpf) {
    cpf = cpf.replace(/[^\d]/g, '');
    
    if (cpf.length !== 11) {
        return false;
    }
    
    if (/^(\d)\1{10}$/.test(cpf)) {
        return false;
    }
    
    let soma = 0;
    for (let i = 0; i < 9; i++) {
        soma += cpf[i] * (10 - i);
    }
    let resto = soma % 11;
    let digito1 = resto < 2 ? 0 : 11 - resto;
    
    if (cpf[9] != digito1) {
        return false;
    }
    
    soma = 0;
    for (let i = 0; i < 10; i++) {
        soma += cpf[i] * (11 - i);
    }
    resto = soma % 11;
    let digito2 = resto < 2 ? 0 : 11 - resto;
    
    if (cpf[10] != digito2) {
        return false;
    }
    
    return true;
}

/**
 * Validar email
 * @param {string} email Email a validar
 * @returns {boolean} True se válido
 */
function validarEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
}

/**
 * Limpar formulário de dependente
 */
function limparFormularioDependente() {
    try {
        dependenteEmEdicao = null;
        
        const form = document.getElementById('dependenteForm');
        if (form) {
            form.reset();
        }
        
        document.getElementById('dependenteId').value = '';
        document.getElementById('formTitleDependente').textContent = 'Novo Dependente';
        document.getElementById('btnSalvarDependente').innerHTML = '<i class="fas fa-save"></i> Salvar Dependente';
        document.getElementById('btnCancelarDependente').style.display = 'none';
        document.getElementById('cpf').disabled = false;
        
        console.log('[DEPENDENTES] Formulário limpo');
        
    } catch (error) {
        console.error('[DEPENDENTES] Erro ao limpar formulário:', error);
    }
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

/**
 * Formatar entrada de CPF em tempo real
 * @param {element} element Elemento input
 */
function formatarInputCPF(element) {
    let valor = element.value.replace(/[^\d]/g, '');
    
    if (valor.length > 11) {
        valor = valor.substring(0, 11);
    }
    
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
 * Mostrar popup de sucesso
 * @param {string} titulo Título do popup
 * @param {string} mensagem Mensagem
 */
function mostrarPopupSucesso(titulo, mensagem) {
    mostrarPopup(titulo, mensagem, 'success');
}

/**
 * Mostrar popup de erro
 * @param {string} titulo Título do popup
 * @param {string} mensagem Mensagem
 */
function mostrarPopupErro(titulo, mensagem) {
    mostrarPopup(titulo, mensagem, 'error');
}

/**
 * Mostrar popup de aviso
 * @param {string} titulo Título do popup
 * @param {string} mensagem Mensagem
 */
function mostrarPopupAviso(titulo, mensagem) {
    mostrarPopup(titulo, mensagem, 'warning');
}

/**
 * Mostrar popup genérico
 * @param {string} titulo Título
 * @param {string} mensagem Mensagem
 * @param {string} tipo Tipo (success, error, warning, info)
 */
function mostrarPopup(titulo, mensagem, tipo = 'info') {
    try {
        // Criar overlay
        const overlay = document.createElement('div');
        overlay.className = 'popup-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        `;
        
        // Cores por tipo
        const cores = {
            success: { bg: '#dcfce7', border: '#22c55e', icon: 'fa-check-circle', color: '#166534' },
            error: { bg: '#fee2e2', border: '#ef4444', icon: 'fa-exclamation-circle', color: '#991b1b' },
            warning: { bg: '#fef3c7', border: '#f59e0b', icon: 'fa-exclamation-triangle', color: '#92400e' },
            info: { bg: '#dbeafe', border: '#3b82f6', icon: 'fa-info-circle', color: '#1e40af' }
        };
        
        const cor = cores[tipo] || cores.info;
        
        // Criar popup
        const popup = document.createElement('div');
        popup.className = 'popup-modal';
        popup.style.cssText = `
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 90%;
            padding: 2rem;
            border-left: 5px solid ${cor.border};
            animation: slideIn 0.3s ease-out;
        `;
        
        popup.innerHTML = `
            <div style="display: flex; align-items: flex-start; gap: 1rem;">
                <div style="font-size: 2rem; color: ${cor.color};">
                    <i class="fas ${cor.icon}"></i>
                </div>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 0.5rem 0; color: #1e293b; font-size: 1.25rem;">
                        ${titulo}
                    </h3>
                    <p style="margin: 0 0 1.5rem 0; color: #64748b; line-height: 1.5;">
                        ${mensagem}
                    </p>
                    <button onclick="fecharPopup()" style="
                        background: ${cor.border};
                        color: white;
                        border: none;
                        padding: 0.6rem 1.5rem;
                        border-radius: 8px;
                        cursor: pointer;
                        font-weight: 600;
                        transition: 0.2s;
                    " onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                        OK
                    </button>
                </div>
            </div>
        `;
        
        overlay.appendChild(popup);
        document.body.appendChild(overlay);
        
        // Adicionar estilos de animação
        if (!document.querySelector('style[data-popup-styles]')) {
            const style = document.createElement('style');
            style.setAttribute('data-popup-styles', 'true');
            style.textContent = `
                @keyframes slideIn {
                    from {
                        transform: translateY(-20px);
                        opacity: 0;
                    }
                    to {
                        transform: translateY(0);
                        opacity: 1;
                    }
                }
            `;
            document.head.appendChild(style);
        }
        
        // Fechar ao clicar no overlay
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                fecharPopup();
            }
        });
        
        // Fechar com ESC
        const fecharComESC = function(e) {
            if (e.key === 'Escape') {
                fecharPopup();
                document.removeEventListener('keydown', fecharComESC);
            }
        };
        document.addEventListener('keydown', fecharComESC);
        
        console.log('[POPUP]', tipo.toUpperCase(), '-', titulo, '-', mensagem);
        
    } catch (error) {
        console.error('[POPUP] Erro ao mostrar popup:', error);
        alert(`${titulo}\n\n${mensagem}`);
    }
}

/**
 * Fechar popup
 */
function fecharPopup() {
    const overlay = document.querySelector('.popup-overlay');
    if (overlay) {
        overlay.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => overlay.remove(), 300);
    }
}

/**
 * Registrar erro do cliente no servidor
 * @param {string} funcao Nome da função
 * @param {string} mensagem Mensagem de erro
 * @param {object} contexto Contexto adicional
 */
function registrarErroCliente(funcao, mensagem, contexto = {}) {
    try {
        const dados = {
            funcao,
            mensagem,
            contexto: JSON.stringify(contexto),
            url: window.location.href,
            timestamp: new Date().toISOString()
        };
        
        // Enviar para servidor (sem bloquear)
        fetch('../api/registrar_erro_cliente.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(dados)
        }).catch(err => console.error('[ERRO_LOGGER] Falha ao registrar erro:', err));
        
    } catch (error) {
        console.error('[ERRO_LOGGER] Erro ao registrar:', error);
    }
}

// Exportar funções para uso global
window.inicializarDependentes = inicializarDependentes;
window.carregarDependentes = carregarDependentes;
window.editarDependente = editarDependente;
window.alternarStatusDependente = alternarStatusDependente;
window.deletarDependente = deletarDependente;
window.salvarDependente = salvarDependente;
window.validarFormularioDependente = validarFormularioDependente;
window.limparFormularioDependente = limparFormularioDependente;
window.preencherTabelaDependentes = preencherTabelaDependentes;
window.formatarCPF = formatarCPF;
window.formatarInputCPF = formatarInputCPF;
window.mostrarPopup = mostrarPopup;
window.mostrarPopupSucesso = mostrarPopupSucesso;
window.mostrarPopupErro = mostrarPopupErro;
window.mostrarPopupAviso = mostrarPopupAviso;
window.fecharPopup = fecharPopup;
window.registrarErroCliente = registrarErroCliente;
window.validarCPF = validarCPF;
window.validarEmail = validarEmail;

console.log('[DEPENDENTES] Script carregado com sucesso');
