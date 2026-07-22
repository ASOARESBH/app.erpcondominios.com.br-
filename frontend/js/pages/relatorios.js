/**
 * Relatorios Page Module
 */

const API_REGISTROS = '../api/api_registros.php';

let todosRegistros = [];
let registrosFiltrados = [];
let termoBuscaLocal = '';
let audioCtx = null;

export function init() {
    console.log('[Relatorios] Inicializando...');

    setupActions();
    setDatasPadrao();
    prepararAudioContext();
    carregarTodosRegistros();

    window.RelatoriosPage = {
        gerar: aplicarFiltros,
        limpar: limparFiltros,
        exportarCSV,
        gerarPDF,
        rankingPDF,
        rankingCSV
    };
}

export function destroy() {
    console.log('[Relatorios] Limpando...');
    delete window.RelatoriosPage;
    todosRegistros = [];
    registrosFiltrados = [];
    termoBuscaLocal = '';
    audioCtx = null;
}

function setupActions() {
    bindClick('btnAplicarFiltros', aplicarFiltros);
    bindClick('btnLimparFiltros', limparFiltros);
    bindClick('btnExportarCsv', exportarCSV);
    bindClick('btnGerarPDF', gerarPDF);
    bindClick('btnAtualizarRelatorio', carregarTodosRegistros);
    bindClick('btnRankingPDF', rankingPDF);
    bindClick('btnRankingCSV', rankingCSV);

    const buscaLocal = document.getElementById('buscaLocalRelatorio');
    if (buscaLocal) {
        buscaLocal.addEventListener('input', () => {
            termoBuscaLocal = buscaLocal.value || '';
            aplicarBuscaLocalTabela();
        });
    }

    ['tipoRelatorio', 'apenasLiberados', 'tipoMorador', 'tipoVisitante', 'tipoPrestador']
        .forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', aplicarFiltros);
        });
}

function bindClick(id, fn) {
    const btn = document.getElementById(id);
    if (btn) btn.addEventListener('click', fn);
}

function setDatasPadrao() {
    const hoje = new Date();
    const trintaDiasAtras = new Date();
    trintaDiasAtras.setDate(hoje.getDate() - 30);

    const dataInicial = document.getElementById('dataInicial');
    const dataFinal = document.getElementById('dataFinal');

    if (dataInicial) dataInicial.value = trintaDiasAtras.toISOString().slice(0, 10);
    if (dataFinal) dataFinal.value = hoje.toISOString().slice(0, 10);
}

async function carregarTodosRegistros() {
    setLoading(true);

    try {
        const response = await fetch(`${API_REGISTROS}?limite=10000`);
        const data = await response.json();

        if (!data.sucesso) {
            mostrarAlerta('error', data.mensagem || 'Erro ao carregar registros.');
            tocarSom('error');
            return;
        }

        todosRegistros = Array.isArray(data.dados) ? data.dados : [];
        aplicarFiltros();
        mostrarAlerta('success', `${todosRegistros.length} registro(s) carregado(s).`);
        tocarSom('success');
    } catch (error) {
        console.error('[Relatorios] Erro ao carregar:', error);
        mostrarAlerta('error', 'Erro de conexao ao carregar registros.');
        tocarSom('error');
    } finally {
        setLoading(false);
    }
}

function setLoading(ativo) {
    const loading = document.getElementById('loadingRelatorios');
    if (loading) loading.style.display = ativo ? 'block' : 'none';
}

