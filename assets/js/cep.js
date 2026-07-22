// Função para buscar endereço por CEP
async function buscarCEP(cep) {
    // Remover caracteres não numéricos
    cep = cep.replace(/\D/g, '');
    
    // Verificar se o CEP tem 8 dígitos
    if (cep.length !== 8) {
        return null;
    }
    
    try {
        // Buscar na API do ViaCEP
        const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        const data = await response.json();
        
        // Verificar se houve erro
        if (data.erro) {
            return null;
        }
        
        return {
            logradouro: data.logradouro || '',
            bairro: data.bairro || '',
            cidade: data.localidade || '',
            uf: data.uf || ''
        };
    } catch (error) {
        console.error('Erro ao buscar CEP:', error);
        return null;
    }
}

// Função para preencher campos de endereço
function preencherEndereco(endereco) {
    const campos = {
        'endereco_externo_logradouro': endereco.logradouro,
        'endereco_externo_bairro': endereco.bairro,
        'endereco_externo_cidade': endereco.cidade,
        'endereco_externo_uf': endereco.uf
    };
    
    for (const [campo, valor] of Object.entries(campos)) {
        const elemento = document.getElementById(campo);
        if (elemento) {
            elemento.value = valor;
        }
    }
}

// Função para configurar busca automática de CEP
function configurarBuscaCEP() {
    const campoCEP = document.getElementById('endereco_externo_cep');
    if (!campoCEP) return;
    
    campoCEP.addEventListener('blur', async function() {
        const cep = this.value;
        
        if (cep.length >= 8) {
            // Mostrar loading
            const loading = document.createElement('span');
            loading.textContent = ' Buscando...';
            loading.style.color = '#666';
            loading.id = 'cep-loading';
            this.parentNode.appendChild(loading);
            
            const endereco = await buscarCEP(cep);
            
            // Remover loading
            const loadingElement = document.getElementById('cep-loading');
            if (loadingElement) {
                loadingElement.remove();
            }
            
            if (endereco) {
                preencherEndereco(endereco);
                
                // Focar no campo de número
                const campoNumero = document.getElementById('endereco_externo_numero');
                if (campoNumero) {
                    campoNumero.focus();
                }
            } else {
                alert('CEP não encontrado. Verifique o número digitado.');
            }
        }
    });
    
    // Formatar CEP enquanto digita
    campoCEP.addEventListener('input', function() {
        let valor = this.value.replace(/\D/g, '');
        if (valor.length > 5) {
            valor = valor.replace(/(\d{5})(\d)/, '$1-$2');
        }
        this.value = valor;
    });
}

// Função para formatar CPF
function formatarCPF(input) {
    let valor = input.value.replace(/\D/g, '');
    if (valor.length > 11) {
        valor = valor.substring(0, 11);
    }
    
    if (valor.length > 9) {
        valor = valor.replace(/(\d{3})(\d{3})(\d{3})(\d)/, '$1.$2.$3-$4');
    } else if (valor.length > 6) {
        valor = valor.replace(/(\d{3})(\d{3})(\d)/, '$1.$2.$3');
    } else if (valor.length > 3) {
        valor = valor.replace(/(\d{3})(\d)/, '$1.$2');
    }
    
    input.value = valor;
}

// Função para formatar telefone
function formatarTelefone(input) {
    let valor = input.value.replace(/\D/g, '');
    if (valor.length > 11) {
        valor = valor.substring(0, 11);
    }
    
    if (valor.length > 10) {
        valor = valor.replace(/(\d{2})(\d{5})(\d)/, '($1) $2-$3');
    } else if (valor.length > 6) {
        valor = valor.replace(/(\d{2})(\d{4})(\d)/, '($1) $2-$3');
    } else if (valor.length > 2) {
        valor = valor.replace(/(\d{2})(\d)/, '($1) $2');
    }
    
    input.value = valor;
}

// Função para validar CPF
function validarCPF(cpf) {
    cpf = cpf.replace(/\D/g, '');
    
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
        return false;
    }
    
    let soma = 0;
    for (let i = 0; i < 9; i++) {
        soma += parseInt(cpf.charAt(i)) * (10 - i);
    }
    let resto = 11 - (soma % 11);
    if (resto === 10 || resto === 11) resto = 0;
    if (resto !== parseInt(cpf.charAt(9))) return false;
    
    soma = 0;
    for (let i = 0; i < 10; i++) {
        soma += parseInt(cpf.charAt(i)) * (11 - i);
    }
    resto = 11 - (soma % 11);
    if (resto === 10 || resto === 11) resto = 0;
    if (resto !== parseInt(cpf.charAt(10))) return false;
    
    return true;
}

// Configurar formatação automática quando a página carregar
document.addEventListener('DOMContentLoaded', function() {
    configurarBuscaCEP();
    
    // Configurar formatação de CPF
    const campoCPF = document.getElementById('cpf');
    if (campoCPF) {
        campoCPF.addEventListener('input', function() {
            formatarCPF(this);
        });
        
        campoCPF.addEventListener('blur', function() {
            const cpf = this.value.replace(/\D/g, '');
            if (cpf.length === 11 && !validarCPF(cpf)) {
                alert('CPF inválido. Verifique o número digitado.');
                this.focus();
            }
        });
    }
    
    // Configurar formatação de telefone
    const campoTelefone = document.getElementById('telefone');
    if (campoTelefone) {
        campoTelefone.addEventListener('input', function() {
            formatarTelefone(this);
        });
    }
});

