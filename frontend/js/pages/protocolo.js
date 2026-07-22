/**
 * Protocolo de Mercadorias - Módulo de Página
 * Gerencia protocolos de entrega de mercadorias
 */

let protocolos = [];
let unidades = [];
let usuarios = [];
let refreshInterval = null; // Para gerenciar o setInterval

/**
 * Inicializar página
 */
export function init() {
    console.log('[Protocolo] Inicializando...');

    carregarUnidades();
    carregarUsuarios();
    carregarProtocolos();
    definirDataHoraAtual();

    // Configurar listener do formulário principal
    document.getElementById('formProtocolo').addEventListener('submit', handleSubmitProtocolo);

    // Configurar listener do formulário de entrega
    document.getElementById('formEntrega').addEventListener('submit', handleSubmitEntrega);

    // Configurar listener da cascata unidade -> moradores
    document.getElementById('unidadeId').addEventListener('change', carregarMoradores);

    // Auto-refresh a cada 60 segundos
    refreshInterval = setInterval(carregarProtocolos, 60000);

    // Expor funções globais para uso em onclick
    window.ProtocoloPage = {
        editarProtocolo,
        excluirProtocolo,
        abrirModalEntrega,
        fecharModalEntrega,
        limparFormulario
    };

    console.log('[Protocolo] ✅ Inicializado');
}

/**
 * Destruir página (cleanup)
 */
export function destroy() {
    console.log('[Protocolo] Destruindo...');

    // Limpar interval crítico
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
        console.log('[Protocolo] ✅ setInterval limpo');
    }

    // Remover event listeners
    const formProtocolo = document.getElementById('formProtocolo');
    if (formProtocolo) {
        formProtocolo.removeEventListener('submit', handleSubmitProtocolo);
    }

    const formEntrega = document.getElementById('formEntrega');
    if (formEntrega) {
        formEntrega.removeEventListener('submit', handleSubmitEntrega);
    }

    const unidadeSelect = document.getElementById('unidadeId');
    if (unidadeSelect) {
        unidadeSelect.removeEventListener('change', carregarMoradores);
    }

    // Limpar namespace global
    if (window.ProtocoloPage) {
        delete window.ProtocoloPage;
    }

    // Limpar data
    protocolos = [];
    unidades = [];
    usuarios = [];

    console.log('[Protocolo] ✅ Destruído');
}

// ========== FUNÇÕES DE CARREGAMENTO ==========

function definirDataHoraAtual() {
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    const elem = document.getElementById('dataHoraRecebimento');
    if (elem) {
        elem.value = agora.toISOString().slice(0, 16);
    }
}

function carregarUnidades() {
    fetch('../api/api_unidades.php?acao=select')
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                // Garantir array (compatível com resposta paginada ou select)
                const lista = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);
                // Ordenar unidades numericamente
                unidades = lista.sort((a, b) => {
                    const numA = parseInt(a.nome.replace(/\D/g, '')) || 0;
                    const numB = parseInt(b.nome.replace(/\D/g, '')) || 0;
                    return numA - numB;
                });

                const select = document.getElementById('unidadeId');
                if (select) {
                    select.innerHTML = '<option value="">Selecione...</option>';
                    unidades.forEach(u => {
                        select.innerHTML += `<option value="${u.id}" data-nome="${u.nome}">${u.nome}</option>`;
                    });
                }
                console.log('[Protocolo] ✅ Unidades carregadas:', unidades.length);
            } else {
                mostrarAlerta('Erro ao carregar unidades', 'error');
            }
        })
        .catch(err => {
            console.error('[Protocolo] ❌ Erro ao carregar unidades:', err);
            mostrarAlerta('Erro ao conectar com o servidor', 'error');
        });
}

function carregarMoradores() {
    const unidadeId = document.getElementById('unidadeId').value;
    const moradorSelect = document.getElementById('moradorId');

    if (!moradorSelect) return;

    if (!unidadeId) {
        moradorSelect.innerHTML = '<option value="">Selecione a unidade primeiro</option>';
        return;
    }

    moradorSelect.innerHTML = '<option value="">Carregando...</option>';

    const unidadeSelecionada = unidades.find(u => u.id == unidadeId);
    const nomeUnidade = unidadeSelecionada ? unidadeSelecionada.nome : '';

    fetch(`../api/api_moradores.php?unidade=${encodeURIComponent(nomeUnidade)}&por_pagina=0`)
        .then(r => r.json())
        .then(data => {
            // api_moradores retorna dados paginados: { itens: [...], total, ... }
            const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
            if (data.sucesso && moradores.length > 0) {
                moradorSelect.innerHTML = '<option value="">Selecione...</option>';
                moradores.forEach(m => {
                    moradorSelect.innerHTML += `<option value="${m.id}">${m.nome}</option>`;
                });
                console.log('[Protocolo] ✅ Moradores carregados:', moradores.length);
            } else {
                moradorSelect.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
            }
        })
        .catch(err => {
            console.error('[Protocolo] ❌ Erro ao carregar moradores:', err);
            moradorSelect.innerHTML = '<option value="">Erro ao conectar com o servidor</option>';
            mostrarAlerta('Erro ao conectar com o servidor', 'error');
        });
}

