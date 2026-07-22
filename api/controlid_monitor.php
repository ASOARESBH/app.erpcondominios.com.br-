<?php
/**
 * controlid_monitor.php - Receptor nativo do Monitor da Control iD
 *
 * Este endpoint recebe os eventos enviados DIRETAMENTE pelo equipamento
 * Control iD quando configurado com o Monitor nativo (sem bridge local).
 *
 * Configuração no equipamento (via API ou interface web):
 *   POST /set_configuration.fcgi?session=XXXX
 *   {
 *     "monitor": {
 *       "request_timeout": "5000",
 *       "hostname": "app.erpcondominios.com.br",
 *       "port": "443",
 *       "path": "api/controlid_monitor.php"
 *     }
 *   }
 *
 * O equipamento enviará eventos para:
 *   https://app.erpcondominios.com.br/api/controlid_monitor.php/dao
 *   https://app.erpcondominios.com.br/api/controlid_monitor.php/door
 *   https://app.erpcondominios.com.br/api/controlid_monitor.php/operation_mode
 */

ob_start();

require_once __DIR__ . '/config.php';

date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Permite qualquer origem (o equipamento não envia Origin)
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

function monitor_log($nivel, $msg) {
    error_log('[MONITOR_CONTROLID][' . $nivel . '] ' . $msg);
}

