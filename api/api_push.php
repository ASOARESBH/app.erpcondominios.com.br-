<?php
file_put_contents("log_push.txt", date("Y-m-d H:i:s")." OK\n", FILE_APPEND);

/**
 * api_push.php - Endpoint compatível com Control iD Push Mode.
 *
 * Compatibilidade:
 * - Equipamentos antigos sem campo/header de token.
 * - GET /api/api_push.php?deviceId=...&uuid=... para buscar comandos.
 * - POST /api/api_push.php?deviceId=...&uuid=... para resultado/eventos.
 * - POST JSON legado com device_id/serial_number/events.
 *
 * O equipamento sempre recebe JSON valido. Quando nao ha comando, retorna {}.
 */

ob_start();
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

push_headers();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    push_respond(array(), 200);
}

$raw_body = (string) file_get_contents('php://input');
$body = push_parse_json($raw_body);

if ($method !== 'GET' && $method !== 'POST') {
    push_log('WARN', 'Metodo nao permitido: ' . $method);
    push_respond(array(), 200);
}

$device_identifier = push_device_identifier($body);
$uuid = trim((string) ($_GET['uuid'] ?? $body['uuid'] ?? ''));

$conn = conectar_banco();
if (!($conn instanceof mysqli)) {
    push_respond(array('erro' => 'Banco indisponivel'), 503);
}

$dispositivo = push_buscar_dispositivo($conn, $device_identifier);
if ($dispositivo === null) {
    push_log(
        'WARN',
        'Dispositivo nao encontrado. identificador=' . ($device_identifier !== '' ? $device_identifier : 'vazio')
        . ' ip=' . push_ip()
    );
    fechar_conexao($conn);
    push_respond(array(), 200);
}

if (!push_autenticar($dispositivo, $body)) {
    push_log('WARN', 'Token invalido para serial=' . $dispositivo['serial_number'] . ' ip=' . push_ip());
    fechar_conexao($conn);
    push_respond(array(), 200);
}

$serial = (string) $dispositivo['serial_number'];
push_atualizar_keepalive($conn, $serial);

if ($method === 'POST') {
    push_processar_post($conn, $serial, $body, $uuid);
}

$comando = push_proximo_comando($conn, $serial);
fechar_conexao($conn);

if ($comando !== null) {
    push_log('INFO', 'Comando entregue: id=' . $comando['id'] . ' serial=' . $serial);
    push_respond(array(
        'verb' => $comando['verbo'],
        'endpoint' => $comando['endpoint'],
        'body' => push_json_array($comando['corpo_json'] ?? '{}'),
    ));
}

push_respond(array());

function push_headers()
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-ControlID-Token, X-Bridge-Key, X-API-KEY');
}

function push_respond(array $payload, $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code((int) $status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        empty($payload) ? new stdClass() : $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function push_log($nivel, $msg)
{
    error_log('[CONTROLID_PUSH][' . $nivel . '] ' . $msg);
}

function push_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
}

function push_parse_json($raw)
{
    if ($raw === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        push_log('WARN', 'JSON invalido: ' . json_last_error_msg() . ' raw=' . substr($raw, 0, 500));
        return array();
    }

    return $decoded;
}

function push_json_array($raw)
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

function push_header($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return trim((string) $_SERVER[$key]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $h => $v) {
            if (strcasecmp($h, $name) === 0) {
                return trim((string) $v);
            }
        }
    }
    return '';
}

