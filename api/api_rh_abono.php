<?php
// =====================================================
// API: RH — Abono de Ponto
// =====================================================
// GET  ?acao=listar&colaborador_id=N&mes=M&ano=A
// POST ?acao=salvar  {lancamento_id, abono_extras, abono_extras_min,
//                     abono_falta, abono_atraso, abono_atraso_min, abono_justificativa}

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'rh_ponto_core.php';
ob_end_clean();

try { verificarAutenticacao(true, 'operador'); }
catch (Exception $e) {
    http_response_code(401);
    retornar_json(false, 'Não autenticado.');
    exit;
}

$conn = conectar_banco();
if (!$conn) { retornar_json(false, 'Erro ao conectar ao banco.'); exit; }

// _abono_garantir_colunas() agora vive em rh_ponto_core.php (compartilhada com
// _recalcular_totais()/_bh_sincronizar(), que passam a ler essas colunas).
_abono_garantir_colunas($conn);
_ponto_garantir_colunas($conn);

$metodo = $_SERVER['REQUEST_METHOD'];
$acao   = trim($_GET['acao'] ?? '');

// ── LISTAR ────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'listar') {
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    $mes      = intval($_GET['mes'] ?? 0);
    $ano      = intval($_GET['ano'] ?? 0);

    if ($colab_id <= 0 || $mes < 1 || $mes > 12 || $ano < 2000) {
        fechar_conexao($conn);
        retornar_json(false, 'Parâmetros inválidos.');
        exit;
    }

    $data_ini = sprintf('%04d-%02d-01', $ano, $mes);
    $data_fim = date('Y-m-t', strtotime($data_ini));

    $st = $conn->prepare(
        "SELECT id,
                DATE_FORMAT(data,'%d/%m/%Y') AS d,
                DAYNAME(data) AS dn,
                tipo_dia,
                TIME_FORMAT(hora_entrada,'%H:%i') AS he,
                TIME_FORMAT(hora_saida,'%H:%i')   AS hs,
                horas_trabalhadas_min, horas_extras_min, atraso_min,
                observacoes,
                abono_extras, abono_extras_min, abono_falta,
                abono_atraso, abono_atraso_min,
                abono_justificativa, abono_usuario,
                DATE_FORMAT(abono_data,'%d/%m/%Y %H:%i') AS abono_data
         FROM rh_ponto_lancamento
         WHERE colaborador_id = ? AND data BETWEEN ? AND ?
         ORDER BY data"
    );
    if (!$st) { fechar_conexao($conn); retornar_json(false, 'Erro: ' . $conn->error); exit; }

    $st->bind_param('iss', $colab_id, $data_ini, $data_fim);
    $st->execute();
    $res = $st->get_result();

    $dias_pt = ['Monday'=>'Segunda','Tuesday'=>'Terça','Wednesday'=>'Quarta',
                'Thursday'=>'Quinta','Friday'=>'Sexta','Saturday'=>'Sábado','Sunday'=>'Domingo'];
    $list = [];
    while ($r = $res->fetch_assoc()) {
        $r['dia_pt'] = $dias_pt[$r['dn']] ?? $r['dn'];
        $list[] = $r;
    }
    $st->close();
    fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
    exit;
}

// ── SALVAR ────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'salvar') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $lancamento_id  = intval($body['lancamento_id'] ?? 0);
    $abono_extras   = in_array($body['abono_extras'] ?? '', ['nenhum','total','parcial'])
                        ? $body['abono_extras'] : 'nenhum';
    $abono_extras_m = max(0, intval($body['abono_extras_min'] ?? 0));
    $abono_falta    = empty($body['abono_falta']) ? 0 : 1;
    $abono_atraso   = in_array($body['abono_atraso'] ?? '', ['nenhum','total','parcial'])
                        ? $body['abono_atraso'] : 'nenhum';
    $abono_atraso_m = max(0, intval($body['abono_atraso_min'] ?? 0));
    $justificativa  = trim($body['abono_justificativa'] ?? '');
    $usuario        = $_SESSION['nome'] ?? $_SESSION['usuario_nome'] ?? 'Sistema';

    if ($lancamento_id <= 0) {
        fechar_conexao($conn);
        retornar_json(false, 'ID de lançamento inválido.');
        exit;
    }

    $tem_abono = $abono_extras !== 'nenhum' || $abono_falta || $abono_atraso !== 'nenhum';

    if ($tem_abono && empty($justificativa)) {
        fechar_conexao($conn);
        retornar_json(false, 'Justificativa é obrigatória ao conceder abono.');
        exit;
    }

    if (!$tem_abono) {
        // Limpar abono existente
        $abono_extras   = 'nenhum';
        $abono_extras_m = 0;
        $abono_falta    = 0;
        $abono_atraso   = 'nenhum';
        $abono_atraso_m = 0;
    }

    $justif_val     = $tem_abono ? $justificativa : null;
    $abono_usr_val  = $tem_abono ? $usuario : null;
    $abono_data_val = $tem_abono ? date('Y-m-d H:i:s') : null;

    $st = $conn->prepare(
        "UPDATE rh_ponto_lancamento
         SET abono_extras=?, abono_extras_min=?, abono_falta=?,
             abono_atraso=?, abono_atraso_min=?,
             abono_justificativa=?, abono_usuario=?, abono_data=?
         WHERE id=?"
    );
    if (!$st) { fechar_conexao($conn); retornar_json(false, 'Erro: ' . $conn->error); exit; }

    // s(extras) i(extras_min) i(falta) s(atraso) i(atraso_min) s(justif) s(usr) s(data) i(id)
    $st->bind_param('siis' . 'i' . 'sss' . 'i',
        $abono_extras, $abono_extras_m, $abono_falta,
        $abono_atraso, $abono_atraso_m,
        $justif_val, $abono_usr_val, $abono_data_val,
        $lancamento_id
    );

    if (!$st->execute()) {
        $erro = $st->error; $st->close(); fechar_conexao($conn);
        retornar_json(false, 'Erro ao salvar: ' . $erro);
        exit;
    }
    $st->close();

    // O abono não recalcula o fato bruto do dia (_calcular_minutos não é chamado
    // de novo) — apenas reflete na consequência agregada: banco de horas e
    // totais do período, reaproveitando as mesmas funções do motor de ponto.
    $lRow = $conn->query(
        "SELECT periodo_id, colaborador_id, data, horas_trabalhadas_min,
                horas_extras_min, atraso_min, saida_antecipada_min
         FROM rh_ponto_lancamento WHERE id = $lancamento_id"
    );
    $lRow = $lRow ? $lRow->fetch_assoc() : null;
    if ($lRow) {
        $colabIdAbono = intval($lRow['colaborador_id']);
        $escalaAbono  = _buscar_escala($conn, $colabIdAbono);
        $calcAbono    = [
            'trabalhadas'      => (int)$lRow['horas_trabalhadas_min'],
            'extras'           => (int)$lRow['horas_extras_min'],
            'atraso'           => (int)$lRow['atraso_min'],
            'saida_antecipada' => (int)$lRow['saida_antecipada_min'],
        ];
        _bh_sincronizar($conn, $lancamento_id, $colabIdAbono, $lRow['data'], $calcAbono, $escalaAbono);
        _recalcular_totais($conn, intval($lRow['periodo_id']));
    }

    fechar_conexao($conn);
    retornar_json(true, $tem_abono ? 'Abono salvo com sucesso.' : 'Abono removido.');
    exit;
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida.');
