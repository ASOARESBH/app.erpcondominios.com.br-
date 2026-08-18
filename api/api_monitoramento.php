<?php
/**
 * API do ERP CONDOMÍNIOS MONITORING.
 *
 * Agente local: bearer individual por agente.
 * Navegador: sessão PHP do ERP.
 * Tenant: sempre resolvido pelo vínculo autenticado; nunca confiado ao corpo.
 */
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/monitoramento_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$allowed_origin = 'https://app.erpcondominios.com.br';
if (($_SERVER['HTTP_ORIGIN'] ?? '') === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conexao = conectar_banco();
$action = $_GET['action'] ?? $_GET['acao'] ?? '';
$input = monitoring_json_input();

try {
    switch ($action) {
        case 'solicitar_pareamento':
            monitoring_require_method('POST');
            _monitoring_solicitar_pareamento($conexao, $input);
            break;
        case 'login':
            monitoring_require_method('POST');
            _monitoring_login($conexao, $input);
            break;
        case 'heartbeat':
            monitoring_require_method('POST');
            _monitoring_heartbeat($conexao, $input);
            break;
        case 'ingestir_lote':
        case 'eventos_lote':
            monitoring_require_method('POST');
            _monitoring_ingestir_lote($conexao, $input);
            break;
        case 'acessos_recentes':
            monitoring_require_method('GET');
            _monitoring_acessos_recentes($conexao);
            break;
        case 'listar_agentes':
            monitoring_require_method('GET');
            _monitoring_listar_agentes($conexao);
            break;
        case 'habilitar_agente':
            monitoring_require_method('POST');
            _monitoring_habilitar_agente($conexao, $input);
            break;
        case 'revogar_agente':
            monitoring_require_method('POST');
            _monitoring_revogar_agente($conexao, $input);
            break;
        case 'salvar_configuracao':
            monitoring_require_method('POST');
            _monitoring_salvar_configuracao($conexao, $input);
            break;
        case 'configuracao':
            monitoring_require_method('GET');
            _monitoring_configuracao($conexao);
            break;
        default:
            monitoring_json(false, 'Ação inválida.', null, 400, 'INVALID_ACTION');
    }
} catch (Throwable $e) {
    error_log('[MONITORING][API] erro=' . $e->getMessage());
    monitoring_json(false, 'Não foi possível processar o Monitoramento.', null, 500, 'MONITORING_INTERNAL_ERROR');
} finally {
    fechar_conexao($conexao);
}

function _monitoring_solicitar_pareamento($conexao, $input) {
    $install_id = trim((string)($input['install_id'] ?? ''));
    $hardware_fingerprint = trim((string)($input['hardware_fingerprint'] ?? ''));
    $pairing_code = strtoupper(trim((string)($input['pairing_code'] ?? '')));
    if (!monitoring_valid_install_id($install_id) || strlen($hardware_fingerprint) < 32 || !monitoring_valid_pairing_code($pairing_code)) {
        monitoring_json(false, 'Dados de pareamento inválidos.', null, 422, 'PAIRING_INVALID');
    }

    $stmt = $conexao->prepare("SELECT id, status, tenant_id FROM monitoramento_agentes WHERE install_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $install_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $fingerprint_hash = hash('sha256', $hardware_fingerprint);
    $pairing_hash = password_hash($pairing_code, PASSWORD_DEFAULT);
    $preview = monitoring_preview($pairing_code, 4, 4);
    $machine_name = substr(trim((string)($input['machine_name'] ?? 'Máquina Monitoring')), 0, 120);
    $agent_version = substr(trim((string)($input['agent_version'] ?? '')), 0, 30);
    $api_contract_version = substr(trim((string)($input['api_contract_version'] ?? '')), 0, 20);
    $engine = substr(trim((string)($input['lpr_engine'] ?? 'fastalpr')), 0, 40);
    $engine_version = substr(trim((string)($input['lpr_engine_version'] ?? '')), 0, 40);

    if ($existing && in_array($existing['status'], ['ativo', 'bloqueado'], true)) {
        monitoring_json(true, 'Esta instalação já possui um registro.', [
            'agent_id' => (int)$existing['id'],
            'status' => $existing['status'],
            'tenant_id' => $existing['tenant_id'] ? (int)$existing['tenant_id'] : null,
        ]);
    }

    if ($existing) {
        $stmt = $conexao->prepare(
            "UPDATE monitoramento_agentes
                SET hardware_fingerprint_hash = ?, pairing_code_hash = ?, pairing_code_preview = ?,
                    pairing_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE), nome = ?,
                    agent_version = ?, api_contract_version = ?, lpr_engine = ?, lpr_engine_version = ?,
                    status = 'pendente_ativacao', atualizado_em = NOW()
              WHERE id = ?"
        );
        $id = (int)$existing['id'];
        $stmt->bind_param('ssssssssi', $fingerprint_hash, $pairing_hash, $preview, $machine_name, $agent_version, $api_contract_version, $engine, $engine_version, $id);
    } else {
        $stmt = $conexao->prepare(
            "INSERT INTO monitoramento_agentes
                (tenant_id, install_id, nome, hardware_fingerprint_hash, pairing_code_hash,
                 pairing_code_preview, pairing_expires_at, status, agent_version,
                 api_contract_version, lpr_engine, lpr_engine_version)
             VALUES (NULL, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pendente_ativacao', ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssssssss', $install_id, $machine_name, $fingerprint_hash, $pairing_hash, $preview, $agent_version, $api_contract_version, $engine, $engine_version);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        monitoring_json(false, 'Não foi possível registrar a solicitação.', null, 500, 'PAIRING_SAVE_ERROR');
    }
    $agent_id = $existing ? (int)$existing['id'] : (int)$stmt->insert_id;
    $stmt->close();
    monitoring_log('MONITORING_PAIRING_REQUEST', 'Solicitação de pareamento registrada. agent_id=' . $agent_id);
    monitoring_json(true, 'Solicitação de pareamento registrada.', [
        'agent_id' => $agent_id,
        'status' => 'pendente_ativacao',
        'pairing_code_preview' => $preview,
        'expires_at' => date('c', time() + 1800),
    ]);
}

function _monitoring_login($conexao, $input) {
    $email = trim((string)($input['email'] ?? ''));
    $senha = (string)($input['senha'] ?? '');
    $agent_id = (int)($input['agent_id'] ?? 0);
    $agent_secret = (string)($input['agent_secret'] ?? '');
    $install_id = trim((string)($input['install_id'] ?? ''));
    $hardware_fingerprint = trim((string)($input['hardware_fingerprint'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '' || $agent_id <= 0 || $agent_secret === '' || $install_id === '' || $hardware_fingerprint === '') {
        monitoring_json(false, 'E-mail ou senha incorretos.', null, 401, 'AUTH_REQUIRED');
    }

    $stmt = $conexao->prepare(
        "SELECT id, tenant_id, install_id, agent_secret_hash, status, nome, local,
                agent_version, api_contract_version, lpr_engine, lpr_engine_version
         FROM monitoramento_agentes WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $agent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$agent || !$agent['tenant_id']) monitoring_json(false, 'Máquina não autorizada.', null, 401, 'AGENT_NOT_FOUND');
    if ($agent['status'] !== 'ativo') {
        $code = $agent['status'] === 'revogado' ? 'AGENT_REVOKED' : 'AGENT_BLOCKED';
        monitoring_json(false, 'Máquina não autorizada.', null, 401, $code);
    }
    if (!hash_equals((string)$agent['install_id'], $install_id)) {
        monitoring_json(false, 'Identificação da máquina divergente.', null, 401, 'HARDWARE_MISMATCH');
    }
    if (!password_verify($agent_secret, (string)$agent['agent_secret_hash'])) {
        monitoring_json(false, 'E-mail ou senha incorretos.', null, 401, 'AUTH_REQUIRED');
    }

    $fingerprint_hash = hash('sha256', $hardware_fingerprint);
    $stmt = $conexao->prepare("SELECT hardware_fingerprint_hash FROM monitoramento_agentes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $stored_hash = $stmt->get_result()->fetch_assoc()['hardware_fingerprint_hash'] ?? '';
    $stmt->close();
    if (!hash_equals((string)$stored_hash, $fingerprint_hash)) {
        monitoring_json(false, 'Identificação da máquina divergente.', null, 401, 'HARDWARE_MISMATCH');
    }

    $stmt = $conexao->prepare(
        "SELECT u.id, u.nome, u.email, u.senha, u.funcao, u.departamento, u.permissao
           FROM usuarios u
           INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id
          WHERE u.email = ? AND u.ativo = 1 AND ut.tenant_id = ? AND ut.ativo = 1
          LIMIT 1"
    );
    $tenant_id = (int)$agent['tenant_id'];
    $stmt->bind_param('si', $email, $tenant_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || !password_verify($senha, (string)($user['senha'] ?? ''))) {
        monitoring_json(false, 'E-mail ou senha incorretos.', null, 401, 'AUTH_REQUIRED');
    }

    $access_token = bin2hex(random_bytes(32));
    $token_hash = monitoring_hash_bearer($access_token);
    $user_agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'monitoring-agent'), 0, 255);
    $stmt = $conexao->prepare(
        "INSERT INTO monitoramento_sessoes
            (agente_id, token_hash, expires_at, last_seen_at, ip_address, user_agent)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR), NOW(), ?, ?)"
    );
    $ip = monitoring_ip();
    $stmt->bind_param('isss', $agent_id, $token_hash, $ip, $user_agent);
    $stmt->execute();
    $stmt->close();

    $tenant = monitoring_tenant($conexao, $tenant_id);
    $config = monitoring_config($conexao, $tenant_id);
    $stmt = $conexao->prepare("UPDATE monitoramento_agentes SET last_ip = ?, last_heartbeat_at = NOW(), last_error_code = NULL WHERE id = ?");
    $stmt->bind_param('si', $ip, $agent_id);
    $stmt->execute();
    $stmt->close();

    monitoring_log('MONITORING_LOGIN', 'Login do agente realizado. agent_id=' . $agent_id, $user['email']);
    monitoring_json(true, 'Login realizado com sucesso.', [
        'session' => ['access_token' => $access_token, 'expires_in' => 28800],
        'tenant' => $tenant,
        'agent' => [
            'id' => $agent_id,
            'nome' => $agent['nome'],
            'local' => $agent['local'],
            'status' => $agent['status'],
            'retencao_dias' => (int)$config['retencao_dias'],
        ],
        'permissions' => ['monitoramento.visualizar', 'monitoramento.lpr.configurar'],
        'user' => ['id' => (int)$user['id'], 'nome' => $user['nome'], 'email' => $user['email'], 'permissao' => $user['permissao']],
    ]);
}

function _monitoring_heartbeat($conexao, $input) {
    $agent = monitoring_agent_context($conexao, true);
    $agent_id = (int)($input['agent_id'] ?? 0);
    if ($agent_id !== (int)$agent['agente_id']) monitoring_json(false, 'Agente divergente.', null, 403, 'AGENT_SCOPE_INVALID');

    $agent_version = substr(trim((string)($input['agent_version'] ?? '')), 0, 30);
    $contract = substr(trim((string)($input['api_contract_version'] ?? '')), 0, 20);
    $engine = substr(trim((string)($input['lpr_engine'] ?? '')), 0, 40);
    $engine_version = substr(trim((string)($input['lpr_engine_version'] ?? '')), 0, 40);
    $backend = substr(trim((string)($input['onnx_backend'] ?? '')), 0, 30);
    $ip = monitoring_ip();
    $stmt = $conexao->prepare(
        "UPDATE monitoramento_agentes
            SET agent_version = ?, api_contract_version = ?, lpr_engine = ?, lpr_engine_version = ?,
                onnx_backend = ?, last_heartbeat_at = NOW(), last_ip = ?, last_error_code = NULL
          WHERE id = ? AND tenant_id = ?"
    );
    $tenant_id = (int)$agent['tenant_id'];
    $stmt->bind_param('ssssssii', $agent_version, $contract, $engine, $engine_version, $backend, $ip, $agent_id, $tenant_id);
    $stmt->execute();
    $stmt->close();

    $cameras = is_array($input['cameras'] ?? null) ? $input['cameras'] : [];
    foreach (array_slice($cameras, 0, 100) as $camera) {
        $external_key = substr(trim((string)($camera['camera_id'] ?? '')), 0, 80);
        if ($external_key === '') continue;
        $status = substr(trim((string)($camera['status'] ?? 'offline')), 0, 20);
        $last_error = substr(trim((string)($camera['last_error_code'] ?? '')), 0, 60);
        $frames_dropped = (int)($camera['frames_dropped'] ?? 0);
        $stmt = $conexao->prepare("SELECT id FROM monitoramento_cameras WHERE agente_id = ? AND external_key = ? LIMIT 1");
        $stmt->bind_param('is', $agent_id, $external_key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $camera_id = (int)$row['id'];
            $stmt = $conexao->prepare(
                "UPDATE monitoramento_cameras SET ultimo_status = ?, frames_perdidos = ?, ultimo_erro_code = ?,
                        ultimo_frame_at = NULLIF(?, ''), atualizado_em = NOW()
                  WHERE id = ? AND tenant_id = ?"
            );
            $raw_last_frame = trim((string)($camera['last_frame_at'] ?? ''));
            $last_frame = $raw_last_frame !== '' ? _monitoring_datetime($raw_last_frame) : '';
            $stmt->bind_param('sissii', $status, $frames_dropped, $last_error, $last_frame, $camera_id, $tenant_id);
        } else {
            $name = $external_key;
            $sentido = 'indeterminado';
            $stmt = $conexao->prepare(
                "INSERT INTO monitoramento_cameras
                    (tenant_id, agente_id, external_key, nome, sentido, ultimo_status, frames_perdidos, ultimo_erro_code)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iissssis', $tenant_id, $agent_id, $external_key, $name, $sentido, $status, $frames_dropped, $last_error);
        }
        $stmt->execute();
        $stmt->close();
    }

    $config = monitoring_config($conexao, $tenant_id);
    monitoring_json(true, 'Heartbeat registrado.', [
        'server_time' => gmdate('c'),
        'config_version' => (int)$config['config_version'],
        'retencao_dias' => (int)$config['retencao_dias'],
        'lpr_engine' => $config['lpr_engine'] ?? 'fastalpr',
        'onnx_backend' => $config['onnx_backend'] ?? 'cpu',
        'confidence_min' => (float)($config['confidence_min'] ?? 0.8),
        'dedup_seconds' => (int)($config['dedup_seconds'] ?? 20),
        'commands' => [],
        'update' => ['required' => false, 'minimum_agent_version' => $config['versao_minima_agente']],
    ]);
}

function _monitoring_ingestir_lote($conexao, $input) {
    $agent = monitoring_agent_context($conexao, true);
    $agent_id = (int)($input['agent_id'] ?? 0);
    if ($agent_id !== (int)$agent['agente_id']) monitoring_json(false, 'Agente divergente.', null, 403, 'AGENT_SCOPE_INVALID');
    $events = is_array($input['events'] ?? null) ? $input['events'] : [];
    if (!$events || count($events) > 100) monitoring_json(false, 'Lote de eventos inválido.', null, 422, 'EVENT_BATCH_INVALID');

    $tenant_id = (int)$agent['tenant_id'];
    $accepted = [];
    $duplicates = [];
    $rejected = [];
    $results = [];
    foreach ($events as $event) {
        $event_uuid = trim((string)($event['event_uuid'] ?? ''));
        $plate_raw = substr(trim((string)($event['plate_raw'] ?? '')), 0, 32);
        $plate = monitoring_normalize_plate($event['plate_normalized'] ?? $plate_raw);
        if (!monitoring_valid_uuid($event_uuid) || $plate === '') {
            if ($event_uuid !== '') $rejected[] = $event_uuid;
            continue;
        }
        $captured_at = _monitoring_datetime($event['captured_at'] ?? null);
        $camera_key = substr(trim((string)($event['camera_id'] ?? '')), 0, 80);
        $direction = substr(trim((string)($event['direction'] ?? 'indeterminado')), 0, 20);
        $type = substr(trim((string)($event['event_type'] ?? 'lpr')), 0, 30);
        $engine = substr(trim((string)($event['engine'] ?? 'fastalpr')), 0, 40);
        $engine_version = substr(trim((string)($event['engine_version'] ?? '')), 0, 40);
        $det_conf = _monitoring_decimal($event['detection_confidence'] ?? null);
        $ocr_conf = _monitoring_decimal($event['ocr_confidence'] ?? null);
        $snapshot_sha = substr(trim((string)($event['snapshot_sha256'] ?? '')), 0, 64);

        $camera_id = null;
        if ($camera_key !== '') {
            $stmt = $conexao->prepare("SELECT id FROM monitoramento_cameras WHERE tenant_id = ? AND agente_id = ? AND external_key = ? LIMIT 1");
            $stmt->bind_param('iis', $tenant_id, $agent_id, $camera_key);
            $stmt->execute();
            $camera_id = ($stmt->get_result()->fetch_assoc()['id'] ?? null);
            $stmt->close();
            $camera_id = $camera_id ? (int)$camera_id : null;
        }

        $vehicle = _monitoring_find_vehicle($conexao, $tenant_id, $plate);
        $match_status = $vehicle ? 'veiculo_encontrado' : 'nao_cadastrado';
        $veiculo_id = $vehicle ? (int)$vehicle['id'] : null;
        $morador_id = $vehicle ? (int)$vehicle['morador_id'] : null;
        $stmt = $conexao->prepare(
            "INSERT INTO monitoramento_eventos
                (tenant_id, agente_id, camera_id, camera_external_key, event_uuid, capturado_em,
                 placa_raw, placa_normalizada, detection_confidence, ocr_confidence, direcao,
                 tipo_evento, motor, motor_versao, match_status, veiculo_id, morador_id,
                 snapshot_sha256, status_evento)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmado')"
        );
        $stmt->bind_param('iiisssssddsssssiis', $tenant_id, $agent_id, $camera_id, $camera_key, $event_uuid, $captured_at, $plate_raw, $plate, $det_conf, $ocr_conf, $direction, $type, $engine, $engine_version, $match_status, $veiculo_id, $morador_id, $snapshot_sha);
        if ($stmt->execute()) {
            $server_id = (int)$stmt->insert_id;
            $accepted[] = $event_uuid;
            $results[] = ['event_uuid' => $event_uuid, 'server_event_id' => $server_id, 'match_status' => $match_status, 'veiculo_id' => $veiculo_id, 'morador_id' => $morador_id, 'registro_acesso_id' => null, 'notification_status' => 'not_requested'];
        } elseif ((int)$conexao->errno === 1062) {
            $duplicates[] = $event_uuid;
            $lookup = $conexao->prepare("SELECT id, match_status, veiculo_id, morador_id FROM monitoramento_eventos WHERE tenant_id = ? AND agente_id = ? AND event_uuid = ? LIMIT 1");
            $lookup->bind_param('iis', $tenant_id, $agent_id, $event_uuid);
            $lookup->execute();
            $old = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            $results[] = ['event_uuid' => $event_uuid, 'server_event_id' => $old ? (int)$old['id'] : null, 'match_status' => $old['match_status'] ?? 'duplicado', 'veiculo_id' => $old ? (int)$old['veiculo_id'] : null, 'morador_id' => $old ? (int)$old['morador_id'] : null, 'registro_acesso_id' => null, 'notification_status' => 'not_requested'];
        } else {
            $rejected[] = $event_uuid;
        }
        $stmt->close();
    }
    monitoring_json(true, 'Lote processado.', ['accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected, 'results' => $results]);
}

function _monitoring_acessos_recentes($conexao) {
    $bearer = monitoring_read_bearer();
    if ($bearer) {
        $context = monitoring_agent_context($conexao, true);
        $tenant_id = (int)$context['tenant_id'];
    } else {
        $context = monitoring_web_context('visualizador');
        $tenant_id = (int)$context['tenant_id'];
    }
    $limit = max(1, min((int)($_GET['limite'] ?? 10), 100));
    $stmt = $conexao->prepare(
        "SELECT e.id, e.event_uuid, e.capturado_em, e.placa_raw, e.placa_normalizada,
                e.detection_confidence, e.ocr_confidence, e.direcao, e.tipo_evento,
                e.motor, e.match_status, e.veiculo_id, e.morador_id, e.status_evento,
                e.camera_external_key, a.nome AS agente_nome
           FROM monitoramento_eventos e
           LEFT JOIN monitoramento_agentes a ON a.id = e.agente_id
          WHERE e.tenant_id = ? AND e.capturado_em >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
          ORDER BY e.capturado_em DESC LIMIT ?"
    );
    $stmt->bind_param('ii', $tenant_id, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    monitoring_json(true, 'Acessos recentes carregados.', ['eventos' => $rows, 'retencao_dias' => (int)monitoring_config($conexao, $tenant_id)['retencao_dias']]);
}

function _monitoring_admin_tenant($conexao, $input = []) {
    $user = obterUsuarioAutenticado();
    if (!$user) monitoring_json(false, 'Autenticação necessária.', null, 401, 'AUTH_REQUIRED');

    // O tenant operacional é sempre o tenant ativo da sessão. Mesmo o
    // super-admin deve primeiro entrar no contexto operacional da unidade;
    // parâmetros GET/POST nunca podem escolher o tenant de uma máquina.
    $context = monitoring_web_context('gerente');
    return ['user' => $user, 'tenant_id' => (int)$context['tenant_id']];
}

function _monitoring_listar_agentes($conexao) {
    $context = monitoring_web_context('visualizador');
    $tenant_id = (int)$context['tenant_id'];
    $stmt = $conexao->prepare(
        "SELECT id, tenant_id, install_id, nome, local, responsavel, observacao, status,
                agent_version, api_contract_version, lpr_engine, lpr_engine_version,
                onnx_backend, pairing_code_preview, pairing_expires_at,
                last_heartbeat_at, last_ip, last_error_code, activated_at, revoked_at, criado_em, atualizado_em
           FROM monitoramento_agentes WHERE tenant_id = ?
          ORDER BY atualizado_em DESC"
    );
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $camera_stmt = $conexao->prepare(
        "SELECT c.id, c.tenant_id, c.agente_id, c.external_key, c.nome, c.fabricante, c.modelo,
                c.origem_stream, c.sentido, c.ativo, c.ultimo_status, c.ultimo_frame_at,
                c.frames_perdidos, c.ultimo_erro_code, a.nome AS agente_nome
           FROM monitoramento_cameras c
           LEFT JOIN monitoramento_agentes a ON a.id = c.agente_id
          WHERE c.tenant_id = ? ORDER BY c.nome ASC"
    );
    $camera_stmt->bind_param('i', $tenant_id);
    $camera_stmt->execute();
    $cameras = $camera_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $camera_stmt->close();
    monitoring_json(true, 'Agentes carregados.', ['agentes' => $rows, 'cameras' => $cameras, 'configuracao' => monitoring_config($conexao, $tenant_id)]);
}

function _monitoring_habilitar_agente($conexao, $input) {
    $admin = _monitoring_admin_tenant($conexao, $input);
    $tenant_id = (int)$admin['tenant_id'];
    $agent_id = (int)($input['agent_id'] ?? $input['pairing_request_id'] ?? 0);
    $name = substr(trim((string)($input['nome'] ?? 'Agente Monitoring')), 0, 120);
    $local = substr(trim((string)($input['local'] ?? '')), 0, 160);
    $responsavel = substr(trim((string)($input['responsavel'] ?? '')), 0, 160);
    $observacao = substr(trim((string)($input['observacao'] ?? '')), 0, 5000);
    $pairing_code = strtoupper(trim((string)($input['pairing_code'] ?? '')));
    if (!monitoring_valid_pairing_code($pairing_code)) monitoring_json(false, 'Código de pareamento obrigatório.', null, 422, 'PAIRING_CODE_REQUIRED');
    if ($agent_id <= 0) {
        // A instalação ainda não possui tenant antes da habilitação e, por isso,
        // não aparece em listar_agentes. O código completo é a prova de posse
        // usada para localizar exclusivamente a solicitação pendente.
        $pairing_preview = monitoring_preview($pairing_code, 4, 4);
        $stmt = $conexao->prepare(
            "SELECT id FROM monitoramento_agentes
              WHERE pairing_code_preview = ?
                AND status IN ('pendente_ativacao', 'solicitado')
                AND pairing_expires_at > NOW()
              ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) monitoring_json(false, 'Serviço de pareamento indisponível.', null, 500, 'PAIRING_DB_ERROR');
        $stmt->bind_param('s', $pairing_preview);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $agent_id = (int)($candidate['id'] ?? 0);
        if ($agent_id <= 0) {
            monitoring_log('MONITORING_PAIRING_NOT_FOUND', 'Código de pareamento sem solicitação pendente.', $admin['user']['email'] ?? null);
            monitoring_json(false, 'Nenhuma solicitação pendente foi encontrada para este código. No computador Windows, abra o painel local do Monitoring e gere ou envie um novo código de pareamento.', null, 422, 'PAIRING_REQUEST_NOT_FOUND');
        }
    }

    $stmt = $conexao->prepare("SELECT id, tenant_id, install_id, hardware_fingerprint_hash, pairing_code_hash, pairing_expires_at, status FROM monitoramento_agentes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $agent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$agent || !in_array($agent['status'], ['pendente_ativacao', 'solicitado'], true)) monitoring_json(false, 'Solicitação de pareamento não está pendente.', null, 409, 'PAIRING_NOT_PENDING');
    if ($agent['tenant_id'] && (int)$agent['tenant_id'] !== $tenant_id) monitoring_json(false, 'Agente pertence a outro tenant.', null, 403, 'TENANT_SCOPE_INVALID');
    if (empty($agent['pairing_code_hash']) || !password_verify($pairing_code, (string)$agent['pairing_code_hash'])) monitoring_json(false, 'Código de pareamento inválido.', null, 403, 'PAIRING_CODE_INVALID');
    if (!empty($agent['pairing_expires_at']) && strtotime($agent['pairing_expires_at']) < time()) monitoring_json(false, 'Código de pareamento expirado.', null, 410, 'PAIRING_EXPIRED');

    $secret = bin2hex(random_bytes(32));
    $secret_hash = password_hash($secret, PASSWORD_DEFAULT);
    $last4 = substr($secret, -4);
    $stmt = $conexao->prepare(
        "UPDATE monitoramento_agentes
            SET tenant_id = ?, nome = ?, local = ?, responsavel = ?, observacao = ?,
                agent_secret_hash = ?, agent_secret_last4 = ?, status = 'ativo',
                activated_at = NOW(), pairing_code_hash = NULL, pairing_expires_at = NULL,
                atualizado_em = NOW()
          WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?)"
    );
    $stmt->bind_param('issssssii', $tenant_id, $name, $local, $responsavel, $observacao, $secret_hash, $last4, $agent_id, $tenant_id);
    $stmt->execute();
    $stmt->close();
    monitoring_log('MONITORING_AGENT_ACTIVATED', 'Agente habilitado. agent_id=' . $agent_id, $admin['user']['email'] ?? null);
    monitoring_json(true, 'Agente habilitado. Guarde a credencial exibida apenas agora.', [
        'agent_id' => $agent_id,
        'tenant_id' => $tenant_id,
        'activation_secret' => $secret,
        'secret_last4' => $last4,
        'status' => 'ativo',
    ]);
}

function _monitoring_revogar_agente($conexao, $input) {
    $admin = _monitoring_admin_tenant($conexao, $input);
    $tenant_id = (int)$admin['tenant_id'];
    $agent_id = (int)($input['agent_id'] ?? 0);
    if ($agent_id <= 0) monitoring_json(false, 'Agente obrigatório.', null, 422, 'AGENT_REQUIRED');
    $stmt = $conexao->prepare("UPDATE monitoramento_agentes SET status = 'revogado', revoked_at = NOW(), atualizado_em = NOW() WHERE id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $agent_id, $tenant_id);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    $stmt = $conexao->prepare("UPDATE monitoramento_sessoes SET revoked_at = NOW() WHERE agente_id = ? AND revoked_at IS NULL");
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $stmt->close();
    if (!$changed) monitoring_json(false, 'Agente não encontrado neste tenant.', null, 404, 'AGENT_NOT_FOUND');
    monitoring_log('MONITORING_AGENT_REVOKED', 'Agente revogado. agent_id=' . $agent_id, $admin['user']['email'] ?? null);
    monitoring_json(true, 'Agente revogado.', ['agent_id' => $agent_id, 'status' => 'revogado']);
}

function _monitoring_configuracao($conexao) {
    $context = monitoring_web_context('visualizador');
    monitoring_json(true, 'Configuração carregada.', monitoring_config($conexao, (int)$context['tenant_id']));
}

function _monitoring_salvar_configuracao($conexao, $input) {
    $admin = _monitoring_admin_tenant($conexao, $input);
    $tenant_id = (int)$admin['tenant_id'];
    $retention = max(1, min((int)($input['retencao_dias'] ?? 30), 3650));
    $module_active = !empty($input['modulo_ativo']) ? 1 : 0;
    $engine = in_array(($input['lpr_engine'] ?? 'fastalpr'), ['fastalpr', 'frigate'], true) ? (string)$input['lpr_engine'] : 'fastalpr';
    $backend = in_array(($input['onnx_backend'] ?? 'cpu'), ['cpu', 'openvino', 'directml'], true) ? (string)$input['onnx_backend'] : 'cpu';
    $confidence = _monitoring_decimal($input['confidence_min'] ?? 0.8) ?? 0.8;
    $dedup = max(1, min((int)($input['dedup_seconds'] ?? 20), 300));
    $min_version = substr(trim((string)($input['versao_minima_agente'] ?? '0.1.0')), 0, 30);
    $stmt = $conexao->prepare(
        "INSERT INTO monitoramento_configuracoes
            (tenant_id, modulo_ativo, retencao_dias, lpr_engine, onnx_backend, confidence_min, dedup_seconds, versao_minima_agente, config_version)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE modulo_ativo = VALUES(modulo_ativo), retencao_dias = VALUES(retencao_dias),
             lpr_engine = VALUES(lpr_engine), onnx_backend = VALUES(onnx_backend), confidence_min = VALUES(confidence_min),
             dedup_seconds = VALUES(dedup_seconds), versao_minima_agente = VALUES(versao_minima_agente), config_version = config_version + 1"
    );
    $stmt->bind_param('iiissdis', $tenant_id, $module_active, $retention, $engine, $backend, $confidence, $dedup, $min_version);
    $stmt->execute();
    $stmt->close();
    monitoring_log('MONITORING_CONFIG_SAVED', 'Configuração de retenção atualizada. tenant_id=' . $tenant_id, $admin['user']['email'] ?? null);
    monitoring_json(true, 'Configuração salva.', monitoring_config($conexao, $tenant_id));
}

function _monitoring_find_vehicle($conexao, $tenant_id, $plate) {
    $stmt = $conexao->prepare(
        "SELECT v.id, v.morador_id, v.modelo, v.cor
           FROM veiculos v
          WHERE v.tenant_id = ? AND v.ativo = 1
            AND REPLACE(REPLACE(UPPER(v.placa), '-', ''), ' ', '') = ?
          LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('is', $tenant_id, $plate);
    $stmt->execute();
    $vehicle = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $vehicle ?: null;
}

function _monitoring_datetime($value) {
    $timestamp = strtotime((string)$value);
    if ($timestamp === false) return gmdate('Y-m-d H:i:s');
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function _monitoring_decimal($value) {
    if ($value === null || $value === '') return null;
    $value = is_numeric($value) ? (float)$value : null;
    if ($value === null) return null;
    return max(0, min(1, round($value, 4)));
}
