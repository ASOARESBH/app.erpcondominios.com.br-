<?php
/**
 * bridge_receiver.php - Receptor do Control iD Bridge.
 *
 * Compatível com PHP 7+ e HostGator/PHP puro.
 * Autenticação via API Key salva em configuracoes(chave='bridge_api_key').
 *
 * Aceita a API Key em:
 * - Header: X-API-KEY
 * - Header legado: X-Bridge-Key
 * - JSON body: api_key
 * - Query string: ?api_key=...
 */

ob_start();

require_once __DIR__ . '/config.php';

date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$allowed_origins = array(
    'https://asl.erpcondominios.com.br',
    'https://asl.erpcondominio.com.br',
    'http://localhost:8765',
    'http://localhost:8766',
    'http://127.0.0.1:8765',
    'http://127.0.0.1:8766',
);

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, X-Bridge-Key, X-Bridge-ID, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    json_response(array('sucesso' => true), 200);
}

set_exception_handler(function ($e) {
    bridge_log('ERROR', 'Excecao nao tratada: ' . $e->getMessage());
    json_response(array(
        'sucesso' => false,
        'erro' => 'Erro interno no bridge_receiver',
    ), 500);
});

set_error_handler(function ($severity, $message, $file, $line) {
    bridge_log('PHP', $message . ' em ' . $file . ':' . $line);
    return false;
});

$raw_body = file_get_contents('php://input');
bridge_log('DEBUG', 'Body recebido: ' . $raw_body);
$body = parse_json_body($raw_body);
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$bridge_id = get_header_value('X-Bridge-ID');
if ($bridge_id === '') {
    $bridge_id = isset($body['bridge_id']) ? (string)$body['bridge_id'] : 'desconhecido';
}

$conn = conectar_banco();
if (!$conn instanceof mysqli) {
    json_response(array('sucesso' => false, 'erro' => 'Banco de dados indisponivel'), 503);
}

if (!autenticar_bridge($conn, $body)) {
    bridge_log('WARN', 'Autenticacao invalida. IP=' . remote_ip() . ' Bridge=' . $bridge_id);
    fechar_conexao($conn);
    json_response(array('sucesso' => false, 'erro' => 'Autenticação inválida'), 401);
}

$acao = obter_acao($method, $body);
if ($acao === '') {
    $acao = 'heartbeat';
}
bridge_log('INFO', 'Acao=' . $acao . ' Metodo=' . $method . ' Bridge=' . $bridge_id);

try {
    switch ($acao) {
        case 'heartbeat':
            responder_heartbeat($conn, $body, $bridge_id);
            break;

        case 'eventos':
            responder_eventos($conn, $body);
            break;

        case 'fila_comandos':
            responder_fila_comandos($conn, $body);
            break;

        case 'confirmar_comando':
            responder_confirmar_comando($conn, $body);
            break;

        case 'tags_para_sync':
            responder_tags_para_sync($conn);
            break;

        case 'enfileirar_comando':
            responder_enfileirar_comando($conn, $body);
            break;

        default:
            fechar_conexao($conn);
            json_response(array(
                'sucesso' => false,
                'erro' => 'Ação desconhecida ou ausente',
                'acao' => $acao,
            ), 400);
    }
} catch (Exception $e) {
    bridge_log('ERROR', 'Falha na acao ' . $acao . ': ' . $e->getMessage());
    fechar_conexao($conn);
    json_response(array('sucesso' => false, 'erro' => 'Erro ao processar requisição'), 500);
}

/**
 * Envia JSON e encerra a execução, limpando qualquer saída anterior.
 */
function json_response($payload, $status_code = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bridge_log($nivel, $msg) {
    error_log('[BRIDGE][' . $nivel . '] ' . $msg);
}

function remote_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'desconhecido';
}

function parse_json_body($raw_body) {
    if (!$raw_body) {
        return array();
    }

    $decoded = json_decode($raw_body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        bridge_log('WARN', 'JSON body invalido: ' . json_last_error_msg());
        return array();
    }

    return $decoded;
}

function get_header_value($name) {
    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$server_key])) {
        return trim((string)$_SERVER[$server_key]);
    }

    $redirect_key = 'REDIRECT_' . $server_key;
    if (isset($_SERVER[$redirect_key])) {
        return trim((string)$_SERVER[$redirect_key]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $header_name => $value) {
            if (strcasecmp($header_name, $name) === 0) {
                return trim((string)$value);
            }
        }
    }

    return '';
}

