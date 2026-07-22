<?php
/**
 * device_is_alive.php — Control ID Online Mode: Heartbeat / Server Check
 *
 * O equipamento envia este request a cada minuto quando não consegue
 * atingir o servidor. Também é enviado periodicamente para confirmar
 * a conectividade. Após 3 falhas, o equipamento entra em modo contingência.
 *
 * Acessível via:
 *   POST /device_is_alive.fcgi  (reescrito pelo root .htaccess)
 *   POST /api/controlid/device_is_alive.php
 *
 * Body JSON:
 *   {"access_logs": <int — total de logs no equipamento>}
 *
 * Resposta: {} (HTTP 200 confirma que o servidor está online)
 */

require_once __DIR__ . '/_helper.php';

push_headers();

// Aceitar GET também (alguns firmwares enviam GET para health check)
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$device_id   = isset($_GET['deviceId']) ? intval($_GET['deviceId']) : null;
$access_logs = isset($body['access_logs']) ? intval($body['access_logs']) : null;
$ip_remoto   = $_SERVER['REMOTE_ADDR'] ?? null;

$conn = conectar_banco();

$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);

if ($disp) {
    push_atualizar_ping($conn, $disp['id'], $device_id);

    // Se o equipamento tem logs que ainda não coletamos, enfileirar coleta automática
    if ($access_logs !== null) {
        $ultimo = $conn->query(
            "SELECT MAX(controlid_log_id) AS ultimo FROM dispositivos_controlid_leituras
             WHERE dispositivo_id = {$disp['id']}"
        )->fetch_assoc();
        $ultimo_id = intval($ultimo['ultimo'] ?? 0);

        // Se o total de logs do equipamento for maior que o que temos, há logs novos
        if ($access_logs > 0 && $disp['modo_operacao'] === 'push') {
            // Verificar se já há um comando de coleta de logs na fila
            $existe = $conn->query(
                "SELECT id FROM controlid_push_queue
                 WHERE dispositivo_id = {$disp['id']}
                   AND endpoint = 'load_objects'
                   AND status = 'pendente'
                 LIMIT 1"
            )->fetch_assoc();

            if (!$existe) {
                $body_cmd = json_encode([
                    'object'   => 'access_logs',
                    'where'    => $ultimo_id > 0 ? ['id' => ['gt' => $ultimo_id]] : null,
                    'order_by' => ['id' => 'asc'],
                    'limit'    => 500
                ]);
                $stmt = $conn->prepare(
                    "INSERT INTO controlid_push_queue
                     (dispositivo_id, device_id, endpoint, verb, body)
                     VALUES (?, ?, 'load_objects', 'POST', ?)"
                );
                $stmt->bind_param('iis', $disp['id'], $device_id, $body_cmd);
                $stmt->execute();
            }
        }
    }
}

fechar_conexao($conn);
// Resposta 200 confirma que o servidor está online
push_responder([]);
