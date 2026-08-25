<?php
/**
 * API — Modelos de Alerta e Histórico de Envios (por condomínio)
 *
 * A configuração de SMTP/remetente deixou de existir aqui — é global e vive
 * exclusivamente no Painel Super-Admin (api_superadmin.php, ações
 * email_config_*). Este arquivo cuida só do que é legitimamente por tenant:
 * quais dos alertas automáticos este condomínio quer receber, e o histórico
 * de e-mails já enviados PARA ELE.
 *
 * Ações disponíveis:
 *   GET  ?acao=alertas_listar[&modulo=xxx]
 *   POST acao=alerta_salvar
 *   POST acao=alerta_toggle   (ativar/desativar)
 *   GET  ?acao=log_listar[&pagina=N&tipo=xxx&status=xxx]
 *   POST acao=log_limpar
 *   GET  ?acao=dashboard_stats
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
require_once __DIR__ . '/helpers/email_config_schema.php';

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Autenticação
verificarAutenticacao();

$conexao = conectar_banco();
$tenant_id = exigirTenantId();
if (!$conexao) retornar_json(false, 'Erro ao conectar ao banco de dados');
mysqli_set_charset($conexao, 'utf8mb4');

email_config_garantir_tabelas($conexao);
email_config_seed_alertas_padrao($conexao, (int) $tenant_id);

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

// Ações de escrita exigem, no mínimo, perfil gerente — a leitura (ver
// modelos e histórico) fica liberada para qualquer usuário autenticado do
// próprio tenant, igual às demais telas informativas do sistema.
$acoesDeEscrita = ['alerta_salvar', 'alerta_toggle', 'log_limpar'];
if (in_array($acao, $acoesDeEscrita, true)) {
    verificarPermissao('gerente');
}

switch ($acao) {
    case 'alertas_listar':   _alertas_listar($conexao, (int) $tenant_id);   break;
    case 'alerta_salvar':    _alerta_salvar($conexao, (int) $tenant_id);    break;
    case 'alerta_toggle':    _alerta_toggle($conexao, (int) $tenant_id);    break;
    case 'log_listar':       _log_listar($conexao, (int) $tenant_id);       break;
    case 'log_limpar':       _log_limpar($conexao, (int) $tenant_id);       break;
    case 'dashboard_stats':  _dashboard_stats($conexao, (int) $tenant_id);  break;
    default:
        retornar_json(false, "Ação '$acao' não reconhecida.");
}

// ============================================================
// ALERTAS — LISTAR
// ============================================================
function _alertas_listar($db, int $tenantId) {
    $modulo = $_GET['modulo'] ?? '';
    $where  = "WHERE tenant_id=$tenantId" . ($modulo ? " AND modulo='" . mysqli_real_escape_string($db, $modulo) . "'" : '');
    $res    = mysqli_query($db, "SELECT * FROM email_alertas $where ORDER BY modulo, nome");
    $lista  = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $r['variaveis'] = json_decode($r['variaveis'] ?? '[]', true) ?: [];
        $lista[] = $r;
    }

    // Agrupar por módulo
    $grupos = [];
    foreach ($lista as $a) {
        $grupos[$a['modulo']][] = $a;
    }

    retornar_json(true, 'OK', ['alertas' => $lista, 'grupos' => $grupos, 'total' => count($lista)]);
}

// ============================================================
// ALERTA — SALVAR
// ============================================================
function _alerta_salvar($db, int $tenantId) {
    $id      = (int)($_POST['id'] ?? 0);
    $assunto = mysqli_real_escape_string($db, $_POST['assunto']  ?? '');
    $corpo   = mysqli_real_escape_string($db, $_POST['corpo_html'] ?? '');
    $dest_tipo  = in_array($_POST['destinatario_tipo'] ?? '', ['morador','admin','email_fixo','todos_admins'])
                  ? $_POST['destinatario_tipo'] : 'admin';
    $dest_email = mysqli_real_escape_string($db, $_POST['destinatario_email'] ?? '');
    $cc         = mysqli_real_escape_string($db, $_POST['cc_emails'] ?? '');

    if ($id <= 0) retornar_json(false, 'ID do alerta inválido.');

    $sql = "UPDATE email_alertas SET
        assunto='$assunto', corpo_html='$corpo',
        destinatario_tipo='$dest_tipo', destinatario_email='$dest_email',
        cc_emails='$cc' WHERE tenant_id=$tenantId AND id=$id";

    if (mysqli_query($db, $sql)) {
        retornar_json(true, 'Alerta salvo com sucesso!');
    } else {
        retornar_json(false, 'Erro ao salvar: ' . mysqli_error($db));
    }
}

// ============================================================
// ALERTA — TOGGLE ATIVO/INATIVO
// ============================================================
function _alerta_toggle($db, int $tenantId) {
    $id    = (int)($_POST['id'] ?? 0);
    $ativo = (int)($_POST['ativo'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido.');

    if (mysqli_query($db, "UPDATE email_alertas SET ativo=$ativo WHERE tenant_id=$tenantId AND id=$id")) {
        $msg = $ativo ? 'Alerta ativado com sucesso!' : 'Alerta desativado.';
        retornar_json(true, $msg, ['ativo' => $ativo]);
    } else {
        retornar_json(false, 'Erro: ' . mysqli_error($db));
    }
}

// ============================================================
// LOG — LISTAR
// ============================================================
function _log_listar($db, int $tenantId) {
    $pagina  = max(1, (int)($_GET['pagina'] ?? 1));
    $limite  = 50;
    $offset  = ($pagina - 1) * $limite;
    $tipo    = $_GET['tipo']   ?? '';
    $status  = $_GET['status'] ?? '';
    $busca   = $_GET['busca']  ?? '';

    $where = ["tenant_id=$tenantId"];
    if ($tipo)   $where[] = "tipo='"   . mysqli_real_escape_string($db, $tipo)   . "'";
    if ($status) $where[] = "status='" . mysqli_real_escape_string($db, $status) . "'";
    if ($busca)  $where[] = "(destinatario LIKE '%" . mysqli_real_escape_string($db, $busca) . "%' OR assunto LIKE '%" . mysqli_real_escape_string($db, $busca) . "%')";
    $w = implode(' AND ', $where);

    $total = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) as t FROM email_log WHERE $w"))['t'];
    $res   = mysqli_query($db, "SELECT * FROM email_log WHERE $w ORDER BY data_envio DESC LIMIT $limite OFFSET $offset");
    $lista = [];
    while ($r = mysqli_fetch_assoc($res)) $lista[] = $r;

    retornar_json(true, 'OK', [
        'logs'          => $lista,
        'total'         => (int)$total,
        'pagina_atual'  => $pagina,
        'total_paginas' => max(1, ceil($total / $limite)),
    ]);
}

// ============================================================
// LOG — LIMPAR
// ============================================================
function _log_limpar($db, int $tenantId) {
    $dias = (int)($_POST['dias'] ?? 30);
    if ($dias < 1) $dias = 30;
    mysqli_query($db, "DELETE FROM email_log WHERE tenant_id=$tenantId AND data_envio < DATE_SUB(NOW(), INTERVAL $dias DAY)");
    $afetados = mysqli_affected_rows($db);
    retornar_json(true, "$afetados registro(s) removido(s).", ['removidos' => $afetados]);
}

// ============================================================
// DASHBOARD — ESTATÍSTICAS DE ENVIO DESTE TENANT
// ============================================================
function _dashboard_stats($db, int $tenantId) {
    $hoje = date('Y-m-d');

    $resEnviados = mysqli_query($db,
        "SELECT COUNT(*) AS total FROM email_log WHERE tenant_id=$tenantId AND DATE(data_envio) = '$hoje' AND status = 'enviado'"
    );
    $enviados = $resEnviados ? (int) mysqli_fetch_assoc($resEnviados)['total'] : 0;

    $resFalhas = mysqli_query($db,
        "SELECT COUNT(*) AS total FROM email_log WHERE tenant_id=$tenantId AND DATE(data_envio) = '$hoje' AND status != 'enviado'"
    );
    $falhas = $resFalhas ? (int) mysqli_fetch_assoc($resFalhas)['total'] : 0;

    $total = $enviados + $falhas;
    $taxa  = $total > 0 ? round(($enviados / $total) * 100, 1) : null;

    $resUltimoEnvio = mysqli_query($db,
        "SELECT data_envio, status FROM email_log WHERE tenant_id=$tenantId ORDER BY id DESC LIMIT 1"
    );
    $ultimoEnvio = null;
    if ($resUltimoEnvio && mysqli_num_rows($resUltimoEnvio) > 0) {
        $ultimoEnvio = mysqli_fetch_assoc($resUltimoEnvio)['data_envio'];
    }

    // Contagem de alertas ativos deste tenant, para dar contexto na tela.
    $resAtivos = mysqli_query($db, "SELECT COUNT(*) AS total FROM email_alertas WHERE tenant_id=$tenantId AND ativo=1");
    $alertasAtivos = $resAtivos ? (int) mysqli_fetch_assoc($resAtivos)['total'] : 0;

    retornar_json(true, 'OK', [
        'alertas_ativos'  => $alertasAtivos,
        'ultimo_envio'    => $ultimoEnvio,
        'enviados_hoje'   => $enviados,
        'falhas_hoje'     => $falhas,
        'taxa_entrega'    => $taxa,
    ]);
}