function obter_api_key_recebida($body) {
    $key = get_header_value('X-API-KEY');
    if ($key !== '') {
        return $key;
    }

    $legacy_key = get_header_value('X-Bridge-Key');
    if ($legacy_key !== '') {
        return $legacy_key;
    }

    if (isset($body['api_key'])) {
        return trim((string)$body['api_key']);
    }

    if (isset($body['apiKey'])) {
        return trim((string)$body['apiKey']);
    }

    if (isset($_GET['api_key'])) {
        return trim((string)$_GET['api_key']);
    }

    return '';
}

function autenticar_bridge(mysqli $conn, $body) {
    $key_recebida = obter_api_key_recebida($body);
    if ($key_recebida === '') {
        bridge_log('WARN', 'API Key nao enviada');
        return false;
    }

    $chave = 'bridge_api_key';
    $stmt = $conn->prepare('SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1');
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare bridge_api_key falhou: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('s', $chave);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $key_banco = $row && isset($row['valor']) ? trim((string)$row['valor']) : '';
    if ($key_banco === '') {
        bridge_log('ERROR', 'bridge_api_key nao configurada no banco');
        return false;
    }

    return hash_equals($key_banco, $key_recebida);
}

function obter_acao($method, $body) {
    if ($method === 'GET') {
        return isset($_GET['acao']) ? trim((string)$_GET['acao']) : '';
    }

    if (isset($body['acao'])) {
        return trim((string)$body['acao']);
    }

    if (isset($_GET['acao'])) {
        return trim((string)$_GET['acao']);
    }

    return '';
}

function body_get($body, $key, $default = null) {
    return array_key_exists($key, $body) ? $body[$key] : $default;
}

function request_int($body, $key, $default = 0) {
    if (isset($body[$key])) {
        return (int)$body[$key];
    }

    if (isset($_GET[$key])) {
        return (int)$_GET[$key];
    }

    return (int)$default;
}

function normalizar_datetime($value) {
    if ($value === null || $value === '') {
        return date('Y-m-d H:i:s');
    }

    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', (int)$value);
    }

    $timestamp = strtotime((string)$value);
    if ($timestamp === false) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function table_has_column(mysqli $conn, $table, $column) {
    static $cache = array();
    $cache_key = $table . '.' . $column;
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    if (!$stmt) {
        $cache[$cache_key] = false;
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : array('total' => 0);
    $stmt->close();

    $cache[$cache_key] = ((int)$row['total']) > 0;
    return $cache[$cache_key];
}

function responder_heartbeat(mysqli $conn, $body, $bridge_id) {
    $dispositivos = body_get($body, 'dispositivos', array());
    $versao = (string)body_get($body, 'versao', '');

    if (!is_array($dispositivos)) {
        $dispositivos = array();
    }

    foreach ($dispositivos as $d) {
        if (!is_array($d)) {
            continue;
        }

        $disp_id = (int)body_get($d, 'id', 0);
        if ($disp_id <= 0) {
            continue;
        }

        $online = !empty($d['online']) ? 1 : 0;
        $ultimo = normalizar_datetime(body_get($d, 'ultimo_contato', null));
        $erros = (int)body_get($d, 'erros_consecutivos', 0);

        atualizar_dispositivo_heartbeat($conn, $disp_id, $online, $ultimo, $erros, $bridge_id, $versao);
    }

    fechar_conexao($conn);
    json_response(array(
        'sucesso' => true,
        'timestamp' => date('c'),
        'mensagem' => 'Heartbeat registrado',
    ));
}

function responder_eventos(mysqli $conn, $body) {
    $disp_id = (int)body_get($body, 'dispositivo_id', 0);
    $eventos = body_get($body, 'eventos', array());

    if ($disp_id <= 0) {
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'dispositivo_id obrigatório'), 400);
    }

    if (!is_array($eventos) || empty($eventos)) {
        fechar_conexao($conn);
        json_response(array('sucesso' => true, 'processados' => 0, 'erros' => 0));
    }

    $processados = 0;
    $ignorados = 0;
    $erros = 0;
    $ultimo_log = 0;

    foreach ($eventos as $ev) {
        if (!is_array($ev)) {
            $ignorados++;
            continue;
        }

        $log_id_ext = (int)body_get($ev, 'id', 0);
        if ($log_id_ext <= 0) {
            $ignorados++;
            continue;
        }

        $ultimo_log = max($ultimo_log, $log_id_ext);

        if (evento_ja_processado($conn, $disp_id, $log_id_ext)) {
            $ignorados++;
            continue;
        }

        $user_id = (int)body_get($ev, 'user_id', 0);
        $card_value = (string)body_get($ev, 'card_value', body_get($ev, 'uhf_tag', ''));
        $event_type = (int)body_get($ev, 'event_type', 0);
        $event_time = normalizar_datetime(body_get($ev, 'time', null));
        $door_id = (int)body_get($ev, 'door_id', 0);

        $veiculo = buscar_veiculo_por_tag($conn, $card_value);
        $veiculo_id = $veiculo ? (int)$veiculo['id'] : null;
        $morador_id = $veiculo && $veiculo['morador_id'] !== null ? (int)$veiculo['morador_id'] : null;

        if (!inserir_evento_bridge(
            $conn,
            $disp_id,
            $log_id_ext,
            $user_id,
            $card_value,
            $event_type,
            $event_time,
            $door_id,
            $veiculo_id,
            $morador_id
        )) {
            $erros++;
            continue;
        }

        if ($veiculo_id && $morador_id) {
            registrar_acesso_principal($conn, $veiculo, $event_time, $card_value, $disp_id, $event_type);
        }

        $processados++;
    }

    if ($ultimo_log > 0) {
        atualizar_ultimo_log($conn, $disp_id, $ultimo_log);
    }

    fechar_conexao($conn);
    json_response(array(
        'sucesso' => true,
        'processados' => $processados,
        'ignorados' => $ignorados,
        'erros' => $erros,
    ));
}

