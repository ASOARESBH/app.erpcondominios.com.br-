<?php
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function api_chatin_config_responder($sucesso, $mensagem, $dados = [], $status = 200, $codigo = null) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    $saida = [
        'sucesso' => (bool)$sucesso,
        'mensagem' => (string)$mensagem,
        'dados' => $dados,
    ];
    if ($codigo !== null) $saida['codigo'] = (string)$codigo;
    echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_chatin_config_validar_origem_sessao($metodo) {
    if (strtoupper((string)$metodo) !== 'PUT') return;
    $origem = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origem !== '') {
        $schemeAtual = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $atual = parse_url($schemeAtual . '://' . (string)($_SERVER['HTTP_HOST'] ?? ''));
        $recebida = parse_url($origem);
        if (!is_array($atual) || !is_array($recebida)) {
            api_chatin_config_responder(false, 'Origem da solicitação não autorizada.', [], 403, 'ORIGIN_DENIED');
        }
        $schemeRecebido = strtolower((string)($recebida['scheme'] ?? ''));
        $schemeDestino = strtolower((string)($atual['scheme'] ?? ''));
        $portaRecebida = (int)($recebida['port'] ?? ($schemeRecebido === 'https' ? 443 : 80));
        $portaDestino = (int)($atual['port'] ?? ($schemeDestino === 'https' ? 443 : 80));
        $hostRecebido = strtolower((string)($recebida['host'] ?? ''));
        $pathRecebido = (string)($recebida['path'] ?? '');
        if (!in_array($schemeRecebido, ['http', 'https'], true) || $hostRecebido === ''
            || isset($recebida['query']) || isset($recebida['fragment'])
            || ($pathRecebido !== '' && $pathRecebido !== '/')
            || $schemeRecebido !== $schemeDestino
            || $hostRecebido !== strtolower((string)($atual['host'] ?? ''))
            || $portaRecebida !== $portaDestino
            || !empty($recebida['user']) || !empty($recebida['pass'])) {
            api_chatin_config_responder(false, 'Origem da solicitação não autorizada.', [], 403, 'ORIGIN_DENIED');
        }
        return;
    }
    if (strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''))) !== 'same-origin') {
        api_chatin_config_responder(false, 'Origem da solicitação não confirmada.', [], 403, 'ORIGIN_REQUIRED');
    }
}

function api_chatin_config_tabela_existe($conexao, $nome) {
    $nome = (string)$nome;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $nome)) return false;
    try {
        return \ERP\Database\DatabaseDialect::tableExists($conexao, $nome);
    } catch (Throwable $e) {
        return false;
    }
}

function api_chatin_config_instalado($conexao) {
    if (!api_chatin_config_tabela_existe($conexao, 'chatin_configuracoes')) return false;
    $obrigatorias = [
        'tenant_id', 'ativo', 'usuario_usuario', 'usuario_morador',
        'morador_inicia_com_equipe', 'morador_morador', 'recurso_sons',
        'recurso_cutucar', 'recurso_digitando', 'recurso_emojis',
        'recurso_push_app', 'grupos_visiveis_moradores_json',
        'filtro_moradores_json', 'atualizado_por', 'atualizado_em',
    ];
    try {
        foreach ($obrigatorias as $coluna) {
            if (!\ERP\Database\DatabaseDialect::columnExists($conexao, 'chatin_configuracoes', $coluna)) return false;
        }
    } catch (Throwable $e) {
        return false;
    }
    return true;
}

function api_chatin_config_padrao() {
    return [
        'ativo' => true,
        'usuario_usuario' => true,
        'usuario_morador' => true,
        'morador_inicia_com_equipe' => true,
        'morador_morador' => true,
        'recurso_sons' => true,
        'recurso_cutucar' => true,
        'recurso_digitando' => true,
        'recurso_emojis' => true,
        'recurso_push_app' => true,
        'grupos_visiveis_moradores' => [],
        'filtro_moradores' => [],
    ];
}