function aplicarFiltros() {
    const dataInicial = getValue('dataInicial');
    const dataFinal = getValue('dataFinal');
    const horaInicial = getValue('horaInicial');
    const horaFinal = getValue('horaFinal');

    const filtroPlaca = getValue('filtroPlaca').toUpperCase().trim();
    const filtroModelo = getValue('filtroModelo').toLowerCase().trim();
    const filtroUnidade = getValue('filtroUnidade').toLowerCase().trim();
    const filtroNome = getValue('filtroNome').toLowerCase().trim();

    const tipoRelatorio = getValue('tipoRelatorio');
    const tipoMorador = getChecked('tipoMorador');
    const tipoVisitante = getChecked('tipoVisitante');
    const tipoPrestador = getChecked('tipoPrestador');
    const apenasLiberados = getChecked('apenasLiberados');

    registrosFiltrados = todosRegistros.filter((r) => {
        const dt = parseDataHora(r.data_hora);
        if (!dt) return false;

        if (dataInicial) {
            const dIni = new Date(`${dataInicial}T00:00:00`);
            if (dt < dIni) return false;
        }

        if (dataFinal) {
            const dFim = new Date(`${dataFinal}T23:59:59`);
            if (dt > dFim) return false;
        }

        if (horaInicial) {
            const hhmm = dt.toTimeString().slice(0, 5);
            if (hhmm < horaInicial) return false;
        }

        if (horaFinal) {
            const hhmm = dt.toTimeString().slice(0, 5);
            if (hhmm > horaFinal) return false;
        }

        const placa = String(r.placa || '').toUpperCase();
        const modelo = String(r.modelo || '').toLowerCase();
        const unidade = String(r.morador_unidade || r.unidade_destino || '').toLowerCase();
        const nome = String(r.morador_nome || r.nome_visitante || '').toLowerCase();
        const tipo = String(r.tipo || '');

        if (filtroPlaca && !placa.includes(filtroPlaca)) return false;
        if (filtroModelo && !modelo.includes(filtroModelo)) return false;
        if (filtroUnidade && !unidade.includes(filtroUnidade)) return false;
        if (filtroNome && !nome.includes(filtroNome)) return false;

        if (!tipoMorador && tipo === 'Morador') return false;
        if (!tipoVisitante && tipo === 'Visitante') return false;
        if (!tipoPrestador && tipo === 'Prestador') return false;

        if (tipoRelatorio === 'moradores' && tipo !== 'Morador') return false;
        if (tipoRelatorio === 'visitantes' && tipo !== 'Visitante') return false;
        if (tipoRelatorio === 'prestadores' && tipo !== 'Prestador') return false;

        if (apenasLiberados && Number(r.liberado) !== 1) return false;

        return true;
    });

    aplicarBuscaLocalTabela();
    atualizarEstatisticas(registrosFiltrados);
}

function aplicarBuscaLocalTabela() {
    const termo = termoBuscaLocal.toLowerCase().trim();

    if (!termo) {
        renderTabela(registrosFiltrados);
        return;
    }

    const dados = registrosFiltrados.filter((r) => {
        const dataHora = `${r.data_hora_formatada || ''} ${r.data_hora || ''}`.toLowerCase();
        const placa = String(r.placa || '').toLowerCase();
        const modelo = String(r.modelo || '').toLowerCase();
        const cor = String(r.cor || '').toLowerCase();
        const tag = String(r.tag || '').toLowerCase();
        const tipo = String(r.tipo || '').toLowerCase();
        const nome = String(r.morador_nome || r.nome_visitante || '').toLowerCase();
        const unidade = String(r.morador_unidade || r.unidade_destino || '').toLowerCase();
        const status = String(r.status || '').toLowerCase();
        const obs = String(r.observacao || '').toLowerCase();

        return (
            dataHora.includes(termo) || placa.includes(termo) || modelo.includes(termo) ||
            cor.includes(termo) || tag.includes(termo) || tipo.includes(termo) ||
            nome.includes(termo) || unidade.includes(termo) || status.includes(termo) ||
            obs.includes(termo)
        );
    });

    renderTabela(dados);
}

