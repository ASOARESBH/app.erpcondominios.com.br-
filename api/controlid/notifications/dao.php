<?php
/**
 * dao.php — Control ID Monitor Mode: DAO Change Notification
 *
 * No modo Monitor, o equipamento faz POST aqui sempre que houver
 * inserção/atualização/remoção nas tabelas access_logs, templates,
 * cards ou alarm_logs.
 *
 * Configuração no equipamento (via set_configuration.fcgi):
 *   monitor.path = "/api/controlid/notifications"
 *   monitor.hostname = "asl.erpcondominio.com.br"
 *   monitor.port = 443
 *
 * O equipamento chamará:
 *   POST https://asl.erpcondominio.com.br/api/controlid/notifications/dao
 *
 * Body JSON varia conforme o objeto:
 *   {"object":"access_logs","records":[{"id":1,...}],"operation":"INSERT"}
 */

require_once __DIR__ . '/../_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_responder([]);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$objeto    = $body['object']    ?? null;
$registros = $body['records']   ?? [];
$operacao  = $body['operation'] ?? 'INSERT';
$device_id = isset($body['device_id']) ? intval($body['device_id']) : null;
$ip_remoto = $_SERVER['REMOTE_ADDR'] ?? null;

if (!$objeto || empty($registros)) {
    push_responder([]);
}

$conn = conectar_banco();

$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);
if ($disp) push_atualizar_ping($conn, $disp['id'], $device_id);

// Processar apenas access_logs por enquanto
if ($objeto === 'access_logs') {
    foreach ($registros as $log) {
        $log_id     = intval($log['id']         ?? 0);
        $ts         = intval($log['time']        ?? 0);
        $data_hora  = $ts > 0 ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        $evento     = intval($log['event']       ?? 0);
        $tag_value  = $log['uhf_tag']            ?? null;
        $card_value = isset($log['card_value'])  ? intval($log['card_value']) : null;
        $cid_user   = isset($log['user_id'])     ? intval($log['user_id'])    : null;
        $liberado   = ($evento === 6) ? 1 : 0;

        if (!$log_id) continue;

        $veiculo = null;
        if ($tag_value)  $veiculo = push_processar_tag($conn, $tag_value);
        if (!$veiculo && $card_value) $veiculo = push_processar_card($conn, $card_value);
        if (!$veiculo && $cid_user)   $veiculo = push_processar_user_id($conn, $cid_user);

        // Registrar evento push
        push_registrar_evento($conn, [
            'dispositivo_id'     => $disp['id']   ?? null,
            'device_id'          => $device_id,
            'tipo_evento'        => 'dao',
            'payload'            => json_encode($log),
            'tag_value'          => $tag_value,
            'card_value'         => $card_value,
            'controlid_user_id'  => $cid_user,
            'evento_codigo'      => $liberado ? 7 : 6,
            'veiculo_id'         => $veiculo['id']        ?? null,
            'morador_id'         => $veiculo['morador_id'] ?? null,
            'acesso_liberado'    => $liberado,
            'data_evento'        => $data_hora,
            'tipo_evento_codigo' => $evento,
        ]);

        // Inserir na tabela de leituras (INSERT IGNORE para evitar duplicatas)
        if ($disp) {
            $stmt = $conn->prepare(
                "INSERT IGNORE INTO dispositivos_controlid_leituras
                 (dispositivo_id, controlid_log_id, data_hora, tipo_evento, tag_value,
                  card_value, controlid_user_id, veiculo_id, morador_id, acesso_liberado, processado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
            );
            $veiculo_id = $veiculo['id']        ?? null;
            $morador_id = $veiculo['morador_id'] ?? null;
            $stmt->bind_param('iisissiiiii',
                $disp['id'], $log_id, $data_hora, $evento,
                $tag_value, $card_value, $cid_user,
                $veiculo_id, $morador_id, $liberado);
            $stmt->execute();

            if ($liberado && $veiculo) {
                push_registrar_acesso_erp($conn, $veiculo, $disp['id'], 'monitor_dao',
                    $tag_value ?? "card=$card_value");
            }
        }
    }
}

fechar_conexao($conn);
push_responder([]);
