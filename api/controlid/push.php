<?php
/**
 * push.php — Control ID Push Mode: Device polls for commands
 *
 * O equipamento faz GET neste endpoint periodicamente buscando
 * o próximo comando a executar. O servidor responde com um
 * comando JSON ou {} se não houver nada pendente.
 *
 * URL configurada no equipamento (push_remote_address):
 *   https://asl.erpcondominio.com.br/api/controlid
 *
 * Request do equipamento:
 *   GET /api/controlid/push?deviceId=<int64>&uuid=<string>
 *
 * Resposta do servidor (comando único):
 *   {"verb":"POST","endpoint":"load_objects","body":{...}}
 *
 * Resposta quando não há comando:
 *   {}
 */

require_once __DIR__ . '/_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    push_responder([], 405);
}

$device_id = isset($_GET['deviceId']) ? intval($_GET['deviceId']) : null;
$uuid      = $_GET['uuid'] ?? null;
$ip_remoto = $_SERVER['REMOTE_ADDR'] ?? null;

$conn = conectar_banco();

// Identificar o dispositivo
$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);

if ($disp) {
    // Registrar que o equipamento está vivo e atualizar device_id
    push_atualizar_ping($conn, $disp['id'], $device_id, $uuid);

    // Buscar próximo comando pendente para este dispositivo
    $stmt = $conn->prepare(
        "SELECT * FROM controlid_push_queue
         WHERE dispositivo_id = ? AND status = 'pendente'
         ORDER BY id ASC
         LIMIT 1"
    );
    $stmt->bind_param('i', $disp['id']);
    $stmt->execute();
    $cmd = $stmt->get_result()->fetch_assoc();

    if ($cmd) {
        // Marcar como enviado
        $upd = $conn->prepare(
            "UPDATE controlid_push_queue SET status='enviado', enviado_em=NOW(), tentativas=tentativas+1 WHERE id=?"
        );
        $upd->bind_param('i', $cmd['id']);
        $upd->execute();

        // Decodificar body
        $body = json_decode($cmd['body'], true) ?? [];

        // Responder com o comando
        $resposta = [
            'verb'     => $cmd['verb'],
            'endpoint' => $cmd['endpoint'],
            'body'     => $body
        ];
        fechar_conexao($conn);
        push_responder($resposta);
    }
}

fechar_conexao($conn);
// Nenhum comando pendente — resposta vazia (device aguarda próximo ciclo)
push_responder([]);