function evento_ja_processado(mysqli $conn, $disp_id, $log_id_ext) {
    $stmt = $conn->prepare(
        'SELECT id FROM bridge_eventos_log
         WHERE dispositivo_id = ? AND log_id_externo = ?
         LIMIT 1'
    );
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare duplicidade evento falhou: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('ii', $disp_id, $log_id_ext);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function buscar_veiculo_por_tag(mysqli $conn, $tag) {
    $tag = trim((string)$tag);
    if ($tag === '') {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.morador_id, m.nome AS morador_nome
         FROM veiculos v
         LEFT JOIN moradores m ON m.id = v.morador_id
         WHERE v.tag = ? AND v.ativo = 1
         LIMIT 1'
    );
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare buscar veiculo falhou: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('s', $tag);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function inserir_evento_bridge(
    mysqli $conn,
    $disp_id,
    $log_id_ext,
    $user_id,
    $card_value,
    $event_type,
    $event_time,
    $door_id,
    $veiculo_id,
    $morador_id
) {
    $stmt = $conn->prepare(
        'INSERT INTO bridge_eventos_log
         (dispositivo_id, log_id_externo, user_id_controlid, card_value,
          event_type, event_time, door_id, veiculo_id, morador_id, processado)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare inserir evento falhou: ' . $conn->error);
        return false;
    }

    $stmt->bind_param(
        'iiisisiii',
        $disp_id,
        $log_id_ext,
        $user_id,
        $card_value,
        $event_type,
        $event_time,
        $door_id,
        $veiculo_id,
        $morador_id
    );

    $ok = $stmt->execute();
    if (!$ok) {
        bridge_log('ERROR', 'Erro inserir evento: ' . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

function registrar_acesso_principal(mysqli $conn, $veiculo, $event_time, $tag, $disp_id, $event_type) {
    $placa = isset($veiculo['placa']) ? (string)$veiculo['placa'] : '';
    $modelo = isset($veiculo['modelo']) ? (string)$veiculo['modelo'] : '';
    $cor = isset($veiculo['cor']) ? (string)$veiculo['cor'] : '';
    $morador_id = (int)$veiculo['morador_id'];
    $morador_nome = isset($veiculo['morador_nome']) ? (string)$veiculo['morador_nome'] : 'Morador';
    $tipo = 'Morador';
    $status = 'Acesso liberado via Control iD - ' . $morador_nome;
    $liberado = 1;
    $sentido = ((int)$event_type === 1) ? 'saida' : 'entrada';
    $observacao = 'TAG RFID: ' . $tag . ' | Dispositivo ID: ' . $disp_id . ' | Sentido: ' . $sentido;

    if (table_has_column($conn, 'registros_acesso', 'origem')) {
        $origem = 'controlid';
        $stmt = $conn->prepare(
            'INSERT INTO registros_acesso
             (data_hora, placa, modelo, cor, tag, tipo, morador_id, status, liberado, observacao, origem)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            bridge_log('ERROR', 'Prepare registro acesso origem falhou: ' . $conn->error);
            return false;
        }
        $stmt->bind_param('ssssssisiss', $event_time, $placa, $modelo, $cor, $tag, $tipo, $morador_id, $status, $liberado, $observacao, $origem);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO registros_acesso
             (data_hora, placa, modelo, cor, tag, tipo, morador_id, status, liberado, observacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            bridge_log('ERROR', 'Prepare registro acesso falhou: ' . $conn->error);
            return false;
        }
        $stmt->bind_param('ssssssisis', $event_time, $placa, $modelo, $cor, $tag, $tipo, $morador_id, $status, $liberado, $observacao);
    }

    $ok = $stmt->execute();
    if (!$ok) {
        bridge_log('ERROR', 'Erro registrar acesso principal: ' . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

function atualizar_ultimo_log(mysqli $conn, $disp_id, $ultimo_log) {
    if (!table_has_column($conn, 'dispositivos_controlid', 'ultimo_log_id')) {
        return;
    }

    $stmt = $conn->prepare('UPDATE dispositivos_controlid SET ultimo_log_id = ? WHERE id = ?');
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare ultimo_log falhou: ' . $conn->error);
        return;
    }

    $stmt->bind_param('ii', $ultimo_log, $disp_id);
    if (!$stmt->execute()) {
        bridge_log('ERROR', 'Erro atualizar ultimo_log: ' . $stmt->error);
    }
    $stmt->close();
}

function atualizar_dispositivo_heartbeat(mysqli $conn, $disp_id, $online, $ultimo, $erros, $bridge_id, $versao) {
    if (table_has_column($conn, 'dispositivos_controlid', 'online')) {
        executar_update_dispositivo_int($conn, $disp_id, 'online', $online);
    }

    if (table_has_column($conn, 'dispositivos_controlid', 'ultimo_contato')) {
        executar_update_dispositivo_string($conn, $disp_id, 'ultimo_contato', $ultimo);
    }

    if (table_has_column($conn, 'dispositivos_controlid', 'erros_consecutivos')) {
        executar_update_dispositivo_int($conn, $disp_id, 'erros_consecutivos', $erros);
    }

    if (table_has_column($conn, 'dispositivos_controlid', 'bridge_id')) {
        executar_update_dispositivo_string($conn, $disp_id, 'bridge_id', $bridge_id);
    }

    if (table_has_column($conn, 'dispositivos_controlid', 'bridge_versao')) {
        executar_update_dispositivo_string($conn, $disp_id, 'bridge_versao', $versao);
    }

    if (table_has_column($conn, 'dispositivos_controlid', 'status_online')) {
        executar_update_dispositivo_int($conn, $disp_id, 'status_online', $online);
    }

    if (table_has_column($conn, 'dispositivos_controlid', 'ultimo_ping')) {
        $stmt = $conn->prepare('UPDATE dispositivos_controlid SET ultimo_ping = NOW() WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $disp_id);
            if (!$stmt->execute()) {
                bridge_log('ERROR', 'Erro atualizar ultimo_ping: ' . $stmt->error);
            }
            $stmt->close();
        }
    }

    if (table_has_column($conn, 'dispositivos_controlid', 'updated_at')) {
        $stmt = $conn->prepare('UPDATE dispositivos_controlid SET updated_at = NOW() WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $disp_id);
            if (!$stmt->execute()) {
                bridge_log('ERROR', 'Erro atualizar updated_at: ' . $stmt->error);
            }
            $stmt->close();
        }
    }
}

function executar_update_dispositivo_int(mysqli $conn, $disp_id, $column, $value) {
    $allowed = array('online', 'erros_consecutivos', 'status_online');
    if (!in_array($column, $allowed, true)) {
        return;
    }

    $sql = 'UPDATE dispositivos_controlid SET `' . $column . '` = ? WHERE id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare update ' . $column . ' falhou: ' . $conn->error);
        return;
    }

    $stmt->bind_param('ii', $value, $disp_id);
    if (!$stmt->execute()) {
        bridge_log('ERROR', 'Erro update ' . $column . ': ' . $stmt->error);
    }
    $stmt->close();
}

function executar_update_dispositivo_string(mysqli $conn, $disp_id, $column, $value) {
    $allowed = array('ultimo_contato', 'bridge_id', 'bridge_versao');
    if (!in_array($column, $allowed, true)) {
        return;
    }

    $sql = 'UPDATE dispositivos_controlid SET `' . $column . '` = ? WHERE id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare update ' . $column . ' falhou: ' . $conn->error);
        return;
    }

    $stmt->bind_param('si', $value, $disp_id);
    if (!$stmt->execute()) {
        bridge_log('ERROR', 'Erro update ' . $column . ': ' . $stmt->error);
    }
    $stmt->close();
}

function responder_fila_comandos(mysqli $conn, $body) {
    $disp_id = request_int($body, 'dispositivo_id', 0);
    if ($disp_id <= 0) {
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'dispositivo_id obrigatório'), 400);
    }

    $stmt = $conn->prepare(
        'SELECT id, endpoint, verb, body
         FROM bridge_fila_comandos
         WHERE dispositivo_id = ? AND status = "pendente"
         ORDER BY created_at ASC
         LIMIT 10'
    );
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare fila_comandos falhou: ' . $conn->error);
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'Erro ao buscar fila'), 500);
    }

    $stmt->bind_param('i', $disp_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $comandos = array();

    while ($row = $res->fetch_assoc()) {
        $decoded_body = json_decode(isset($row['body']) ? $row['body'] : '{}', true);
        $row['body'] = is_array($decoded_body) ? $decoded_body : array();
        $comandos[] = $row;
    }

    $stmt->close();
    fechar_conexao($conn);
    json_response(array('sucesso' => true, 'comandos' => $comandos));
}

