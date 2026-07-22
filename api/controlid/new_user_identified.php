<?php
/**
 * new_user_identified.php — Control ID Online Mode Pro: User Identified
 *
 * No modo Online Pro, a identificação ocorre no equipamento e o servidor
 * recebe o resultado para autorizar ou negar o acesso.
 *
 * Acessível via:
 *   POST /new_user_identified.fcgi  (reescrito pelo root .htaccess)
 *   POST /api/controlid/new_user_identified.php
 *
 * Parâmetros (form-urlencoded):
 *   device_id, identifier_id, event, user_id, duress, face_mask,
 *   card_value, uhf_tag, pin_value, qrcode_value, user_name,
 *   portal_id, uuid, time, confidence
 */

require_once __DIR__ . '/_helper.php';

push_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_responder(push_resposta_negado(1), 405);
}

$params     = array_merge($_GET, $_POST);
$device_id    = isset($params['device_id'])    ? intval($params['device_id'])    : null;
$identifier_id = isset($params['identifier_id']) ? intval($params['identifier_id']) : null;
$event_code   = isset($params['event'])        ? intval($params['event'])        : 0;
$cid_user_id  = isset($params['user_id'])      ? intval($params['user_id'])      : null;
$duress       = isset($params['duress'])       ? intval($params['duress'])       : 0;
$card_value   = isset($params['card_value'])   ? intval($params['card_value'])   : null;
$uhf_tag      = trim($params['uhf_tag']        ?? '');
$qrcode_value = trim($params['qrcode_value']   ?? '');
$uuid         = $params['uuid']     ?? null;
$time_unix    = isset($params['time']) ? intval($params['time']) : time();
$portal_id    = isset($params['portal_id']) ? intval($params['portal_id']) : 1;
$ip_remoto    = $_SERVER['REMOTE_ADDR'] ?? null;

$data_evento = $time_unix > 0 ? date('Y-m-d H:i:s', $time_unix) : date('Y-m-d H:i:s');

// Eventos que chegam com identificação bem-sucedida do equipamento
// O event_code aqui é o evento local do equipamento; 0 = identificado com sucesso
// O server pode sobreescrever com 6=negado ou 7=concedido
$identificado = ($event_code === 0 || $cid_user_id > 0);

$conn = conectar_banco();

$disp = push_encontrar_dispositivo($conn, $device_id, $ip_remoto);
if ($disp) push_atualizar_ping($conn, $disp['id'], $device_id, $uuid);

// Tentar identificar o veículo/morador por vários campos
$veiculo = null;
if ($cid_user_id) $veiculo = push_processar_user_id($conn, $cid_user_id);
if (!$veiculo && $uhf_tag) $veiculo = push_processar_tag($conn, $uhf_tag);
if (!$veiculo && $card_value) $veiculo = push_processar_card($conn, $card_value);

$payload_raw = json_encode($params);

if ($identificado && $veiculo) {
    $resposta = push_resposta_autorizado($veiculo, $portal_id, $disp ?? []);

    push_registrar_evento($conn, [
        'dispositivo_id'    => $disp['id']   ?? null,
        'device_id'         => $device_id,
        'uuid'              => $uuid,
        'tipo_evento'       => 'user_identified',
        'payload'           => $payload_raw,
        'tag_value'         => $uhf_tag ?: null,
        'card_value'        => $card_value,
        'controlid_user_id' => $cid_user_id,
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
            'tag_value'          => $uhf_tag ?: null,
            'card_value'         => $card_value,
            'controlid_user_id'  => $cid_user_id,
            'veiculo_id'         => $veiculo['id'],
            'morador_id'         => $veiculo['morador_id'],
            'acesso_liberado'    => 1,
        ]);
        push_registrar_acesso_erp($conn, $veiculo, $disp['id'], 'online_pro',
            ($uhf_tag ?: '') . ($card_value ? " card=$card_value" : ''));
    }

    fechar_conexao($conn);
    push_responder($resposta);

} else {
    $resposta = push_resposta_negado($portal_id);

    push_registrar_evento($conn, [
        'dispositivo_id'    => $disp['id'] ?? null,
        'device_id'         => $device_id,
        'uuid'              => $uuid,
        'tipo_evento'       => 'user_identified',
        'payload'           => $payload_raw,
        'tag_value'         => $uhf_tag ?: null,
        'card_value'        => $card_value,
        'controlid_user_id' => $cid_user_id,
        'evento_codigo'     => 6,
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
            'tag_value'          => $uhf_tag ?: null,
            'card_value'         => $card_value,
            'controlid_user_id'  => $cid_user_id,
            'acesso_liberado'    => 0,
        ]);
    }

    fechar_conexao($conn);
    push_responder($resposta);
}
