<?php
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/visitantes_config_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function config_visitantes_responder($sucesso, $mensagem, $dados = null, $status = 200) {
    http_response_code($status);
    $saida = ['sucesso' => (bool)$sucesso, 'mensagem' => $mensagem];
    if ($dados !== null) $saida['dados'] = $dados;
    echo json_encode($saida, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$auth = verificarAutenticacao(true);
$tenant_id = exigirTenantId();
$conexao = conectar_banco();
$metodo = $_SERVER['REQUEST_METHOD'];

// No RBAC, somente quem possui Sistema > Configurar pode alterar a regra.
// Sem a migration RBAC, preserva-se a exigência administrativa legada.
$rbacAtivo = rbacTabelasDisponiveis($conexao);
if ($metodo === 'GET') {
    if ($rbacAtivo) rbacExigir($conexao, 'sistema', 'visualizar');
    else verificarPermissao('admin');
} else {
    if ($rbacAtivo) rbacExigir($conexao, 'sistema', 'configurar');
    else verificarPermissao('admin');
}

if ($metodo === 'GET') {
    $campos = visitantes_obter_config_campos($conexao, (int)$tenant_id);
    config_visitantes_responder(true, 'Configuração de campos carregada.', [
        'campos' => array_values($campos),
        'modo_compatibilidade' => !visitantes_tabela_config_existe($conexao),
        'pode_configurar' => $rbacAtivo ? rbacPode($conexao, 'sistema', 'configurar') : true,
    ]);
}

if ($metodo !== 'PUT') config_visitantes_responder(false, 'Método não permitido.', null, 405);
if (!visitantes_tabela_config_existe($conexao)) {
    config_visitantes_responder(false, 'A estrutura de configuração ainda não foi instalada. Execute a migration de campos de visitantes.', null, 409);
}

$entrada = json_decode(file_get_contents('php://input'), true);
$recebidos = is_array($entrada['campos'] ?? null) ? $entrada['campos'] : null;
if ($recebidos === null) config_visitantes_responder(false, 'Informe a lista de campos para configuração.', null, 400);

$catalogo = visitantes_catalogo_campos();
$normalizados = [];
foreach ($recebidos as $item) {
    $campo = (string)($item['campo'] ?? '');
    if (!isset($catalogo[$campo])) {
        config_visitantes_responder(false, 'Campo de visitante não reconhecido: ' . $campo, null, 400);
    }
    $normalizados[$campo] = !empty($item['obrigatorio']) ? 1 : 0;
}

// Campos omitidos mantêm sua configuração atual. O navegador não pode zerar
// silenciosamente campos que não fazem parte da tela carregada.
$atuais = visitantes_obter_config_campos($conexao, (int)$tenant_id);
$usuario_id = (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0);
$conexao->begin_transaction();
try {
    $stmt = $conexao->prepare(
        'INSERT INTO config_visitantes_campos (tenant_id,campo,obrigatorio,atualizado_por_usuario_id) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE obrigatorio=VALUES(obrigatorio), atualizado_por_usuario_id=VALUES(atualizado_por_usuario_id), atualizado_em=NOW()'
    );
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a atualização dos campos.');

    foreach ($normalizados as $campo => $obrigatorio) {
        $stmt->bind_param('isii', $tenant_id, $campo, $obrigatorio, $usuario_id);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    $conexao->commit();

    if ($rbacAtivo) {
        rbacAuditar($conexao, [
            'modulo_chave' => 'sistema',
            'submodulo_chave' => 'visitantes',
            'acao' => 'CONFIGURAR_CAMPOS_OBRIGATORIOS',
            'registro_tipo' => 'config_visitantes_campos',
            'dados_antes' => $atuais,
            'dados_depois' => visitantes_obter_config_campos($conexao, (int)$tenant_id),
            'resultado' => 'SUCESSO',
            'status_http' => 200,
        ]);
    }
    registrar_log('CONFIG_VISITANTES_CAMPOS', 'Campos obrigatórios de visitantes atualizados para o tenant ' . (int)$tenant_id, $_SESSION['usuario_nome'] ?? 'Sistema');
    config_visitantes_responder(true, 'Configuração de campos obrigatórios atualizada.', ['campos' => array_values(visitantes_obter_config_campos($conexao, (int)$tenant_id))]);
} catch (Throwable $e) {
    $conexao->rollback();
    error_log('[ConfigVisitantes] tenant=' . (int)$tenant_id . ' erro=' . $e->getMessage());
    config_visitantes_responder(false, 'Não foi possível salvar a configuração de campos.', null, 500);
}
?>