function renderTabela(lista) {
    const tbody = document.querySelector('#relatorioTable tbody');
    if (!tbody) return;

    if (!lista || lista.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" class="empty-state">Nenhum registro encontrado com os filtros aplicados.</td></tr>';
        return;
    }

    tbody.innerHTML = lista.map((r) => {
        const { data, hora } = formatarDataHoraLinha(r);
        const nome = escapeHtml(r.morador_nome || r.nome_visitante || r.tipo || '-');
        const unidade = escapeHtml(r.morador_unidade || r.unidade_destino || '-');
        const status = escapeHtml(r.status || '-');
        const statusClass = classificarStatus(status, r.liberado);

        return `
            <tr>
                <td>${escapeHtml(data)}</td>
                <td>${escapeHtml(hora)}</td>
                <td>${escapeHtml(r.placa || '-')}</td>
                <td>${escapeHtml(r.modelo || '-')}</td>
                <td>${escapeHtml(r.cor || '-')}</td>
                <td>${escapeHtml(r.tag || '-')}</td>
                <td>${escapeHtml(r.tipo || '-')}</td>
                <td>${nome}</td>
                <td>${unidade}</td>
                <td>${escapeHtml(String(r.dias_permanencia || '-'))}</td>
                <td><span class="status-pill ${statusClass}">${status}</span></td>
                <td>${escapeHtml(r.observacao || '-')}</td>
            </tr>
        `;
    }).join('');
}

function atualizarEstatisticas(lista) {
    const total = lista.length;
    const moradores = lista.filter((r) => r.tipo === 'Morador').length;
    const visitantes = lista.filter((r) => r.tipo === 'Visitante').length;
    const prestadores = lista.filter((r) => r.tipo === 'Prestador').length;
    const liberados = lista.filter((r) => Number(r.liberado) === 1).length;

    setText('totalRegistros', total);
    setText('totalMoradores', moradores);
    setText('totalVisitantes', visitantes);
    setText('totalPrestadores', prestadores);
    setText('totalLiberados', liberados);
}

