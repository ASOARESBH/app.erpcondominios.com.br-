<?php
/**
 * Helpers do domínio ERP CONDOMÍNIOS MONITORING.
 * Não contém credenciais e não acessa tenant arbitrário enviado pelo cliente.
 */

function monitoring_json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function monitoring_json($sucesso, $mensagem = '', $dados = null, $status = 200, $codigo = null) {
    http_response_code((int)$status);
    $response = ['sucesso' => (bool)$sucesso, 'mensagem' => $mensagem];
    if ($codigo !== null) $response['codigo'] = $codigo;
    if ($dados !== null) $response['dados'] = $dados;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function monitoring_require_method($method) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        monitoring_json(false, 'Método não permitido.', null, 405, 'METHOD_NOT_ALLOWED');
    }
}

function monitoring_read_bearer() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function monitoring_hash_bearer($token) {
    return hash('sha256', (string)$token);
}

function monitoring_server_secret() {
    if (defined('ERP_MONITORING_SECRET') && ERP_MONITORING_SECRET !== '') {
        return (string)ERP_MONITORING_SECRET;
    }
    // Compatibilidade temporária de laboratório. Defina ERP_MONITORING_SECRET
    // no erp_config.php antes de liberar o produto em produção.
    error_log('[MONITORING] ERP_MONITORING_SECRET ausente; usando fallback temporário.');
    return hash('sha256', (string)DB_PASS . '|erp-condominios-monitoring');
}

function monitoring_derive_agent_secret($agent_id, $install_id, $pairing_code) {
    $material = 'agent|' . (int)$agent_id . '|' . (string)$install_id . '|' . strtoupper(trim((string)$pairing_code));
    return hash_hmac('sha256', $material, monitoring_server_secret());
}

function monitoring_preview($value, $start = 6, $end = 4) {
    $value = (string)$value;
    if ($value === '') return null;
    if (strlen($value) <= ($start + $end)) return str_repeat('*', strlen($value));
    return substr($value, 0, $start) . '…' . substr($value, -$end);
}

function monitoring_normalize_plate($value) {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value);
    return substr((string)$value, 0, 16);
}

function monitoring_valid_uuid($value) {
    return (bool)preg_match('/^[0-9a-fA-F-]{20,64}$/', (string)$value);
}

function monitoring_valid_install_id($value) {
    return (bool)preg_match('/^[0-9a-fA-F-]{20,64}$/', (string)$value);
}

function monitoring_valid_pairing_code($value) {
    return (bool)preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', strtoupper(trim((string)$value)));
}

function monitoring_sanitize_error($value) {
    $value = preg_replace('/(?i)(senha|password|token|secret|authorization)\s*[:=]\s*[^\s,;]+/', '$1=***', (string)$value);
    return substr((string)$value, 0, 255);
}

function monitoring_ip() {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function monitoring_agent_context($conexao, $required = true) {
    $token = monitoring_read_bearer();
    if (!$token) {
        if ($required) monitoring_json(false, 'Credencial do agente ausente.', null, 401, 'AGENT_AUTH_REQUIRED');
        return null;
    }

    $token_hash = monitoring_hash_bearer($token);
    $stmt = $conexao->prepare(
        "SELECT s.id AS sessao_id, s.expires_at, a.id AS agente_id, a.tenant_id,
                a.install_id, a.nome, a.local, a.status, a.agent_version,
                a.api_contract_version, a.lpr_engine, a.lpr_engine_version,
                a.hardware_fingerprint_hash, a.last_ip
           FROM monitoramento_sessoes s
         INNER JOIN monitoramento_agentes a ON a.id = s.agente_id
         WHERE s.token_hash = ? AND s.revoked_at IS NULL
           AND s.expires_at > NOW()
         LIMIT 1"
    );
    if (!$stmt) {
        if ($required) monitoring_json(false, 'Serviço de agentes indisponível.', null, 500, 'MONITORING_DB_ERROR');
        return null;
    }
    $stmt->bind_param('s', $token_hash);
    $stmt->execute();
    $context = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$context || empty($context['tenant_id'])) {
        if ($required) monitoring_json(false, 'Credencial do agente inválida ou expirada.', null, 401, 'AGENT_AUTH_INVALID');
        return null;
    }
    if ($context['status'] !== 'ativo') {
        $code = $context['status'] === 'revogado' ? 'AGENT_REVOKED' : 'AGENT_BLOCKED';
        if ($required) monitoring_json(false, 'Agente não autorizado para comunicação.', null, 401, $code);
        return null;
    }

    $stmt = $conexao->prepare("UPDATE monitoramento_sessoes SET last_seen_at = NOW() WHERE token_hash = ?");
    if ($stmt) {
        $stmt->bind_param('s', $token_hash);
        $stmt->execute();
        $stmt->close();
    }
    return $context;
}

function monitoring_web_context($permissao = 'gerente') {
    $context = verificarAutenticacao(true, $permissao);
    if (!$context || empty($context['tenant_id'])) {
        monitoring_json(false, 'Contexto de tenant não identificado.', null, 403, 'TENANT_REQUIRED');
    }
    return $context;
}

function monitoring_tenant($conexao, $tenant_id) {
    $stmt = $conexao->prepare(
        "SELECT id, slug, razao_social, nome_fantasia, plano, status,
                logo_url, modulos_habilitados
         FROM tenants WHERE id = ? AND status = 'ativo' LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $tenant ?: null;
}

function monitoring_config($conexao, $tenant_id) {
    $stmt = $conexao->prepare(
        "SELECT retencao_dias, lpr_engine, onnx_backend, confidence_min, dedup_seconds,
                versao_minima_agente, config_version, modulo_ativo
         FROM monitoramento_configuracoes WHERE tenant_id = ? LIMIT 1"
    );
    if (!$stmt) return ['retencao_dias' => 30, 'lpr_engine' => 'fastalpr', 'onnx_backend' => 'cpu', 'confidence_min' => 0.8, 'dedup_seconds' => 20, 'versao_minima_agente' => '0.1.0', 'config_version' => 1, 'modulo_ativo' => 1];
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $config ?: ['retencao_dias' => 30, 'lpr_engine' => 'fastalpr', 'onnx_backend' => 'cpu', 'confidence_min' => 0.8, 'dedup_seconds' => 20, 'versao_minima_agente' => '0.1.0', 'config_version' => 1, 'modulo_ativo' => 1];
}

function monitoring_log($tipo, $descricao, $usuario = null) {
    $descricao = monitoring_sanitize_error($descricao);
    if (function_exists('registrar_log')) {
        registrar_log($tipo, $descricao, $usuario);
    } else {
        error_log('[MONITORING][' . $tipo . '] ' . $descricao);
    }
}