function responder_confirmar_comando(mysqli $conn, $body) {
    $cmd_id = (int)body_get($body, 'cmd_id', body_get($body, 'comando_id', 0));
    $status = (string)body_get($body, 'status', 'executado');
    $permitidos = array('pendente', 'executado', 'erro', 'cancelado');
    if (!in_array($status, $permitidos, true)) {
        $status = 'executado';
    }
    $resultado = json_encode(body_get($body, 'resultado', array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($cmd_id <= 0) {
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'cmd_id obrigatório'), 400);
    }

    $stmt = $conn->prepare(
        'UPDATE bridge_fila_comandos
         SET status = ?, resultado = ?, executado_em = NOW()
         WHERE id = ?'
    );
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare confirmar_comando falhou: ' . $conn->error);
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'Erro ao confirmar comando'), 500);
    }

    $stmt->bind_param('ssi', $status, $resultado, $cmd_id);
    $ok = $stmt->execute();
    $stmt->close();
    fechar_conexao($conn);

    json_response(array('sucesso' => (bool)$ok));
}

function responder_tags_para_sync(mysqli $conn) {
    $stmt = $conn->prepare(
        'SELECT v.id AS veiculo_id, v.placa, v.modelo, v.tag,
                v.morador_id,
                COALESCE(m.nome, d.nome_completo, "Desconhecido") AS nome
         FROM veiculos v
         LEFT JOIN moradores m ON m.id = v.morador_id
         LEFT JOIN dependentes d ON d.id = v.dependente_id
         WHERE v.ativo = 1 AND v.tag IS NOT NULL AND v.tag <> ""
         ORDER BY v.id'
    );
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare tags_para_sync falhou: ' . $conn->error);
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'Erro ao buscar TAGs'), 500);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $tags = array();
    while ($row = $res->fetch_assoc()) {
        $tags[] = $row;
    }
    $stmt->close();
    fechar_conexao($conn);

    json_response(array('sucesso' => true, 'tags' => $tags, 'total' => count($tags)));
}

