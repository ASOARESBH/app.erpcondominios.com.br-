<?php
/**
 * new_card.php — Control ID Online Mode: Proximity Card / Wiegand Event
 *
 * Disparado pelo equipamento a cada leitura de cartão de proximidade
 * (Wiegand/RFID) no modo Online Enterprise.
 *
 * Acessível via:
 *   POST /new_card.fcgi  (reescrito pelo root .htaccess)
 *   POST /api/controlid/new_card.php
 *
 * Parâmetros (form-urlencoded):
 *   device_id, identifier_id, card_value, panic, time, portal_id, uuid,
 *   block_read_error, block_read_data
 */

require_once __DIR__ . '/_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_responder(push_resposta_negado(1), 405);
}

$params        = array_merge($_GET, $_POST);
$device_id     = isset($params['device_id'])    ? intval($params['device_id'])    : null;
$identifier_id = isset($params['identifier_id']) ? intval($params['identifier_id']) : null;
$card_value    = isset($params['card_value'])   ? intval($params['card_value'])   : null;
$panic         = isset($params['panic'])        ? intval($params['panic'])        : 0;
$uuid          = $params['uuid'] ?? null;
$time_unix     = isset($params['time']) ? intval($params['time']) : time();
$portal_id     = isset($params['portal_id']) ? intval($params['portal_id']) : 1;
$ip_remoto     = $_SERVER['REMOTE_ADDR'] ?? null;

$data_evento = $time_unix > 0 ? date('Y-m-d H:i:s', $time_unix) : date('Y-m-d H:i:s');

if (!$card_value) {
    push_responder(push_resposta_negado($portal_id));
}

$conn = conectar_banco();

$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);
if ($disp) push_atualizar_ping($conn, $disp['id'], $device_id, $uuid);

$veiculo     = push_processar_card($conn, $card_value);
$payload_raw = json_encode($params);

if ($veiculo && !$panic) {
    $resposta = push_resposta_autorizado($veiculo, $portal_id, $disp ?? []);

    push_registrar_evento($conn, [
        'dispositivo_id'    => $disp['id']   ?? null,
        'device_id'         => $device_id,
        'uuid'              => $uuid,
        'tipo_evento'       => 'card',
        'payload'           => $payload_raw,
        'card_value'        => $card_value,
        'controlid_user_id' => $veiculo['controlid_user_id'],
        'evento_codigo'     => 7,
        'veiculo_id'        => $veiculo['id'],
        'morador_id'        => $veiculo['morador_id'],
        'acesso_liberado'   => 1,
        'resposta_enviada'  => json_encode($resposta),
        'portal_id'         => $portal_id,
        'identifier_id'     => $identifier_id,
        'data_evento'       => $data_evento,
        'tipo_evento_codigo' => 6,
    ]);

    if ($disp) {
        push_registrar_leitura($conn, [
            'dispositivo_id'     => $disp['id'],
            'data_evento'        => $data_evento,
            'tipo_evento_codigo' => 6,
            'card_value'         => $card_value,
            'controlid_user_id'  => $veiculo['controlid_user_id'],
            'veiculo_id'         => $veiculo['id'],
            'morador_id'         => $veiculo['morador_id'],
            'acesso_liberado'    => 1,
        ]);
        push_registrar_acesso_erp($conn, $veiculo, $disp['id'], 'online_card', "card=$card_value");
    }

    fechar_conexao($conn);
    push_responder($resposta);

} else {
    $resposta = push_resposta_negado($portal_id);

    push_registrar_evento($conn, [
        'dispositivo_id'   => $disp['id'] ?? null,
        'device_id'        => $device_id,
        'uuid'             => $uuid,
        'tipo_evento'      => 'card',
        'payload'          => $payload_raw,
        'card_value'       => $card_value,
        'evento_codigo'    => 6,
        'acesso_liberado'  => 0,
        'resposta_enviada' => json_encode($resposta),
        'portal_id'        => $portal_id,
        'identifier_id'    => $identifier_id,
        'data_evento'      => $data_evento,
        'tipo_evento_codigo' => 5,
    ]);

    if ($disp) {
        push_registrar_leitura($conn, [
            'dispositivo_id'     => $disp['id'],
            'data_evento'        => $data_evento,
            'tipo_evento_codigo' => 5,
            'card_value'         => $card_value,
            'acesso_liberado'    => 0,
        ]);
    }

    fechar_conexao($conn);
    push_responder($resposta);
}