function push_device_identifier(array $body)
{
    $candidates = array(
        $_GET['deviceId'] ?? null,
        $_GET['device_id'] ?? null,
        $_GET['serial_number'] ?? null,
        $_GET['serial'] ?? null,
        $body['deviceId'] ?? null,
        $body['device_id'] ?? null,
        $body['serial_number'] ?? null,
        $body['serial'] ?? null,
    );

    foreach ($candidates as $candidate) {
        $value = trim((string) $candidate);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function push_buscar_dispositivo(mysqli $conn, $identifier)
{
    $identifier = trim((string) $identifier);
    if ($identifier === '') {
        return null;
    }

    $sql = 'SELECT id, nome_dispositivo, serial_number, token_autenticacao
            FROM controlid_dispositivos
            WHERE ativo = 1 AND serial_number = ?
            LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        push_log('ERROR', 'Prepare buscar por serial: ' . $conn->error);
        return null;
    }
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) {
        return $row;
    }

    if (ctype_digit($identifier)) {
        $id = (int) $identifier;
        $stmt = $conn->prepare(
            'SELECT id, nome_dispositivo, serial_number, token_autenticacao
             FROM controlid_dispositivos
             WHERE ativo = 1 AND id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            push_log('ERROR', 'Prepare buscar por id: ' . $conn->error);
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    // Compatibilidade para firmware antigo: algumas telas mostram o serial
    // fisico, mas o Push envia um deviceId numerico diferente. Se existir
    // apenas um leitor ativo, assumimos esse leitor e registramos no log.
    $stmt = $conn->prepare(
        'SELECT id, nome_dispositivo, serial_number, token_autenticacao
         FROM controlid_dispositivos
         WHERE ativo = 1
         LIMIT 2'
    );
    if (!$stmt) {
        push_log('ERROR', 'Prepare fallback leitor unico: ' . $conn->error);
        return null;
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = array();
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    if (count($rows) === 1) {
        push_log('WARN', 'Fallback leitor unico usado para identificador=' . $identifier);
        return $rows[0];
    }

    return null;
}

function push_autenticar(array $dispositivo, array $body)
{
    $token_recebido = push_header('X-ControlID-Token');
    if ($token_recebido === '') {
        $token_recebido = push_header('X-Bridge-Key');
    }
    if ($token_recebido === '') {
        $token_recebido = push_header('X-API-KEY');
    }
    if ($token_recebido === '') {
        $token_recebido = trim((string) ($body['token'] ?? $body['api_key'] ?? ''));
    }

    $token_cadastrado = trim((string) ($dispositivo['token_autenticacao'] ?? ''));

    // Compatibilidade com firmware/software antigo: se nao veio token, aceita
    // o dispositivo cadastrado. A seguranca fica no cadastro do identificador.
    if ($token_recebido === '') {
        return true;
    }

    if ($token_cadastrado === '') {
        return true;
    }

    return hash_equals($token_cadastrado, $token_recebido);
}

function push_atualizar_keepalive(mysqli $conn, $serial)
{
    $stmt = $conn->prepare(
        'UPDATE controlid_dispositivos
         SET ultimo_keep_alive = NOW()
         WHERE serial_number = ?'
    );
    if (!$stmt) {
        push_log('ERROR', 'Prepare keepalive: ' . $conn->error);
        return;
    }
    $stmt->bind_param('s', $serial);
    if (!$stmt->execute()) {
        push_log('ERROR', 'Keepalive execute: ' . $stmt->error);
    }
    $stmt->close();
}

function push_processar_post(mysqli $conn, $serial, array $body, $uuid)
{
    $eventos = array();
    if (isset($body['events']) && is_array($body['events'])) {
        $eventos = $body['events'];
    } elseif (isset($body['eventos']) && is_array($body['eventos'])) {
        $eventos = $body['eventos'];
    }

    if (!empty($eventos)) {
        push_registrar_eventos($conn, $serial, $eventos);
    }

    if (isset($body['response']) || isset($body['error']) || isset($body['transactions_results'])) {
        push_marcar_resultado($conn, $serial, $body, $uuid);
    }
}

function push_registrar_eventos(mysqli $conn, $serial, array $eventos)
{
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO controlid_eventos_acesso
         (serial_number, user_id, data_hora, tipo_evento, raw_payload)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        push_log('ERROR', 'Prepare evento: ' . $conn->error);
        return;
    }

    foreach ($eventos as $ev) {
        if (!is_array($ev)) {
            continue;
        }

        $user_id = (int) ($ev['user_id'] ?? 0);
        $tipo = (int) ($ev['event'] ?? $ev['event_type'] ?? 0);
        $data_hora = push_normalizar_datetime($ev['time'] ?? $ev['data_hora'] ?? null);
        $raw = json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt->bind_param('siiss', $serial, $user_id, $data_hora, $tipo, $raw);
        if (!$stmt->execute()) {
            push_log('WARN', 'Erro inserir evento: ' . $stmt->error);
        }
    }

    $stmt->close();
}

function push_marcar_resultado(mysqli $conn, $serial, array $body, $uuid)
{
    // A tabela v2 atual tem enum pendente/enviado/cancelado. Mantemos "enviado"
    // apos o POST /result para nao quebrar ambientes sem coluna de resultado.
    $status = 'enviado';
    $resultado = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare(
        'UPDATE controlid_fila_comandos
         SET status = ?, enviado_em = COALESCE(enviado_em, NOW())
         WHERE serial_number = ? AND status = "enviado"
         ORDER BY enviado_em DESC
         LIMIT 1'
    );

    if (!$stmt) {
        push_log('ERROR', 'Prepare resultado: ' . $conn->error . ' uuid=' . $uuid . ' resultado=' . $resultado);
        return;
    }

    $stmt->bind_param('ss', $status, $serial);
    if (!$stmt->execute()) {
        push_log('WARN', 'Erro atualizar resultado: ' . $stmt->error);
    }
    $stmt->close();
}

function push_proximo_comando(mysqli $conn, $serial)
{
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        'SELECT id, verbo, endpoint, corpo_json
         FROM controlid_fila_comandos
         WHERE serial_number = ? AND status = "pendente"
         ORDER BY criado_em ASC
         LIMIT 1
         FOR UPDATE'
    );
    if (!$stmt) {
        push_log('ERROR', 'Prepare comando: ' . $conn->error);
        $conn->rollback();
        return null;
    }

    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $res = $stmt->get_result();
    $comando = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$comando) {
        $conn->rollback();
        return null;
    }

    $id = (int) $comando['id'];
    $upd = $conn->prepare(
        'UPDATE controlid_fila_comandos
         SET status = "enviado", enviado_em = NOW()
         WHERE id = ?'
    );
    if (!$upd) {
        push_log('ERROR', 'Prepare update comando: ' . $conn->error);
        $conn->rollback();
        return null;
    }

    $upd->bind_param('i', $id);
    $ok = $upd->execute();
    $upd->close();

    if (!$ok) {
        push_log('ERROR', 'Update comando falhou: ' . $conn->error);
        $conn->rollback();
        return null;
    }

    $conn->commit();
    return $comando;
}

function push_normalizar_datetime($value)
{
    if ($value === null || $value === '') {
        return date('Y-m-d H:i:s');
    }
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', (int) $value);
    }
    $ts = strtotime((string) $value);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}
