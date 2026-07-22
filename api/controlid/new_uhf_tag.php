<?php
/**
 * new_uhf_tag.php — Control ID Online Mode: UHF Tag Event
 *
 * Disparado pelo equipamento (IDUHF) a cada leitura de TAG UHF no modo online.
 * O servidor identifica o veículo, decide o acesso e responde imediatamente.
 *
 * Acessível via:
 *   POST /new_uhf_tag.fcgi  (reescrito pelo root .htaccess)
 *   POST /api/controlid/new_uhf_tag.php
 *
 * Parâmetros (form-urlencoded ou query string):
 *   device_id, identifier_id, uuid, time, portal_id, uhf_tag
 */

require_once __DIR__ . '/_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_responder(push_resposta_negado(1), 405);
}

// Parâmetros podem vir como form-data ou query string
$params    = array_merge($_GET, $_POST);
$device_id    = isset($params['device_id'])    ? intval($params['device_id'])    : null;
$identifier_id = isset($params['identifier_id']) ? intval($params['identifier_id']) : null;
$uuid         = $params['uuid']      ?? null;
$time_unix    = isset($params['time']) ? intval($params['time']) : time();
$portal_id    = isset($params['portal_id']) ? intval($params['portal_id']) : 1;
$uhf_tag      = trim($params['uhf_tag'] ?? '');
$ip_remoto    = $_SERVER['REMOTE_ADDR'] ?? null;

$data_evento = $time_unix > 0 ? date('Y-m-d H:i:s', $time_unix) : date('Y-m-d H:i:s');

if (!$uhf_tag) {
    push_responder(push_resposta_negado($portal_id));
}

$conn = conectar_banco();

// Identificar dispositivo
$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);
if ($disp) {
    push_atualizar_ping($conn, $disp['id'], $device_id, $uuid);
}

// Identificar veículo pela TAG
$veiculo = push_processar_tag($conn, $uhf_tag);

$payload_raw = json_encode($params);

if ($veiculo) {
    // Acesso autorizado
    $resposta = push_resposta_autorizado($veiculo, $portal_id, $disp ?? []);

    push_registrar_evento($conn, [
        'dispositivo_id'    => $disp['id']   ?? null,
        'device_id'         => $device_id,
        'uuid'              => $uuid,
        'tipo_evento'       => 'uhf_tag',
        'payload'           => $payload_raw,
        'tag_value'         => $uhf_tag,
        'controlid_user_id' => $veiculo['controlid_user_id'],
        'evento_codigo'     => 7, // granted
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
            'dispositivo_id'    => $disp['id'],
            'data_evento'       => $data_evento,
            'tipo_evento_codigo' => 6,
            'tag_value'         => $uhf_tag,
            'controlid_user_id' => $veiculo['controlid_user_id'],
            'veiculo_id'        => $veiculo['id'],
            'morador_id'        => $veiculo['morador_id'],
            'acesso_liberado'   => 1,
        ]);
        push_registrar_acesso_erp($conn, $veiculo, $disp['id'], 'online_uhf', $uhf_tag);
    }

    fechar_conexao($conn);
    push_responder($resposta);

} else {
    // TAG não encontrada — acesso negado
    $resposta = push_resposta_negado($portal_id);

    push_registrar_evento($conn, [
        'dispositivo_id'    => $disp['id'] ?? null,
        'device_id'         => $device_id,
        'uuid'              => $uuid,
        'tipo_evento'       => 'uhf_tag',
        'payload'           => $payload_raw,
        'tag_value'         => $uhf_tag,
        'evento_codigo'     => 6, // denied
        'acesso_liberado'   => 0,
        'resposta_enviada'  => json_encode($resposta),
        'portal_id'         => $portal_id,
        'identifier_id'     => $identifier_id,
        'data_evento'       => $data_evento,
        'tipo_evento_codigo' => 5,
    ]);

    if ($disp) {
        push_registrar_leitura($conn, [
            'dispositivo_id'     => $disp['id'],
            'data_evento'        => $data_evento,
            'tipo_evento_codigo' => 5,
            'tag_value'          => $uhf_tag,
            'acesso_liberado'    => 0,
        ]);
    }

    fechar_conexao($conn);
    push_responder($resposta);
}