function exportarCSV() {
    if (!registrosFiltrados.length) {
        mostrarAlerta('error', 'Nenhum registro para exportar.');
        tocarSom('error');
        return;
    }

    const header = 'Data;Hora;Placa;Modelo;Cor;TAG RFID;Tipo;Nome;Unidade;Dias Permanencia;Status;Observacao\n';
    const linhas = registrosFiltrados.map((r) => {
        const { data, hora } = formatarDataHoraLinha(r);
        const nome = r.morador_nome || r.nome_visitante || r.tipo || '';
        const unidade = r.morador_unidade || r.unidade_destino || '';

        return [
            data,
            hora,
            r.placa || '',
            r.modelo || '',
            r.cor || '',
            r.tag || '',
            r.tipo || '',
            nome,
            unidade,
            r.dias_permanencia || '',
            r.status || '',
            r.observacao || ''
        ].map(csvEscape).join(';');
    });

    const csv = header + linhas.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);

    const a = document.createElement('a');
    a.href = url;
    a.download = `relatorio_acessos_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    mostrarAlerta('success', 'CSV exportado com sucesso.');
    tocarSom('success');
}

function gerarPDF() {
    // Coleta os filtros ativos e abre o relatorio de acessos em nova aba
    const dataInicial = getValue('dataInicial');
    const dataFinal   = getValue('dataFinal');
    const horaInicial = getValue('horaInicial');
    const horaFinal   = getValue('horaFinal');
    const placa       = getValue('filtroPlaca');
    const modelo      = getValue('filtroModelo');
    const unidade     = getValue('filtroUnidade');
    const nome        = getValue('filtroNome');
    const tipo        = getValue('tipoRelatorio');

    const params = new URLSearchParams();
    if (dataInicial) params.set('data_inicio', dataInicial);
    if (dataFinal)   params.set('data_fim',    dataFinal);
    if (horaInicial) params.set('hora_inicio', horaInicial);
    if (horaFinal)   params.set('hora_fim',    horaFinal);
    if (placa)       params.set('placa',       placa);
    if (modelo)      params.set('modelo',      modelo);
    if (unidade)     params.set('unidade',     unidade);
    if (nome)        params.set('nome',        nome);
    if (tipo && tipo !== 'todos') params.set('tipo', tipo);

    const base = window.location.origin + '/api/api_relatorio_acessos_pdf.php';
    window.open(base + '?' + params.toString(), '_blank');
}

function rankingPDF() {
    const dias   = getValue('rankingDias')  || '30';
    const tipo   = getValue('rankingTipo')  || '';
    const placa  = getValue('rankingPlaca') || '';
    const top    = getValue('rankingTop')   || '20';

    const params = new URLSearchParams({ dias, top });
    if (tipo)  params.set('tipo',  tipo);
    if (placa) params.set('placa', placa);

    const base = window.location.origin + '/api/api_relatorio_acessos_veiculos_pdf.php';
    window.open(base + '?' + params.toString(), '_blank');
}

function rankingCSV() {
    // Busca o ranking via API e gera CSV client-side
    const dias   = getValue('rankingDias')  || '30';
    const tipo   = getValue('rankingTipo')  || '';
    const placa  = getValue('rankingPlaca') || '';
    const top    = getValue('rankingTop')   || '20';

    // Filtra os registros ja carregados em memoria
    const hoje       = new Date();
    const dataCorte  = new Date();
    dataCorte.setDate(hoje.getDate() - parseInt(dias));

    const filtrados = todosRegistros.filter(r => {
        const dt = new Date(r.data_hora || r.data_hora_formatada);
        if (dt < dataCorte) return false;
        if (tipo  && r.tipo  !== tipo)  return false;
        if (placa && !(r.placa || '').toUpperCase().includes(placa.toUpperCase())) return false;
        return true;
    });

    // Agrupar por placa + tipo
    const grupos = {};
    filtrados.forEach(r => {
        const chave = (r.placa || '') + '|' + (r.tipo || '');
        if (!grupos[chave]) {
            grupos[chave] = {
                placa:   r.placa || '',
                modelo:  r.modelo || '',
                cor:     r.cor || '',
                tipo:    r.tipo || '',
                nome:    r.morador_nome || r.nome_visitante || '',
                unidade: r.morador_unidade || r.unidade_destino || '',
                total:   0,
                liberados: 0,
                ultimo: ''
            };
        }
        grupos[chave].total++;
        if (r.liberado == 1) grupos[chave].liberados++;
        if (!grupos[chave].ultimo || r.data_hora > grupos[chave].ultimo)
            grupos[chave].ultimo = r.data_hora_formatada || r.data_hora || '';
    });

    const ranking = Object.values(grupos)
        .sort((a, b) => b.total - a.total)
        .slice(0, parseInt(top));

    if (!ranking.length) {
        mostrarAlerta('error', 'Nenhum registro encontrado para o periodo selecionado.');
        return;
    }

    const cabecalho = ['Posicao','Placa','Modelo','Cor','Tipo','Nome/Responsavel','Unidade','Total Acessos','Acessos Liberados','Ultimo Acesso'];
    const linhas = ranking.map((v, i) => [
        i + 1, v.placa, v.modelo, v.cor, v.tipo, v.nome, v.unidade, v.total, v.liberados, v.ultimo
    ].map(c => '"' + String(c ?? '').replace(/"/g, '""') + '"'));

    const csv  = [cabecalho.join(','), ...linhas.map(l => l.join(','))].join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'ranking_veiculos_' + dias + 'dias_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    mostrarAlerta('success', 'CSV do ranking exportado com sucesso.');
}

function limparFiltros() {
    setDatasPadrao();

    ['horaInicial', 'horaFinal', 'filtroPlaca', 'filtroModelo', 'filtroUnidade', 'filtroNome', 'buscaLocalRelatorio']
        .forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });

    setChecked('tipoMorador', true);
    setChecked('tipoVisitante', true);
    setChecked('tipoPrestador', true);
    setChecked('apenasLiberados', false);

    const tipoRelatorio = document.getElementById('tipoRelatorio');
    if (tipoRelatorio) tipoRelatorio.value = 'todos';

    termoBuscaLocal = '';
    aplicarFiltros();
}

function mostrarAlerta(tipo, mensagem) {
    const box = document.getElementById('alertBox');
    if (!box) return;

    const classe = tipo === 'success' ? 'alert-success' : 'alert-error';
    const icone = tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';

    box.innerHTML = `<div class="alert ${classe}"><i class="fas ${icone}"></i> ${escapeHtml(mensagem)}</div>`;

    setTimeout(() => {
        box.innerHTML = '';
    }, 4000);
}

function formatarDataHoraLinha(r) {
    const formatada = String(r.data_hora_formatada || '').trim();
    if (formatada.includes(' ')) {
        const [data, hora] = formatada.split(' ');
        return { data, hora: hora || '-' };
    }

    const dt = parseDataHora(r.data_hora);
    if (!dt) return { data: '-', hora: '-' };

    const data = `${String(dt.getDate()).padStart(2, '0')}/${String(dt.getMonth() + 1).padStart(2, '0')}/${dt.getFullYear()}`;
    const hora = `${String(dt.getHours()).padStart(2, '0')}:${String(dt.getMinutes()).padStart(2, '0')}:${String(dt.getSeconds()).padStart(2, '0')}`;
    return { data, hora };
}

function parseDataHora(valor) {
    if (!valor) return null;
    const dt = new Date(String(valor).replace(' ', 'T'));
    return Number.isNaN(dt.getTime()) ? null : dt;
}

function classificarStatus(status, liberado) {
    const s = String(status || '').toLowerCase();
    if (Number(liberado) === 1 || s.includes('liberado') || s.includes('permitido')) return 'status-ok';
    if (s.includes('negado') || s.includes('erro')) return 'status-deny';
    return 'status-warn';
}

function somHabilitado() {
    const checkbox = document.getElementById('habilitarSomRelatorios');
    return !!checkbox && checkbox.checked;
}

function prepararAudioContext() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;

    const ativar = () => {
        if (!audioCtx) {
            audioCtx = new AudioContextClass();
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume().catch(() => {});
        }
        document.removeEventListener('pointerdown', ativar);
        document.removeEventListener('keydown', ativar);
    };

    document.addEventListener('pointerdown', ativar);
    document.addEventListener('keydown', ativar);
}

function tocarSom(tipo) {
    if (!somHabilitado()) return;

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;

    if (!audioCtx) {
        audioCtx = new AudioContextClass();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(() => {});
    }

    if (tipo === 'success') {
        tocarBeep(820, 0.08, 0);
        tocarBeep(1080, 0.1, 0.1);
    } else {
        tocarBeep(320, 0.12, 0);
        tocarBeep(240, 0.16, 0.14);
    }
}

function tocarBeep(freq, duracao, atraso = 0) {
    if (!audioCtx) return;

    const inicio = audioCtx.currentTime + atraso;
    const fim = inicio + duracao;

    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(freq, inicio);

    gain.gain.setValueAtTime(0.0001, inicio);
    gain.gain.exponentialRampToValueAtTime(0.1, inicio + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, fim);

    osc.connect(gain);
    gain.connect(audioCtx.destination);

    osc.start(inicio);
    osc.stop(fim);
}

function csvEscape(value) {
    const v = String(value ?? '');
    return `"${v.replaceAll('"', '""')}"`;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function getValue(id) {
    return document.getElementById(id)?.value || '';
}

function getChecked(id) {
    return !!document.getElementById(id)?.checked;
}

function setChecked(id, checked) {
    const el = document.getElementById(id);
    if (el) el.checked = checked;
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = String(value);
}
