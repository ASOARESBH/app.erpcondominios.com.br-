<?php
/**
 * door.php — Control ID Monitor Mode: Door / Relay State Change
 *
 * O equipamento faz POST aqui quando a porta ou relay muda de estado
 * (aberta/fechada, ativado/desativado).
 *
 * Body JSON:
 *   {"door_id":1,"state":"open","device_id":12345,...}
 */

require_once __DIR__ . '/../_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_responder([]);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$device_id = isset($body['device_id']) ? intval($body['device_id']) : null;
$door_id   = $body['door_id'] ?? null;
$state     = $body['state']   ?? null;
$ip_remoto = $_SERVER['REMOTE_ADDR'] ?? null;

$conn = conectar_banco();

$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);
if ($disp) push_atualizar_ping($conn, $disp['id'], $device_id);

// Registrar evento de porta
push_registrar_evento($conn, [
    'dispositivo_id'  => $disp['id'] ?? null,
    'device_id'       => $device_id,
    'tipo_evento'     => 'door',
    'payload'         => json_encode($body),
    'acesso_liberado' => ($state === 'open') ? 1 : 0,
    'data_evento'     => date('Y-m-d H:i:s'),
    'tipo_evento_codigo' => 0,
]);

fechar_conexao($conn);
push_responder([]);
