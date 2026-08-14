<?php
/**
 * Configuração para scripts PHP executados a partir da raiz pública.
 *
 * Não armazene credenciais neste arquivo. Use ERP_CONFIG_PATH ou crie o
 * arquivo externo /home/USUARIO/erpcondominios-private/erp_config.php.
 */

if (!function_exists('erp_responder_configuracao_indisponivel')) {
    function erp_responder_configuracao_indisponivel() {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Configuração de serviço indisponível. Tente novamente mais tarde.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$erpConfigCandidates = [];
$erpConfigEnv = getenv('ERP_CONFIG_PATH');
if (is_string($erpConfigEnv) && trim($erpConfigEnv) !== '') {
    $erpConfigCandidates[] = trim($erpConfigEnv);
}
$erpConfigCandidates[] = dirname(__DIR__) . '/erpcondominios-private/erp_config.php';

$erpConfigLoaded = false;
foreach (array_unique($erpConfigCandidates) as $erpConfigPath) {
    if (is_file($erpConfigPath) && is_readable($erpConfigPath)) {
        require_once $erpConfigPath;
        $erpConfigLoaded = true;
        break;
    }
}

$erpRequiredConstants = ['ERP_DB_HOST', 'ERP_DB_NAME', 'ERP_DB_USER', 'ERP_DB_PASS'];
foreach ($erpRequiredConstants as $erpConstant) {
    if (!$erpConfigLoaded || !defined($erpConstant) || (string)constant($erpConstant) === '') {
        error_log('[ERP_CONFIG] Configuração externa ausente ou incompleta.');
        erp_responder_configuracao_indisponivel();
    }
}

if (!defined('DB_HOST')) define('DB_HOST', ERP_DB_HOST);
if (!defined('DB_NAME')) define('DB_NAME', ERP_DB_NAME);
if (!defined('DB_USER')) define('DB_USER', ERP_DB_USER);
if (!defined('DB_PASS')) define('DB_PASS', ERP_DB_PASS);
if (!defined('DB_CHARSET')) define('DB_CHARSET', defined('ERP_DB_CHARSET') ? ERP_DB_CHARSET : 'utf8mb4');

date_default_timezone_set(defined('ERP_TIMEZONE') ? ERP_TIMEZONE : 'America/Sao_Paulo');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function conectar_banco() {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conexao = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conexao->connect_errno) {
        error_log('[ERP_DB] Falha de conexão. Código=' . (int)$conexao->connect_errno);
        erp_responder_configuracao_indisponivel();
    }
    if (!$conexao->set_charset(DB_CHARSET)) {
        error_log('[ERP_DB] Não foi possível configurar o charset.');
        $conexao->close();
        erp_responder_configuracao_indisponivel();
    }
    $conexao->query("SET time_zone = '-03:00'");
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

function registrar_log($tipo, $descricao, $usuario = null) {
    $conexao = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conexao->connect_errno) {
        error_log('[ERP_LOG] Falha ao abrir conexão de auditoria.');
        return false;
    }

    $conexao->set_charset(DB_CHARSET);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    $stmt = $conexao->prepare('INSERT INTO logs_sistema (tipo, descricao, usuario, ip) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        error_log('[ERP_LOG] Falha ao preparar o registro de auditoria.');
        $conexao->close();
        return false;
    }

    $stmt->bind_param('ssss', $tipo, $descricao, $usuario, $ip);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('[ERP_LOG] Falha ao registrar auditoria.');
    }
    $stmt->close();
    $conexao->close();
    return $ok;
}