function api_chatin_config_lista_ids($valor) {
    if (!is_array($valor)) return [];
    $ids = [];
    foreach ($valor as $item) {
        if (is_int($item)) $id = $item;
        elseif (is_string($item) && preg_match('/^[0-9]+$/', $item)) $id = (int)$item;
        else continue;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function api_chatin_config_ler_atual($conexao, $tenantId) {
    $config = api_chatin_config_padrao();
    $sql = 'SELECT ativo, usuario_usuario, usuario_morador, morador_inicia_com_equipe,
                   morador_morador, recurso_sons, recurso_cutucar, recurso_digitando,
                   recurso_emojis, recurso_push_app, grupos_visiveis_moradores_json
            FROM chatin_configuracoes WHERE tenant_id = ? LIMIT 1';
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a leitura da configuração Chat-In.');
    $stmt->bind_param('i', $tenantId);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException($erro);
    }
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) return $config;

    foreach ([
        'ativo', 'usuario_usuario', 'usuario_morador', 'morador_inicia_com_equipe',
        'morador_morador', 'recurso_sons', 'recurso_cutucar', 'recurso_digitando',
        'recurso_emojis', 'recurso_push_app',
    ] as $campo) {
        $config[$campo] = (int)($linha[$campo] ?? 0) === 1;
    }
    $ids = json_decode((string)($linha['grupos_visiveis_moradores_json'] ?? '[]'), true);
    $config['grupos_visiveis_moradores'] = api_chatin_config_lista_ids($ids);
    // Ainda não há filtro de perfil de morador implementado; não inferir valores do schema.
    $config['filtro_moradores'] = [];
    return $config;
}

function api_chatin_config_ler_filtro_persistido($conexao, $tenantId) {
    $stmt = $conexao->prepare('SELECT filtro_moradores_json FROM chatin_configuracoes WHERE tenant_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a leitura do filtro Chat-In.');
    $stmt->bind_param('i', $tenantId);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException($erro);
    }
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string)($linha['filtro_moradores_json'] ?? '[]');
}

function api_chatin_config_grupos($conexao, $tenantId) {
    if (!api_chatin_config_tabela_existe($conexao, 'rbac_grupos')) return [];
    $stmt = $conexao->prepare('SELECT id, nome FROM rbac_grupos WHERE tenant_id = ? AND ativo = 1 AND excluido_em IS NULL ORDER BY nome ASC, id ASC');
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a leitura dos grupos Chat-In.');
    $stmt->bind_param('i', $tenantId);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException($erro);
    }
    $resultado = $stmt->get_result();
    $grupos = [];
    while ($linha = $resultado->fetch_assoc()) {
        $grupos[] = ['id' => (int)$linha['id'], 'nome' => (string)$linha['nome']];
    }
    $stmt->close();
    return $grupos;
}

function api_chatin_config_filtrar_grupos(array $ids, array $grupos) {
    $permitidos = [];
    foreach ($grupos as $grupo) $permitidos[(int)$grupo['id']] = true;
    return array_values(array_filter($ids, static function ($id) use ($permitidos) {
        return isset($permitidos[(int)$id]);
    }));
}

function api_chatin_config_dados($instalado, $podeConfigurar, array $config, array $grupos) {
    $config['grupos_visiveis_moradores'] = api_chatin_config_filtrar_grupos(
        api_chatin_config_lista_ids($config['grupos_visiveis_moradores'] ?? []),
        $grupos
    );
    $config['filtro_moradores'] = [];
    return [
        'instalado' => (bool)$instalado,
        'pode_configurar' => (bool)$podeConfigurar,
        'config' => $config,
        'grupos' => array_values($grupos),
    ];
}

function api_chatin_config_exigir_permissao($conexao, $rbacAtivo, $metodo) {
    if (!$rbacAtivo) {
        verificarPermissao('admin');
        return;
    }
    $acao = $metodo === 'GET' ? 'visualizar' : 'configurar';
    $alternativas = [['chatin', $acao], ['sistema', $acao]];
    foreach ($alternativas as [$modulo, $permissao]) {
        if (rbacPode($conexao, $modulo, $permissao)) return;
    }
    // rbacExigir registra a negativa e devolve JSON 403 quando nenhuma alternativa concede.
    rbacExigir($conexao, $alternativas[0][0], $alternativas[0][1]);
}

function api_chatin_config_booleano_valido($valor) {
    return is_bool($valor) || $valor === 0 || $valor === 1 || $valor === '0' || $valor === '1';
}

function api_chatin_config_bind($stmt, $tipos, array &$valores) {
    $referencias = [$tipos];
    foreach ($valores as $indice => &$valor) $referencias[] = &$valor;
    return call_user_func_array([$stmt, 'bind_param'], $referencias);
}

