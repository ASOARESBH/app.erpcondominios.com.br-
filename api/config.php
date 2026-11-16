<?php
/**
 * Configuração central da API.
 *
 * Credenciais não pertencem ao document root nem ao Git. Em produção, crie
 * erp_config.php fora de public_html conforme o modelo em config/.
 */

if (!function_exists('erp_responder_configuracao_indisponivel')) {
    function erp_responder_configuracao_indisponivel() {
        $driver = defined('ERP_DB_DRIVER') ? strtolower(trim((string)ERP_DB_DRIVER)) : 'mysql';
        http_response_code($driver === 'pgsql' ? 503 : 500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Configuração de serviço indisponível. Tente novamente mais tarde.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require_once __DIR__ . '/db/PrivateConfigPath.php';
require_once __DIR__ . '/db/DatabaseDriverSelector.php';
if (!class_exists(\ERP\Database\DatabaseTimezone::class, false)) {
    require_once __DIR__ . '/db/DatabaseTimezone.php';
}
$erpConfigPath = \ERP\Database\PrivateConfigPath::resolve();
$erpConfigLoaded = false;
if (is_string($erpConfigPath)) {
    $configOutputDetected = false;
    $configIncludeFailed = false;
    ob_start(static function ($output) use (&$configOutputDetected) {
        if ($output !== '') {
            $configOutputDetected = true;
        }
        return '';
    });
    try {
        require_once $erpConfigPath;
    } catch (Throwable) {
        $configIncludeFailed = true;
    } finally {
        ob_end_flush();
    }
    $erpConfigLoaded = !$configIncludeFailed && !$configOutputDetected;
    if (!$erpConfigLoaded) {
        error_log('[ERP_CONFIG] Configuração externa indisponível.');
    }
}

$erpDatabaseDriverValue = defined('ERP_DB_DRIVER') ? ERP_DB_DRIVER : getenv('ERP_DB_DRIVER');
if ($erpDatabaseDriverValue === false) {
    $erpDatabaseDriverValue = null;
}
try {
    $erpDatabaseDriver = \ERP\Database\DatabaseDriverSelector::resolve($erpDatabaseDriverValue);
} catch (Throwable) {
    error_log('[ERP_CONFIG] Driver de banco inválido.');
    erp_responder_configuracao_indisponivel();
}
if (!defined('ERP_DB_DRIVER')) {
    define('ERP_DB_DRIVER', $erpDatabaseDriver);
}
if ($erpDatabaseDriver === 'pgsql'
    && (!class_exists(\ERP\Database\MysqliDropInBootstrap::class, false)
        || !\ERP\Database\MysqliDropInBootstrap::installed())) {
    error_log('[ERP_CONFIG] Bootstrap PostgreSQL indisponível.');
    erp_responder_configuracao_indisponivel();
}

$erpRequiredConstants = $erpDatabaseDriver === 'pgsql'
    ? ['ERP_PG_HOST', 'ERP_PG_NAME', 'ERP_PG_USER', 'ERP_PG_PASS', 'ERP_PG_SCHEMA']
    : ['ERP_DB_HOST', 'ERP_DB_NAME', 'ERP_DB_USER', 'ERP_DB_PASS'];
foreach ($erpRequiredConstants as $erpConstant) {
    if (!$erpConfigLoaded || !defined($erpConstant) || !is_string(constant($erpConstant)) || constant($erpConstant) === '') {
        error_log('[ERP_CONFIG] Configuração externa ausente ou incompleta.');
        erp_responder_configuracao_indisponivel();
    }
}

if ($erpDatabaseDriver === 'pgsql') {
    $trustedTenantDomains = defined('ERP_TRUSTED_TENANT_DOMAINS') ? ERP_TRUSTED_TENANT_DOMAINS : null;
    if (!is_array($trustedTenantDomains) || !array_is_list($trustedTenantDomains) || $trustedTenantDomains === []) {
        error_log('[ERP_CONFIG] Domínios confiáveis do tenant indisponíveis.');
        erp_responder_configuracao_indisponivel();
    }
    foreach ($trustedTenantDomains as $trustedTenantDomain) {
        if (!is_string($trustedTenantDomain)
            || filter_var($trustedTenantDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            error_log('[ERP_CONFIG] Domínios confiáveis do tenant inválidos.');
            erp_responder_configuracao_indisponivel();
        }
    }
    $erpAliases = [
        'DB_HOST' => ERP_PG_HOST,
        'DB_NAME' => ERP_PG_NAME,
        'DB_USER' => ERP_PG_USER,
        'DB_PASS' => ERP_PG_PASS,
        'DB_CHARSET' => 'utf8mb4',
    ];
} else {
    $erpAliases = [
        'DB_HOST' => ERP_DB_HOST,
        'DB_NAME' => ERP_DB_NAME,
        'DB_USER' => ERP_DB_USER,
        'DB_PASS' => ERP_DB_PASS,
        'DB_CHARSET' => defined('ERP_DB_CHARSET') ? ERP_DB_CHARSET : 'utf8mb4',
    ];
}
foreach ($erpAliases as $alias => $value) {
    if (defined($alias)) {
        if (!is_string(constant($alias)) || constant($alias) !== $value) {
            error_log('[ERP_CONFIG] Alias de banco divergente do driver selecionado.');
            erp_responder_configuracao_indisponivel();
        }
        continue;
    }
    define($alias, $value);
}

try {
    $erpTimezone = \ERP\Database\DatabaseTimezone::resolve(defined('ERP_TIMEZONE') ? constant('ERP_TIMEZONE') : 'America/Sao_Paulo');
} catch (Throwable) {
    error_log('[ERP_CONFIG] Fuso horário inválido.');
    erp_responder_configuracao_indisponivel();
}
if (!@date_default_timezone_set($erpTimezone)) {
    error_log('[ERP_CONFIG] Fuso horário indisponível.');
    erp_responder_configuracao_indisponivel();
}
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function erp_conectar_com_configuracao_selecionada() {
    mysqli_report(MYSQLI_REPORT_OFF);
    if (ERP_DB_DRIVER === 'pgsql') {
        $port = defined('ERP_PG_PORT') ? (int)ERP_PG_PORT : 5432;
        return @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
    }
    return @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
}

function erp_charset_configurado() {
    return ERP_DB_DRIVER === 'pgsql' ? 'utf8mb4' : DB_CHARSET;
}

function conectar_banco() {
    $conexao = erp_conectar_com_configuracao_selecionada();

    if ($conexao->connect_errno) {
        error_log('[ERP_DB] Falha de conexão. Código=' . (int)$conexao->connect_errno);
        erp_responder_configuracao_indisponivel();
    }

    if (!$conexao->set_charset(erp_charset_configurado())) {
        error_log('[ERP_DB] Não foi possível configurar o charset.');
        $conexao->close();
        erp_responder_configuracao_indisponivel();
    }

    if (ERP_DB_DRIVER === 'pgsql') {
        $timezone = \ERP\Database\DatabaseTimezone::resolve(defined('ERP_TIMEZONE') ? constant('ERP_TIMEZONE') : 'America/Sao_Paulo');
        if ($conexao->query("SET TIME ZONE '" . $timezone . "'") !== true) {
            error_log('[ERP_DB] Não foi possível configurar o fuso horário.');
            $conexao->close();
            erp_responder_configuracao_indisponivel();
        }
    } else {
        $conexao->query("SET time_zone = '-03:00'");
    }
    return $conexao;
}

function fechar_conexao($conexao) {
    if ($conexao instanceof mysqli) {
        $conexao->close();
    }
}

if (!function_exists('sanitizar')) {
    function sanitizar($conexao, $valor) {
        return $conexao->real_escape_string(trim((string)$valor));
    }
}

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = ['sucesso' => (bool)$sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) {
            $resposta['dados'] = $dados;
        }
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function erp_registrar_log_em_conexao($conexao, $tipo, $descricao, $usuario = null, $incluirIp = true): bool {
    if ($conexao->connect_errno) {
        error_log('[ERP_LOG] Falha ao abrir conexão de auditoria.');
        return false;
    }

    $conexao->set_charset(erp_charset_configurado());
    $ip = $incluirIp ? ($_SERVER['REMOTE_ADDR'] ?? 'desconhecido') : null;
    $stmt = $conexao->prepare('INSERT INTO logs_sistema (tipo, descricao, usuario, ip) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        error_log('[ERP_LOG] Falha ao preparar o registro de auditoria.');
        return false;
    }

    $stmt->bind_param('ssss', $tipo, $descricao, $usuario, $ip);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('[ERP_LOG] Falha ao registrar auditoria.');
    }
    $stmt->close();
    return $ok;
}

function registrar_log($tipo, $descricao, $usuario = null, $incluirIp = true) {
    $conexao = erp_conectar_com_configuracao_selecionada();
    $ok = erp_registrar_log_em_conexao($conexao, $tipo, $descricao, $usuario, $incluirIp);
    $conexao->close();
    return $ok;
}
