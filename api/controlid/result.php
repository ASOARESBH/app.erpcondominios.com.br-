<?php
/**
 * result.php — Control ID Push Mode: Device reports command result
 *
 * Após executar um comando recebido via /push, o equipamento
 * faz POST aqui com o resultado.
 *
 * Request do equipamento:
 *   POST /api/controlid/result?deviceId=<int>
 *   Content-Type: application/json
 *   Body: {"uuid":"...","endpoint":"load_objects","response":{...}} ou
 *         {"uuid":"...","endpoint":"...","error":"mensagem de erro"}
 *
 * Para batch transactions:
 *   Body: {"transactions_results":[{"transactionid":1,"success":true,"response":"{}"},...]}
 *
 * Resposta do servidor: {}
 */

require_once __DIR__ . '/_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_responder([], 405);
}

$device_id = isset($_GET['deviceId']) ? intval($_GET['deviceId']) : null;
$ip_remoto = $_SERVER['REMOTE_ADDR'] ?? null;

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$uuid     = $body['uuid']     ?? null;
$endpoint = $body['endpoint'] ?? null;
$response = $body['response'] ?? null;
$erro     = $body['error']    ?? null;
$tx_res   = $body['transactions_results'] ?? null;

$conn = conectar_banco();

// Identificar dispositivo
$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);

if ($disp) {
    push_atualizar_ping($conn, $disp['id'], $device_id, $uuid);

    // Encontrar o comando enviado mais recente para este dispositivo
    $stmt = $conn->prepare(
        "SELECT id FROM controlid_push_queue
         WHERE dispositivo_id = ? AND status = 'enviado'
         ORDER BY enviado_em DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $disp['id']);
    $stmt->execute();
    $cmd = $stmt->get_result()->fetch_assoc();

    if ($cmd) {
        if ($tx_res !== null) {
            // Batch result
            $sucesso = collect(array_column($tx_res, 'success')) !== false;
            $resultado_json = json_encode($tx_res);
            $status_final = 'executado';
        } else {
            $resultado_json = $erro
                ? json_encode(['error' => $erro])
                : json_encode($response ?? []);
            $status_final = $erro ? 'erro' : 'executado';
        }

        $upd = $conn->prepare(
            "UPDATE controlid_push_queue
             SET status=?, resultado=?, executado_em=NOW()
             WHERE id=?"
        );
        $upd->bind_param('ssi', $status_final, $resultado_json, $cmd['id']);
        $upd->execute();

        // Se o resultado foi uma listagem de access_logs, processar automaticamente
        if (!$erro && $endpoint === 'load_objects' && $response && isset($response['access_logs'])) {
            _push_processar_logs_resultado($conn, $disp, $response['access_logs']);
        }
    }
}

fechar_conexao($conn);
push_responder([]);

// ============================================================
// Processar access_logs retornados via push result
// ============================================================
function _push_processar_logs_resultado($conn, $disp, $logs) {
    if (!is_array($logs)) return;

    foreach ($logs as $log) {
        $log_id     = intval($log['id'] ?? 0);
        $ts         = intval($log['time'] ?? 0);
        $data_hora  = $ts > 0 ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        $evento     = intval($log['event'] ?? 0);
        $tag_value  = $log['uhf_tag']    ?? null;
        $card_value = isset($log['card_value']) ? intval($log['card_value']) : null;
        $cid_user   = isset($log['user_id'])    ? intval($log['user_id'])    : null;
        $liberado   = ($evento === 6) ? 1 : 0;

        $veiculo = null;
        if ($tag_value) $veiculo = push_processar_tag($conn, $tag_value);
        if (!$veiculo && $card_value) $veiculo = push_processar_card($conn, $card_value);

        // Inserir na tabela de leituras
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO dispositivos_controlid_leituras
             (dispositivo_id, controlid_log_id, data_hora, tipo_evento, tag_value, card_value,
              controlid_user_id, veiculo_id, morador_id, acesso_liberado, processado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $veiculo_id = $veiculo['id']        ?? null;
        $morador_id = $veiculo['morador_id'] ?? null;
        $stmt->bind_param('iisiisiiii',
            $disp['id'], $log_id, $data_hora, $evento,
            $tag_value, $card_value, $cid_user, $veiculo_id, $morador_id, $liberado);
        $stmt->execute();

        if ($liberado && $veiculo) {
            push_registrar_acesso_erp($conn, $veiculo, $disp['id'], 'push_result');
        }
    }
}