function monitor_json($payload, $code = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Determinar o sub-endpoint (dao, door, operation_mode, etc.)
$path_info = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
if ($path_info === '') {
    // Tenta extrair do REQUEST_URI
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $parts = explode('controlid_monitor.php/', $uri);
    $path_info = isset($parts[1]) ? trim(explode('?', $parts[1])[0], '/') : '';
}

$raw_body = file_get_contents('php://input');
monitor_log('DEBUG', "Path=$path_info Body=" . substr($raw_body, 0, 500));

$body = json_decode($raw_body, true);
if (!is_array($body)) {
    $body = [];
}

$device_id = isset($body['device_id']) ? (int)$body['device_id'] : 0;

// Conectar ao banco
$conn = conectar_banco();
if (!$conn instanceof mysqli) {
    monitor_json(['ok' => false, 'erro' => 'Banco indisponivel'], 503);
}

switch ($path_info) {
    case 'dao':
        // Evento principal: alterações em access_logs, cards, templates
        processar_dao($conn, $body, $device_id);
        break;

    case 'door':
        // Evento de abertura/fechamento de porta
        monitor_log('INFO', "Evento door. device_id=$device_id");
        fechar_conexao($conn);
        monitor_json(['ok' => true]);
        break;

    case 'operation_mode':
        // Mudança de modo de operação (online/offline/contingência)
        $mode_name = isset($body['operation_mode']['mode_name']) ? $body['operation_mode']['mode_name'] : 'UNKNOWN';
        monitor_log('INFO', "Modo operacao: $mode_name device_id=$device_id");
        fechar_conexao($conn);
        monitor_json(['ok' => true]);
        break;

    case 'device_is_alive':
        // Heartbeat do equipamento
        monitor_log('INFO', "Alive. device_id=$device_id");
        atualizar_dispositivo_por_device_id($conn, $device_id);
        fechar_conexao($conn);
        monitor_json(['ok' => true]);
        break;

    default:
        // Endpoint desconhecido - responde OK para não gerar erros no equipamento
        monitor_log('WARN', "Endpoint desconhecido: $path_info");
        fechar_conexao($conn);
        monitor_json(['ok' => true]);
        break;
}

function processar_dao(mysqli $conn, $body, $device_id) {
    $changes = isset($body['object_changes']) ? $body['object_changes'] : [];
    if (!is_array($changes) || empty($changes)) {
        fechar_conexao($conn);
        monitor_json(['ok' => true, 'processados' => 0]);
    }

    $processados = 0;
    $ignorados   = 0;

    foreach ($changes as $change) {
        if (!is_array($change)) continue;

        $object = isset($change['object']) ? $change['object'] : '';
        $type   = isset($change['type'])   ? $change['type']   : '';
        $values = isset($change['values']) ? $change['values'] : [];

        if ($object !== 'access_logs' || $type !== 'inserted') {
            $ignorados++;
            continue;
        }

        // Extrair campos do log de acesso
        $log_id_ext = isset($values['id'])         ? (int)$values['id']         : 0;
        $event_time_unix = isset($values['time'])  ? (int)$values['time']       : time();
        $event_type = isset($values['event'])      ? (int)$values['event']      : 0;
        $user_id    = isset($values['user_id'])    ? (int)$values['user_id']    : 0;
        $card_value = isset($values['card_value']) ? (string)$values['card_value'] : '';
        $portal_id  = isset($values['portal_id'])  ? (int)$values['portal_id'] : 0;

        $event_time = date('Y-m-d H:i:s', $event_time_unix);

        if ($log_id_ext <= 0) {
            $ignorados++;
            continue;
        }

        // Verificar duplicidade
        if (evento_monitor_ja_processado($conn, $device_id, $log_id_ext)) {
            $ignorados++;
            continue;
        }

        // Buscar veículo pela TAG
        $veiculo = buscar_veiculo_por_tag_monitor($conn, $card_value);
        $veiculo_id = $veiculo ? (int)$veiculo['id'] : null;
        $morador_id = $veiculo && $veiculo['morador_id'] ? (int)$veiculo['morador_id'] : null;

        // Inserir no log de eventos bridge
        inserir_evento_monitor($conn, $device_id, $log_id_ext, $user_id, $card_value, $event_type, $event_time, $portal_id, $veiculo_id, $morador_id);

        // Registrar acesso principal se veículo identificado
        if ($veiculo && $morador_id) {
            registrar_acesso_monitor($conn, $veiculo, $event_time, $card_value, $device_id, $event_type);
        }

        $processados++;
    }

    fechar_conexao($conn);
    monitor_log('INFO', "DAO processado: processados=$processados ignorados=$ignorados device=$device_id");
    monitor_json(['ok' => true, 'processados' => $processados, 'ignorados' => $ignorados]);
}

function evento_monitor_ja_processado(mysqli $conn, $device_id, $log_id_ext) {
    // Usa a mesma tabela do bridge para evitar duplicidade entre os dois métodos
    $stmt = $conn->prepare(
        'SELECT id FROM bridge_eventos_log WHERE dispositivo_id = ? AND log_id_externo = ? LIMIT 1'
    );
    if (!$stmt) return false;
    $stmt->bind_param('ii', $device_id, $log_id_ext);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function buscar_veiculo_por_tag_monitor(mysqli $conn, $tag) {
    $tag = trim((string)$tag);
    if ($tag === '') return null;

    $stmt = $conn->prepare(
        'SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.morador_id, m.nome AS morador_nome
         FROM veiculos v
         LEFT JOIN moradores m ON m.id = v.morador_id
         WHERE v.tag = ? AND v.ativo = 1
         LIMIT 1'
    );
    if (!$stmt) return null;
    $stmt->bind_param('s', $tag);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function inserir_evento_monitor(mysqli $conn, $device_id, $log_id_ext, $user_id, $card_value, $event_type, $event_time, $door_id, $veiculo_id, $morador_id) {
    // Tenta mapear o device_id da Control iD para o ID interno do dispositivo
    $disp_id_interno = obter_dispositivo_interno($conn, $device_id);

    $stmt = $conn->prepare(
        'INSERT INTO bridge_eventos_log
         (dispositivo_id, log_id_externo, user_id_controlid, card_value,
          event_type, event_time, door_id, veiculo_id, morador_id, processado)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    if (!$stmt) return false;

    $stmt->bind_param('iiisisiii', $disp_id_interno, $log_id_ext, $user_id, $card_value, $event_type, $event_time, $door_id, $veiculo_id, $morador_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function obter_dispositivo_interno(mysqli $conn, $device_id_controlid) {
    // Tenta encontrar o dispositivo pelo device_id_controlid na tabela nova
    $stmt = $conn->prepare(
        'SELECT id FROM controlid_dispositivos WHERE device_id_controlid = ? AND ativo = 1 LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('i', $device_id_controlid);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) return (int)$row['id'];
    }
    // Fallback: retorna o primeiro dispositivo ativo cadastrado
    $res = $conn->query('SELECT id FROM controlid_dispositivos WHERE ativo = 1 ORDER BY id LIMIT 1');
    if ($res) {
        $row = $res->fetch_assoc();
        if ($row) return (int)$row['id'];
    }
    return 1;
}

function registrar_acesso_monitor(mysqli $conn, $veiculo, $event_time, $tag, $device_id, $event_type) {
    $placa       = isset($veiculo['placa'])        ? (string)$veiculo['placa']        : '';
    $modelo      = isset($veiculo['modelo'])       ? (string)$veiculo['modelo']       : '';
    $cor         = isset($veiculo['cor'])          ? (string)$veiculo['cor']          : '';
    $morador_id  = (int)$veiculo['morador_id'];
    $morador_nome = isset($veiculo['morador_nome']) ? (string)$veiculo['morador_nome'] : 'Morador';
    $tipo        = 'Morador';
    $status      = 'Acesso liberado via Control iD Monitor - ' . $morador_nome;
    $liberado    = 1;
    $sentido     = ((int)$event_type === 1) ? 'saida' : 'entrada';
    $observacao  = 'TAG RFID: ' . $tag . ' | Device ID: ' . $device_id . ' | Sentido: ' . $sentido;
    $origem      = 'controlid_monitor';

    $stmt = $conn->prepare(
        'INSERT INTO registros_acesso
         (data_hora, placa, modelo, cor, tag, tipo, morador_id, status, liberado, observacao)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        monitor_log('ERROR', 'Prepare registrar_acesso_monitor falhou: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('ssssssisis', $event_time, $placa, $modelo, $cor, $tag, $tipo, $morador_id, $status, $liberado, $observacao);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function atualizar_dispositivo_por_device_id(mysqli $conn, $device_id) {
    // Atualiza ultimo_keep_alive na tabela nova controlid_dispositivos
    $stmt = $conn->prepare(
        'UPDATE controlid_dispositivos SET ultimo_keep_alive = NOW()
         WHERE device_id_controlid = ? AND ativo = 1'
    );
    if ($stmt) {
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $stmt->close();
    }
}

function fechar_conexao(mysqli $conn) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
}
