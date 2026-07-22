<?php
/**
 * new_qrcode.php — Control ID Online Mode: QR Code Event
 *
 * Disparado pelo equipamento a cada leitura de QR Code no modo online
 * (quando qrcode_legacy_mode_enabled = 0).
 *
 * Acessível via:
 *   POST /new_qrcode.fcgi  (reescrito pelo root .htaccess)
 *   POST /api/controlid/new_qrcode.php
 *
 * Parâmetros (form-urlencoded ou query string):
 *   device_id, identifier_id, uuid, time, portal_id, qrcode_value
 */

require_once __DIR__ . '/_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_responder(push_resposta_negado(1), 405);
}

$params        = array_merge($_GET, $_POST);
$device_id     = isset($params['device_id'])    ? intval($params['device_id'])    : null;
$identifier_id = isset($params['identifier_id']) ? intval($params['identifier_id']) : null;
$uuid          = $params['uuid']          ?? null;
$time_unix     = isset($params['time'])   ? intval($params['time']) : time();
$portal_id     = isset($params['portal_id']) ? intval($params['portal_id']) : 1;
$qrcode_value  = trim($params['qrcode_value'] ?? '');
$ip_remoto     = $_SERVER['REMOTE_ADDR'] ?? null;

$data_evento = $time_unix > 0 ? date('Y-m-d H:i:s', $time_unix) : date('Y-m-d H:i:s');

if (!$qrcode_value) {
    push_responder(push_resposta_negado($portal_id));
}

$conn = conectar_banco();

$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);
if ($disp) push_atualizar_ping($conn, $disp['id'], $device_id, $uuid);

// Tentar identificar veículo pelo QR code (campo tag)
$veiculo = push_processar_tag($conn, $qrcode_value);

// Caso não encontre como TAG, tentar buscar na tabela de visitantes ou moradores com QR
if (!$veiculo) {
    $stmt = $conn->prepare(
        "SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.morador_id, v.controlid_user_id,
                m.nome AS morador_nome, m.unidade
         FROM veiculos v
         LEFT JOIN moradores m ON m.id = v.morador_id
         WHERE v.qrcode_acesso = ? AND v.ativo = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $qrcode_value);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $veiculo = $res->fetch_assoc();
}

$payload_raw = json_encode($params);

if ($veiculo) {
    $resposta = push_resposta_autorizado($veiculo, $portal_id, $disp ?? []);

    push_registrar_evento($conn, [
        'dispositivo_id'   => $disp['id']   ?? null,
        'device_id'        => $device_id,
        'uuid'             => $uuid,
        'tipo_evento'      => 'qrcode',
        'payload'          => $payload_raw,
        'qrcode_value'     => $qrcode_value,
        'controlid_user_id'=> $veiculo['controlid_user_id'],
        'evento_codigo'    => 7,
        'veiculo_id'       => $veiculo['id'],
        'morador_id'       => $veiculo['morador_id'],
        'acesso_liberado'  => 1,
        'resposta_enviada' => json_encode($resposta),
        'portal_id'        => $portal_id,
        'identifier_id'    => $identifier_id,
        'data_evento'      => $data_evento,
        'tipo_evento_codigo' => 6,
    ]);

    if ($disp) {
        push_registrar_leitura($conn, [
            'dispositivo_id'     => $disp['id'],
            'data_evento'        => $data_evento,
            'tipo_evento_codigo' => 6,
            'tag_value'          => $qrcode_value,
            'controlid_user_id'  => $veiculo['controlid_user_id'],
            'veiculo_id'         => $veiculo['id'],
            'morador_id'         => $veiculo['morador_id'],
            'acesso_liberado'    => 1,
        ]);
        push_registrar_acesso_erp($conn, $veiculo, $disp['id'], 'online_qrcode', $qrcode_value);
    }

    fechar_conexao($conn);
    push_responder($resposta);

} else {
    $resposta = push_resposta_negado($portal_id);

    push_registrar_evento($conn, [
        'dispositivo_id'   => $disp['id'] ?? null,
        'device_id'        => $device_id,
        'uuid'             => $uuid,
        'tipo_evento'      => 'qrcode',
        'payload'          => $payload_raw,
        'qrcode_value'     => $qrcode_value,
        'evento_codigo'    => 6,
        'acesso_liberado'  => 0,
        'resposta_enviada' => json_encode($resposta),
        'portal_id'        => $portal_id,
        'identifier_id'    => $identifier_id,
        'data_evento'      => $data_evento,
        'tipo_evento_codigo' => 5,
    ]);

    fechar_conexao($conn);
    push_responder($resposta);
}