function carregarUsuarios() {
    fetch('../api/api_usuarios.php')
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                usuarios = data.dados.filter(u => u.ativo == 1);
                const select = document.getElementById('recebedorPortaria');
                if (select) {
                    select.innerHTML = '<option value="">Selecione...</option>';
                    usuarios.forEach(u => {
                        const funcao = u.funcao ? ` - ${u.funcao}` : '';
                        select.innerHTML += `<option value="${u.nome}">${u.nome}${funcao}</option>`;
                    });
                }
                console.log('[Protocolo] ✅ Usuários carregados:', usuarios.length);
            } else {
                mostrarAlerta('Erro ao carregar usuários', 'error');
            }
        })
        .catch(err => {
            console.error('[Protocolo] ❌ Erro ao carregar usuários:', err);
            mostrarAlerta('Erro ao conectar com o servidor', 'error');
        });
}

function carregarProtocolos() {
    fetch('../api/api_protocolos.php')
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                protocolos = data.dados;
                renderizarTabela(protocolos);
            } else {
                const tbody = document.getElementById('tabelaProtocolos');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #ef4444;">Erro ao carregar protocolos</td></tr>';
                }
            }
        })
        .catch(err => {
            console.error('[Protocolo] ❌ Erro ao carregar protocolos:', err);
            const tbody = document.getElementById('tabelaProtocolos');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #ef4444;">Erro ao conectar com o servidor</td></tr>';
            }
        });
}

// ========== RENDERIZAÇÃO ==========

function renderizarTabela(dados) {
    const tbody = document.getElementById('tabelaProtocolos');
    if (!tbody) return;

    if (dados.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #94a3b8;">Nenhum protocolo cadastrado</td></tr>';
        return;
    }

    tbody.innerHTML = dados.map(p => `
        <tr>
            <td>${p.id}</td>
            <td>${p.unidade_nome || '-'}</td>
            <td>${p.morador_nome || '-'}</td>
            <td>${p.descricao_mercadoria}</td>
            <td>${p.codigo_nf || '-'}</td>
            <td>${p.pagina || '-'}</td>
            <td>${formatarDataHora(p.data_hora_recebimento)}</td>
            <td>${p.recebedor_portaria}</td>
            <td><span class="badge badge-${p.status === 'entregue' ? 'success' : 'warning'}">${p.status === 'entregue' ? 'Entregue' : 'Pendente'}</span></td>
            <td>
                ${p.status === 'pendente' ? `
                    <button class="btn-primary btn-sm" onclick="window.ProtocoloPage.abrirModalEntrega(${p.id})"><i class="fas fa-check"></i> Receber</button>
                    <button class="btn-secondary btn-sm" onclick="window.ProtocoloPage.editarProtocolo(${p.id})"><i class="fas fa-edit"></i></button>
                    <button class="btn-danger btn-sm" onclick="window.ProtocoloPage.excluirProtocolo(${p.id})"><i class="fas fa-trash"></i></button>
                ` : `
                    <span style="color: #10b981;"><i class="fas fa-check-circle"></i> Entregue em ${formatarDataHora(p.data_hora_entrega)}</span>
                `}
            </td>
        </tr>
    `).join('');
}

function formatarDataHora(dt) {
    if (!dt) return '-';
    const d = new Date(dt);
    return d.toLocaleString('pt-BR');
}

// ========== HANDLERS DE FORMULÁRIOS ==========

function handleSubmitProtocolo(e) {
    e.preventDefault();
    salvarProtocolo();
}

function handleSubmitEntrega(e) {
    e.preventDefault();
    registrarEntrega();
}

