<?php
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/moradores_config_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function config_moradores_responder($sucesso, $mensagem, $dados = null, $status = 200) {
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
if (!$conexao) config_moradores_responder(false, 'Não foi possível conectar ao banco de dados.', null, 500);
$metodo = $_SERVER['REQUEST_METHOD'];
$rbacAtivo = rbacTabelasDisponiveis($conexao);

if ($metodo === 'GET') {
    if ($rbacAtivo) rbacExigir($conexao, 'sistema', 'visualizar');
    else verificarPermissao('admin');
} else {
    if ($rbacAtivo) rbacExigir($conexao, 'sistema', 'configurar');
    else verificarPermissao('admin');
}

if ($metodo === 'GET') {
    $configuracao = moradores_obter_configuracao($conexao, (int)$tenant_id);
    config_moradores_responder(true, 'Parâmetros de moradores carregados.', [
        'campos' => [
            'moradores' => array_values($configuracao['campos']['moradores'] ?? []),
            'dependentes' => array_values($configuracao['campos']['dependentes'] ?? []),
        ],
        'opcoes' => array_values($configuracao['opcoes'] ?? []),
        'modo_compatibilidade' => !moradores_configuracao_disponivel($conexao),
        'pode_configurar' => $rbacAtivo ? rbacPode($conexao, 'sistema', 'configurar') : true,
    ]);
}

if ($metodo !== 'PUT') config_moradores_responder(false, 'Método não permitido.', null, 405);
if (!moradores_configuracao_disponivel($conexao)) {
    config_moradores_responder(false, 'A estrutura de configuração ainda não foi instalada. Execute a migration de Moradores.', null, 409);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada) || !is_array($entrada['campos'] ?? null) || !is_array($entrada['opcoes'] ?? null)) {
    config_moradores_responder(false, 'Informe as listas de campos e opções para configuração.', null, 400);
}
$recebidosCampos = $entrada['campos'];
$recebidasOpcoes = $entrada['opcoes'];
$catalogoCampos = moradores_catalogo_campos();
$catalogoOpcoes = moradores_catalogo_opcoes();
$normalizadosCampos = [];
$normalizadasOpcoes = [];

foreach ($recebidosCampos as $item) {
    if (!is_array($item)) config_moradores_responder(false, 'Cada campo deve ser um objeto de configuração.', null, 400);
    $grupo = (string)($item['grupo'] ?? '');
    $campo = (string)($item['campo'] ?? '');
    if (!isset($catalogoCampos[$grupo]['campos'][$campo])) {
        config_moradores_responder(false, 'Campo de morador não reconhecido.', null, 400);
    }
    $meta = $catalogoCampos[$grupo]['campos'][$campo];
    $obrigatorio = !empty($item['obrigatorio']) ? 1 : 0;
    if (!empty($meta['fixo']) && $obrigatorio !== 1) {
        config_moradores_responder(false, 'O campo "' . $meta['rotulo'] . '" é estrutural e não pode ser opcional.', null, 400);
    }
    if (empty($meta['fixo'])) $normalizadosCampos[$grupo . ':' . $campo] = [$grupo, $campo, $obrigatorio];
}

foreach ($recebidasOpcoes as $item) {
    if (!is_array($item)) config_moradores_responder(false, 'Cada opção deve ser um objeto de configuração.', null, 400);
    $opcao = (string)($item['opcao'] ?? '');
    if (!isset($catalogoOpcoes[$opcao])) {
        config_moradores_responder(false, 'Opção de morador não reconhecida.', null, 400);
    }
    $normalizadasOpcoes[$opcao] = !empty($item['habilitado']) ? 1 : 0;
}

$atuais = moradores_obter_configuracao($conexao, (int)$tenant_id);
$usuario_id = (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0);
$conexao->begin_transaction();
try {
    if ($normalizadosCampos) {
        $stmt = $conexao->prepare(
            'INSERT INTO config_moradores_campos (tenant_id,grupo,campo,obrigatorio,atualizado_por_usuario_id) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE obrigatorio=VALUES(obrigatorio), atualizado_por_usuario_id=VALUES(atualizado_por_usuario_id), atualizado_em=NOW()'
        );
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a atualização dos campos de moradores.');
        foreach ($normalizadosCampos as [$grupo, $campo, $obrigatorio]) {
            $stmt->bind_param('issii', $tenant_id, $grupo, $campo, $obrigatorio, $usuario_id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        }
        $stmt->close();
    }

    if ($normalizadasOpcoes) {
        $stmt = $conexao->prepare(
            'INSERT INTO config_moradores_opcoes (tenant_id,opcao,habilitado,atualizado_por_usuario_id) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE habilitado=VALUES(habilitado), atualizado_por_usuario_id=VALUES(atualizado_por_usuario_id), atualizado_em=NOW()'
        );
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a atualização das opções de moradores.');
        foreach ($normalizadasOpcoes as $opcao => $habilitado) {
            $stmt->bind_param('isii', $tenant_id, $opcao, $habilitado, $usuario_id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        }
        $stmt->close();
    }

    $conexao->commit();
    $depois = moradores_obter_configuracao($conexao, (int)$tenant_id);
    if ($rbacAtivo) {
        rbacAuditar($conexao, [
            'modulo_chave' => 'sistema',
            'submodulo_chave' => 'moradores',
            'acao' => 'CONFIGURAR_CAMPOS_E_REGRAS',
            'registro_tipo' => 'config_moradores',
            'dados_antes' => $atuais,
            'dados_depois' => $depois,
            'resultado' => 'SUCESSO',
            'status_http' => 200,
        ]);
    }
    registrar_log('CONFIG_MORADORES', 'Parâmetros de moradores e dependentes atualizados para o tenant ' . (int)$tenant_id, $_SESSION['usuario_nome'] ?? 'Sistema');
    config_moradores_responder(true, 'Parâmetros de moradores atualizados.', [
        'campos' => [
            'moradores' => array_values($depois['campos']['moradores'] ?? []),
            'dependentes' => array_values($depois['campos']['dependentes'] ?? []),
        ],
        'opcoes' => array_values($depois['opcoes'] ?? []),
    ]);
} catch (Throwable $e) {
    $conexao->rollback();
    error_log('[ConfigMoradores] tenant=' . (int)$tenant_id . ' erro=' . $e->getMessage());
    config_moradores_responder(false, 'Não foi possível salvar os parâmetros de moradores.', null, 500);
}
?>
