<?php
/**
 * _helper.php — Funções compartilhadas para endpoints Control ID Push/Online Mode
 *
 * Usado por: push.php, result.php, new_uhf_tag.php, new_user_identified.php,
 *            new_card.php, new_qrcode.php, device_is_alive.php,
 *            notifications/dao.php, notifications/door.php
 */

require_once __DIR__ . '/../config.php';

// O ControlID não usa sessão PHP, mas os acessos liberados precisam gerar
// eventos persistentes para o Portal do Morador. O helper compartilha o
// mesmo transporte FCM já aprovado para protocolos e registros manuais.
$__controle_acesso_notificacao_helper = __DIR__ . '/../helpers/access_control_notification_helper.php';
if (is_file($__controle_acesso_notificacao_helper)) {
    require_once $__controle_acesso_notificacao_helper;
}
unset($__controle_acesso_notificacao_helper);

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
                m.nome AS morador_nome, m.unidade, m.tenant_id
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
                m.nome AS morador_nome, m.unidade, m.tenant_id
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
                m.nome AS morador_nome, m.unidade, m.tenant_id
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
    if (!$veiculo || !isset($veiculo['id'])) {
        error_log('[CONTROLID_ACCESS] veiculo_invalido fonte=' . $fonte);
        return ['sucesso' => false, 'motivo' => 'veiculo_invalido'];
    }

    $morador_id = (int)($veiculo['morador_id'] ?? 0);
    $tenant_id  = (int)($veiculo['tenant_id'] ?? 0);
    $unidade    = trim((string)($veiculo['unidade'] ?? ''));
    $status     = 'Acesso liberado via Control ID Push — ' . ($veiculo['morador_nome'] ?? 'Morador');
    $obs        = "Evento em tempo real via dispositivo #$disp_id ($fonte)" . ($extra ? " | $extra" : '');

    $stmt = $conn->prepare(
        "INSERT INTO registros_acesso
         (data_hora, placa, modelo, cor, tag, tipo, morador_id, unidade_destino, status, liberado, observacao)
         VALUES (NOW(), ?, ?, ?, ?, 'Morador', ?, ?, ?, 1, ?)"
    );
    if (!$stmt) {
        error_log('[CONTROLID_ACCESS] insert_prepare_falhou fonte=' . $fonte . ' erro=' . $conn->error);
        return ['sucesso' => false, 'motivo' => 'insert_prepare_falhou'];
    }

    $placa  = (string)($veiculo['placa'] ?? '');
    $modelo = (string)($veiculo['modelo'] ?? '');
    $cor    = (string)($veiculo['cor'] ?? '');
    $tag    = (string)($veiculo['tag'] ?? '');
    $stmt->bind_param('ssssisss', $placa, $modelo, $cor, $tag, $morador_id, $unidade, $status, $obs);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        error_log('[CONTROLID_ACCESS] insert_execucao_falhou fonte=' . $fonte . ' erro=' . $erro);
        return ['sucesso' => false, 'motivo' => 'insert_execucao_falhou'];
    }

    $registro_acesso_id = (int)$conn->insert_id;
    $stmt->close();

    // O acesso na catraca é prioritário: qualquer problema abaixo é apenas
    // registrado e jamais interfere na resposta entregue ao equipamento.
    $notificacao = ['sucesso' => false, 'motivo' => 'nao_processada'];
    try {
        if ($registro_acesso_id <= 0) {
            $notificacao = ['sucesso' => false, 'motivo' => 'registro_sem_id_valido'];
            error_log('[CONTROLID_ACCESS] registro_sem_id_valido fonte=' . $fonte . ' morador=' . $morador_id);
        } elseif ($tenant_id <= 0 || $morador_id <= 0) {
            $notificacao = ['sucesso' => false, 'motivo' => 'destinatario_sem_tenant'];
            error_log('[CONTROLID_ACCESS] destinatario_sem_tenant registro=' . $registro_acesso_id . ' fonte=' . $fonte);
        } elseif (function_exists('controle_acesso_criar_notificacao_registro')) {
            $notificacao = controle_acesso_criar_notificacao_registro(
                $conn,
                $tenant_id,
                $registro_acesso_id,
                $morador_id,
                $unidade,
                'Entrada',
                'Morador',
                $placa,
                $modelo,
                date('Y-m-d H:i:s')
            );
        } else {
            $notificacao = ['sucesso' => false, 'motivo' => 'helper_notificacao_indisponivel'];
            error_log('[CONTROLID_ACCESS] helper_notificacao_indisponivel registro=' . $registro_acesso_id);
        }
    } catch (Throwable $erro_notificacao) {
        $notificacao = ['sucesso' => false, 'motivo' => 'excecao_nao_bloqueante'];
        error_log('[CONTROLID_ACCESS] notificacao_falhou registro=' . $registro_acesso_id . ' erro=' . $erro_notificacao->getMessage());
    }

    error_log('[CONTROLID_ACCESS] processado registro=' . $registro_acesso_id .
        ' tenant=' . $tenant_id . ' morador=' . $morador_id .
        ' fonte=' . $fonte . ' notificacao=' . json_encode($notificacao, JSON_UNESCAPED_UNICODE));

    return [
        'sucesso' => true,
        'registro_acesso_id' => $registro_acesso_id,
        'notificacao_controle_acesso' => $notificacao,
    ];
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