function salvarProtocolo() {
    const id = document.getElementById('protocoloId').value;
    const dados = {
        unidade_id: document.getElementById('unidadeId').value,
        morador_id: document.getElementById('moradorId').value,
        descricao_mercadoria: document.getElementById('descricaoMercadoria').value,
        codigo_nf: document.getElementById('codigoNf').value,
        pagina: document.getElementById('pagina').value,
        data_hora_recebimento: document.getElementById('dataHoraRecebimento').value,
        recebedor_portaria: document.getElementById('recebedorPortaria').value,
        observacao: document.getElementById('observacao').value
    };

    const metodo = id ? 'PUT' : 'POST';
    if (id) dados.id = id;

    fetch('../api/api_protocolos.php', {
        method: metodo,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dados)
    })
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                mostrarAlerta('Protocolo salvo com sucesso!', 'success');
                limparFormulario();
                carregarProtocolos();
            } else {
                mostrarAlerta(data.mensagem, 'error');
            }
        })
        .catch(err => {
            console.error('[Protocolo] ❌ Erro ao salvar:', err);
            mostrarAlerta('Erro ao salvar protocolo', 'error');
        });
}

function editarProtocolo(id) {
    const p = protocolos.find(x => x.id == id);
    if (!p) return;

    if (p.status === 'entregue') {
        mostrarAlerta('Não é possível editar protocolo já entregue', 'error');
        return;
    }

    document.getElementById('protocoloId').value = p.id;
    document.getElementById('unidadeId').value = p.unidade_id;
    carregarMoradores();
    setTimeout(() => {
        document.getElementById('moradorId').value = p.morador_id;
    }, 1000);
    document.getElementById('descricaoMercadoria').value = p.descricao_mercadoria;
    document.getElementById('codigoNf').value = p.codigo_nf || '';
    document.getElementById('pagina').value = p.pagina || '';
    document.getElementById('dataHoraRecebimento').value = p.data_hora_recebimento.replace(' ', 'T').slice(0, 16);
    document.getElementById('recebedorPortaria').value = p.recebedor_portaria;
    document.getElementById('observacao').value = p.observacao || '';

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function excluirProtocolo(id) {
    const p = protocolos.find(x => x.id == id);
    if (p && p.status === 'entregue') {
        mostrarAlerta('Não é possível excluir protocolo já entregue', 'error');
        return;
    }

    if (!confirm('Deseja realmente excluir este protocolo?')) return;

    fetch('../api/api_protocolos.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                mostrarAlerta('Protocolo excluído com sucesso!', 'success');
                carregarProtocolos();
            } else {
                mostrarAlerta(data.mensagem, 'error');
            }
        });
}

// ========== MODAL DE ENTREGA ==========

function abrirModalEntrega(id) {
    document.getElementById('entregaProtocoloId').value = id;
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    document.getElementById('dataHoraEntrega').value = agora.toISOString().slice(0, 16);
    document.getElementById('modalEntrega').classList.add('active');
}

function fecharModalEntrega() {
    document.getElementById('modalEntrega').classList.remove('active');
    const formEntrega = document.getElementById('formEntrega');
    if (formEntrega) {
        formEntrega.reset();
    }
}

function registrarEntrega() {
    const dados = {
        acao: 'entregar',
        id: document.getElementById('entregaProtocoloId').value,
        nome_recebedor_morador: document.getElementById('nomeRecebedorMorador').value,
        data_hora_entrega: document.getElementById('dataHoraEntrega').value
    };

    fetch('../api/api_protocolos.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: Object.keys(dados).map(k => `${k}=${encodeURIComponent(dados[k])}`).join('&')
    })
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                mostrarAlerta('Entrega registrada com sucesso!', 'success');
                fecharModalEntrega();
                carregarProtocolos();
            } else {
                mostrarAlerta(data.mensagem, 'error');
            }
        });
}

// ========== UTILITÁRIOS ==========

function limparFormulario() {
    const form = document.getElementById('formProtocolo');
    if (form) {
        form.reset();
    }
    document.getElementById('protocoloId').value = '';
    const moradorSelect = document.getElementById('moradorId');
    if (moradorSelect) {
        moradorSelect.innerHTML = '<option value="">Selecione a unidade primeiro</option>';
    }
    definirDataHoraAtual();
}

function mostrarAlerta(mensagem, tipo) {
    const container = document.getElementById('alertContainer');
    if (!container) return;

    const div = document.createElement('div');
    div.className = `alert alert-${tipo}`;
    div.innerHTML = `<i class="fas fa-${tipo === 'success' ? 'check-circle' : tipo === 'info' ? 'info-circle' : 'exclamation-circle'}"></i> ${mensagem}`;
    container.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}
