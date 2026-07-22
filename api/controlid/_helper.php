<?php
/**
 * _helper.php — Funções compartilhadas para endpoints Control ID Push/Online Mode
 *
 * Usado por: push.php, result.php, new_uhf_tag.php, new_user_identified.php,
 *            new_card.php, new_qrcode.php, device_is_alive.php,
 *            notifications/dao.php, notifications/door.php
 */

require_once __DIR__ . '/../config.php';

// ============================================================
// HEADERS — sem autenticação de sessão (requests vêm do equipamento)
// ============================================================
function push_headers() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// ============================================================
// RESPOSTA JSON para o equipamento
// ============================================================
function push_responder($dados = [], $http_code = 200) {
    http_response_code($http_code);
    if (empty($dados)) {
        echo '{}';
    } else {
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================
// ENCONTRAR DISPOSITIVO por device_id Control ID ou por IP remoto
// ============================================================
function push_encontrar_dispositivo($conn, $device_id = null, $ip_remoto = null) {
    if ($device_id) {
        $stmt = $conn->prepare(
            "SELECT * FROM dispositivos_controlid WHERE push_device_id = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) return $row;
    }

    // Fallback: buscar por IP remoto
    if ($ip_remoto) {
        $stmt = $conn->prepare(
            "SELECT * FROM dispositivos_controlid WHERE ip_address = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->bind_param('s', $ip_remoto);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) return $row;
    }

    return null;
}

// ============================================================
// ATUALIZAR LAST PING / device_id do push
// ============================================================
function push_atualizar_ping($conn, $disp_id, $device_id = null, $uuid = null) {
    if ($device_id) {
        $stmt = $conn->prepare(
            "UPDATE dispositivos_controlid
             SET status_online=1, push_ultimo_contato=NOW(), push_ativo=1,
                 push_device_id=?, push_uuid=?
             WHERE id=?"
        );
        $stmt->bind_param('isi', $device_id, $uuid, $disp_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE dispositivos_controlid
             SET status_online=1, push_ultimo_contato=NOW(), push_ativo=1
             WHERE id=?"
        );
        $stmt->bind_param('i', $disp_id);
    }
    $stmt->execute();
}

// ============================================================
// PROCESSAR TAG UHF — encontrar veículo e morador
// ============================================================
function push_processar_tag($conn, $tag_value) {
    if (!$tag_value) return null;

    $stmt = $conn->prepare(
        "SELECT v.id, v.placa, v.modelo, v.cor, v.tag,
                v.morador_id, v.controlid_user_id,
                m.nome AS morador_nome, m.unidade
         FROM veiculos v
         LEFT JOIN moradores m ON m.id = v.morador_id
         WHERE UPPER(v.tag) = UPPER(?) AND v.ativo = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $tag_value);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// ============================================================
// PROCESSAR CARD VALUE — encontrar veículo por cartão Wiegand
// ============================================================
function push_processar_card($conn, $card_value) {
    if (!$card_value) return null;

    $stmt = $conn->prepare(
        "SELECT v.id, v.placa, v.modelo, v.cor, v.tag,
                v.morador_id, v.controlid_user_id,
                m.nome AS morador_nome, m.unidade
         FROM veiculos v
         LEFT JOIN moradores m ON m.id = v.morador_id
         WHERE v.tag = ? AND v.ativo = 1
         LIMIT 1"
    );
    $card_str = strval($card_value);
    $stmt->bind_param('s', $card_str);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// ============================================================
// PROCESSAR controlid_user_id — encontrar veículo pelo ID no Control ID
// ============================================================
function push_processar_user_id($conn, $controlid_user_id) {
    if (!$controlid_user_id) return null;

    $stmt = $conn->prepare(
        "SELECT v.id, v.placa, v.modelo, v.cor, v.tag,
                v.morador_id, v.controlid_user_id,
                m.nome AS morador_nome, m.unidade
         FROM veiculos v
         LEFT JOIN moradores m ON m.id = v.morador_id
         WHERE v.controlid_user_id = ? AND v.ativo = 1
         LIMIT 1"
    );
    $stmt->bind_param('i', $controlid_user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// ============================================================
// REGISTRAR EVENTO na tabela controlid_push_eventos
// ============================================================
function push_registrar_evento($conn, array $dados) {
    $stmt = $conn->prepare(
        "INSERT INTO controlid_push_eventos
         (dispositivo_id, device_id, uuid, tipo_evento, payload, tag_value, card_value,
          qrcode_value, controlid_user_id, evento_codigo, veiculo_id, morador_id,
          acesso_liberado, resposta_enviada, portal_id, identifier_id, data_evento)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    $disp_id      = $dados['dispositivo_id']    ?? null;
    $device_id    = $dados['device_id']         ?? null;
    $uuid         = $dados['uuid']              ?? null;
    $tipo         = $dados['tipo_evento']       ?? 'desconhecido';
    $payload      = $dados['payload']           ?? '{}';
    $tag          = $dados['tag_value']         ?? null;
    $card         = isset($dados['card_value']) ? intval($dados['card_value']) : null;
    $qrcode       = $dados['qrcode_value']      ?? null;
    $cid_user     = isset($dados['controlid_user_id']) ? intval($dados['controlid_user_id']) : null;
    $ev_codigo    = isset($dados['evento_codigo']) ? intval($dados['evento_codigo']) : null;
    $veiculo_id   = isset($dados['veiculo_id'])  ? intval($dados['veiculo_id']) : null;
    $morador_id   = isset($dados['morador_id'])  ? intval($dados['morador_id']) : null;
    $liberado     = isset($dados['acesso_liberado']) ? intval($dados['acesso_liberado']) : 0;
    $resposta     = $dados['resposta_enviada']   ?? null;
    $portal_id    = isset($dados['portal_id'])   ? intval($dados['portal_id']) : null;
    $identifier_id = isset($dados['identifier_id']) ? intval($dados['identifier_id']) : null;
    $data_evento  = $dados['data_evento']        ?? date('Y-m-d H:i:s');

    $stmt->bind_param(
        'iissssisisiiisiis',
        $disp_id, $device_id, $uuid, $tipo, $payload,
        $tag, $card, $qrcode, $cid_user, $ev_codigo,
        $veiculo_id, $morador_id, $liberado, $resposta,
        $portal_id, $identifier_id, $data_evento
    );
    $stmt->execute();
    return $conn->insert_id;
}

// ============================================================
// REGISTRAR em dispositivos_controlid_leituras (tabela legado pull)
// ============================================================
function push_registrar_leitura($conn, array $dados) {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO dispositivos_controlid_leituras
         (dispositivo_id, controlid_log_id, data_hora, tipo_evento, tag_value, card_value,
          controlid_user_id, veiculo_id, morador_id, acesso_liberado, processado)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );

    $disp_id    = $dados['dispositivo_id']    ?? null;
    $data_hora  = $dados['data_evento']       ?? date('Y-m-d H:i:s');
    $tipo_ev    = $dados['tipo_evento_codigo'] ?? 0;
    $tag        = $dados['tag_value']         ?? null;
    $card       = isset($dados['card_value']) ? intval($dados['card_value']) : null;
    $cid_user   = isset($dados['controlid_user_id']) ? intval($dados['controlid_user_id']) : null;
    $veiculo_id = isset($dados['veiculo_id'])  ? intval($dados['veiculo_id']) : null;
    $morador_id = isset($dados['morador_id'])  ? intval($dados['morador_id']) : null;
    $liberado   = isset($dados['acesso_liberado']) ? intval($dados['acesso_liberado']) : 0;

    $stmt->bind_param('isiisiiiii', $disp_id, $data_hora, $tipo_ev, $tag, $card,
        $cid_user, $veiculo_id, $morador_id, $liberado);
    $stmt->execute();
}

// ============================================================
// REGISTRAR ACESSO no ERP (registros_acesso)
// ============================================================
function push_registrar_acesso_erp($conn, $veiculo, $disp_id, $fonte = 'push', $extra = '') {
    if (!$veiculo || !isset($veiculo['id'])) return;

    $status = 'Acesso liberado via Control ID Push — ' . ($veiculo['morador_nome'] ?? 'Morador');
    $obs    = "Evento em tempo real via dispositivo #$disp_id ($fonte)" . ($extra ? " | $extra" : '');

    $stmt = $conn->prepare(
        "INSERT INTO registros_acesso
         (data_hora, placa, modelo, cor, tag, tipo, morador_id, status, liberado, observacao)
         VALUES (NOW(), ?, ?, ?, ?, 'Morador', ?, ?, 1, ?)"
    );
    $stmt->bind_param(
        'ssssiiss',
        $veiculo['placa'], $veiculo['modelo'], $veiculo['cor'], $veiculo['tag'],
        $veiculo['morador_id'], $status, $obs
    );
    $stmt->execute();
}

// ============================================================
// MONTAR RESPOSTA DE AUTORIZAÇÃO para o equipamento (online mode)
// ============================================================
function push_resposta_autorizado($veiculo, $portal_id, $disp) {
    $acao   = $disp['acao_acesso']        ?? 'door';
    $params = $disp['acao_acesso_params'] ?? 'door=1';

    return [
        'result' => [
            'event'           => 7, // Access granted
            'user_id'         => intval($veiculo['controlid_user_id'] ?? 0),
            'user_name'       => ($veiculo['morador_nome'] ?? '') . ' — ' . ($veiculo['placa'] ?? ''),
            'user_image'      => false,
            'user_image_hash' => '',
            'portal_id'       => intval($portal_id ?? 1),
            'actions'         => [['action' => $acao, 'parameters' => $params]],
            'duress'          => 0,
            'message'         => 'Acesso Liberado'
        ]
    ];
}

function push_resposta_negado($portal_id) {
    return [
        'result' => [
            'event'           => 6, // Access denied
            'user_id'         => 0,
            'user_name'       => '',
            'user_image'      => false,
            'user_image_hash' => '',
            'portal_id'       => intval($portal_id ?? 1),
            'actions'         => [],
            'duress'          => 0,
            'message'         => 'Acesso Negado'
        ]
    ];
}