function responder_enfileirar_comando(mysqli $conn, $body) {
    $disp_id = (int)body_get($body, 'dispositivo_id', 0);
    $endpoint = trim((string)body_get($body, 'endpoint', ''));
    $verb = strtoupper(trim((string)body_get($body, 'verb', 'POST')));
    $body_cmd = json_encode(body_get($body, 'body', array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $verbos_permitidos = array('GET', 'POST', 'PUT', 'DELETE');
    if (!in_array($verb, $verbos_permitidos, true)) {
        $verb = 'POST';
    }

    if ($disp_id <= 0 || $endpoint === '') {
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'dispositivo_id e endpoint são obrigatórios'), 400);
    }

    $stmt = $conn->prepare(
        'INSERT INTO bridge_fila_comandos
         (dispositivo_id, endpoint, verb, body, status, created_at)
         VALUES (?, ?, ?, ?, "pendente", NOW())'
    );
    if (!$stmt) {
        bridge_log('ERROR', 'Prepare enfileirar_comando falhou: ' . $conn->error);
        fechar_conexao($conn);
        json_response(array('sucesso' => false, 'erro' => 'Erro ao enfileirar comando'), 500);
    }

    $stmt->bind_param('isss', $disp_id, $endpoint, $verb, $body_cmd);
    $ok = $stmt->execute();
    $cmd_id = $ok ? $conn->insert_id : 0;
    if (!$ok) {
        bridge_log('ERROR', 'Erro enfileirar comando: ' . $stmt->error);
    }
    $stmt->close();
    fechar_conexao($conn);

    json_response(array('sucesso' => (bool)$ok, 'cmd_id' => $cmd_id), $ok ? 200 : 500);
}