$auth = verificarAutenticacao(true);
$tenant_id = (int)exigirTenantId();
$conexao = conectar_banco();
if (!$conexao) api_chatin_config_responder(false, 'Não foi possível conectar ao banco de dados.', [], 500, 'ERRO_BANCO');
$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($metodo, ['GET', 'PUT'], true)) {
    api_chatin_config_responder(false, 'Método não permitido.', [], 405, 'METODO_NAO_PERMITIDO');
}
api_chatin_config_validar_origem_sessao($metodo);
$rbacAtivo = rbacTabelasDisponiveis($conexao);
api_chatin_config_exigir_permissao($conexao, $rbacAtivo, $metodo);
$podeConfigurar = $rbacAtivo
    ? (rbacPode($conexao, 'chatin', 'configurar') || rbacPode($conexao, 'sistema', 'configurar'))
    : true;

try {
    if (!api_chatin_config_instalado($conexao)) {
        $grupos = api_chatin_config_grupos($conexao, $tenant_id);
        api_chatin_config_responder(
            false,
            'A configuração do Chat-In ainda não foi instalada. Aplique a migration Chat-In correspondente.',
            api_chatin_config_dados(false, $podeConfigurar, api_chatin_config_padrao(), $grupos),
            409,
            'NAO_INSTALADO'
        );
    }

    if ($metodo === 'GET') {
        $config = api_chatin_config_ler_atual($conexao, $tenant_id);
        $grupos = api_chatin_config_grupos($conexao, $tenant_id);
        api_chatin_config_responder(true, 'Configuração Chat-In carregada.', api_chatin_config_dados(true, $podeConfigurar, $config, $grupos));
    }

    $corpoBruto = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen((string)$corpoBruto) > 65536) {
        api_chatin_config_responder(false, 'A requisição excede o tamanho permitido.', [], 413, 'PAYLOAD_MUITO_GRANDE');
    }
    $entrada = json_decode((string)$corpoBruto, true);
    if (!is_array($entrada)) api_chatin_config_responder(false, 'Informe um objeto JSON válido.', [], 400, 'ENTRADA_INVALIDA');
    unset($entrada['tenant_id']); // O tenant sempre vem da sessão autenticada.
    $chavesPermitidas = ['config', 'grupos_visiveis_moradores'];
    foreach (array_keys($entrada) as $chave) {
        if (!in_array($chave, $chavesPermitidas, true)) {
            api_chatin_config_responder(false, 'A requisição contém um campo não permitido.', [], 400, 'CAMPO_INVALIDO');
        }
    }
    if (array_key_exists('config', $entrada) && !is_array($entrada['config'])) {
        api_chatin_config_responder(false, 'O campo config deve ser um objeto.', [], 400, 'CONFIG_INVALIDA');
    }
    $camposBooleanos = [
        'ativo', 'usuario_usuario', 'usuario_morador', 'morador_inicia_com_equipe',
        'morador_morador', 'recurso_sons', 'recurso_cutucar', 'recurso_digitando',
        'recurso_emojis', 'recurso_push_app',
    ];
    $recebidos = $entrada['config'] ?? [];
    foreach (array_keys($recebidos) as $chave) {
        if (!in_array($chave, $camposBooleanos, true)) {
            api_chatin_config_responder(false, 'A configuração contém uma opção não permitida.', [], 400, 'CAMPO_INVALIDO');
        }
        if (!api_chatin_config_booleano_valido($recebidos[$chave])) {
            api_chatin_config_responder(false, 'Os valores das opções devem ser booleanos.', [], 400, 'VALOR_INVALIDO');
        }
    }

    $atual = api_chatin_config_ler_atual($conexao, $tenant_id);
    $filtroPersistido = api_chatin_config_ler_filtro_persistido($conexao, $tenant_id);
    $grupos = api_chatin_config_grupos($conexao, $tenant_id);
    $depois = $atual;
    foreach ($recebidos as $chave => $valor) $depois[$chave] = filter_var($valor, FILTER_VALIDATE_BOOLEAN);

    if (array_key_exists('grupos_visiveis_moradores', $entrada)) {
        if (!is_array($entrada['grupos_visiveis_moradores'])) {
            api_chatin_config_responder(false, 'A lista de grupos visíveis deve ser uma lista de IDs.', [], 400, 'GRUPOS_INVALIDOS');
        }
        if (count($entrada['grupos_visiveis_moradores']) > 1000) {
            api_chatin_config_responder(false, 'A lista de grupos visíveis excede o limite permitido.', [], 422, 'GRUPOS_INVALIDOS');
        }
        foreach ($entrada['grupos_visiveis_moradores'] as $id) {
            if (!(is_int($id) || (is_string($id) && preg_match('/^[0-9]+$/', $id))) || (int)$id <= 0) {
                api_chatin_config_responder(false, 'Cada grupo deve ter um ID inteiro positivo.', [], 400, 'GRUPO_INVALIDO');
            }
        }
        $idsSolicitados = api_chatin_config_lista_ids($entrada['grupos_visiveis_moradores']);
        $idsDisponiveis = [];
        foreach ($grupos as $grupo) $idsDisponiveis[(int)$grupo['id']] = true;
        foreach ($idsSolicitados as $id) {
            if (!isset($idsDisponiveis[$id])) {
                api_chatin_config_responder(false, 'Um ou mais grupos não estão ativos neste condomínio.', [], 400, 'GRUPO_INVALIDO');
            }
        }
        $depois['grupos_visiveis_moradores'] = $idsSolicitados;
    }
    $depois['filtro_moradores'] = [];
    $antesAuditavel = api_chatin_config_dados(true, $podeConfigurar, $atual, $grupos)['config'];
    $depoisAuditavel = api_chatin_config_dados(true, $podeConfigurar, $depois, $grupos)['config'];

    $conexao->begin_transaction();
    try {
        $configColunas = [
            'ativo', 'usuario_usuario', 'usuario_morador', 'morador_inicia_com_equipe',
            'morador_morador', 'recurso_sons', 'recurso_cutucar', 'recurso_digitando',
            'recurso_emojis', 'recurso_push_app',
        ];
        $valores = [$tenant_id];
        foreach ($configColunas as $campo) $valores[] = $depois[$campo] ? 1 : 0;
        $gruposJson = json_encode($depois['grupos_visiveis_moradores'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $usuario_id = (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0);
        $valores[] = $gruposJson;
        $valores[] = $filtroPersistido;
        $valores[] = $usuario_id;
        $sql = 'INSERT INTO chatin_configuracoes
                    (tenant_id, ativo, usuario_usuario, usuario_morador, morador_inicia_com_equipe,
                     morador_morador, recurso_sons, recurso_cutucar, recurso_digitando, recurso_emojis,
                     recurso_push_app, grupos_visiveis_moradores_json, filtro_moradores_json, atualizado_por, atualizado_em)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                    ativo=VALUES(ativo), usuario_usuario=VALUES(usuario_usuario),
                    usuario_morador=VALUES(usuario_morador),
                    morador_inicia_com_equipe=VALUES(morador_inicia_com_equipe),
                    morador_morador=VALUES(morador_morador), recurso_sons=VALUES(recurso_sons),
                    recurso_cutucar=VALUES(recurso_cutucar), recurso_digitando=VALUES(recurso_digitando),
                    recurso_emojis=VALUES(recurso_emojis), recurso_push_app=VALUES(recurso_push_app),
                    grupos_visiveis_moradores_json=VALUES(grupos_visiveis_moradores_json),
                    filtro_moradores_json=VALUES(filtro_moradores_json),
                    atualizado_por=VALUES(atualizado_por), atualizado_em=NOW()';
        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a gravação da configuração Chat-In.');
        $tipos = str_repeat('i', 11) . 'ssi';
        api_chatin_config_bind($stmt, $tipos, $valores);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException($erro);
        }
        $stmt->close();

        $auditado = rbacAuditar($conexao, [
            'tenant_id' => $tenant_id,
            'usuario_id' => $usuario_id,
            'modulo_chave' => 'chatin',
            'submodulo_chave' => 'configuracao',
            'acao' => 'CONFIGURAR_CHATIN',
            'registro_tipo' => 'chatin_configuracoes',
            'registro_id' => (string)$tenant_id,
            'dados_antes' => $antesAuditavel,
            'dados_depois' => $depoisAuditavel,
            'resultado' => 'SUCESSO',
            'status_http' => 200,
        ]);
        if ($rbacAtivo && !$auditado) throw new RuntimeException('Não foi possível registrar a auditoria da configuração Chat-In.');
        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        throw $e;
    }

    api_chatin_config_responder(true, 'Configuração Chat-In atualizada.', api_chatin_config_dados(true, $podeConfigurar, $depois, $grupos));
} catch (Throwable $e) {
    error_log('[ConfigChatIn] tenant=' . $tenant_id . ' erro=' . get_class($e) . ' codigo=' . (int)$e->getCode());
    api_chatin_config_responder(false, 'Não foi possível carregar ou salvar a configuração Chat-In.', [], 500, 'ERRO_CONFIG_CHATIN');
}
?>
