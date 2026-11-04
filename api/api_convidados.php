<?php
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/helpers/cpf_helper.php';
require_once __DIR__ . '/helpers/visitantes_config_helper.php';
require_once __DIR__ . '/helpers/alertas_unidade_helper.php';
require_once __DIR__ . '/helpers/convidados_core_helper.php';
ob_end_clean();
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$origem = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (preg_match('/^https?:\/\/([a-z0-9-]+\.)?erpcondominios\.com\.br$/i', $origem)
    || preg_match('/^https?:\/\/localhost(:\d+)?$/i', $origem)) {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Idempotency-Key');

set_exception_handler(function (Throwable $erro) {
    error_log('[Convidados][Uncaught] classe=' . get_class($erro) . ' codigo=' . (int)$erro->getCode());
    convidados_responder(false, 'Não foi possível concluir a operação de convidados. Tente novamente ou contate o administrador.', null, 500);
});

function convidados_listar(mysqli $conexao, $tenantId) {
    $data = trim((string)($_GET['data'] ?? convidados_data_hoje()));
    if (!convidados_data_valida($data)) convidados_erro('A data informada é inválida.');
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($_GET['limite'] ?? $_GET['por_pagina'] ?? 25)));
    $offset = ($pagina - 1) * $limite;
    $status = strtoupper(trim((string)($_GET['status'] ?? '')));
    $tiposValidosStatus = ['PREVISTO', 'PRESENTE', 'FINALIZADO', 'CANCELADO', 'EXPIRADO'];
    if ($status !== '' && !in_array($status, $tiposValidosStatus, true)) convidados_erro('Filtro de status inválido.');
    $tipo = strtoupper(trim((string)($_GET['tipo'] ?? $_GET['tipo_convidado'] ?? '')));
    $tiposValidos = ['VISITANTE', 'PRESTADOR', 'ENTREGADOR_AUTORIZADO', 'FAMILIAR'];
    if ($tipo !== '' && !in_array($tipo, $tiposValidos, true)) convidados_erro('Filtro de tipo inválido.');
    $unidade = trim((string)($_GET['unidade'] ?? ''));
    $busca = trim((string)($_GET['busca'] ?? ''));
    $origemFiltro = strtoupper(trim((string)($_GET['origem'] ?? '')));
    if ($origemFiltro !== '' && $origemFiltro !== 'MORADOR' && !in_array($origemFiltro, CONVIDADOS_ORIGENS_TODAS, true)) {
        convidados_erro('Filtro de origem inválido.');
    }

    $tiposBase = '';
    $paramsBase = [];
    $base = convidados_sql_base($data, $tiposBase, $paramsBase);
    // O tenant é sempre resolvido da sessão autenticada.
    $paramsBase[3] = (int)$tenantId;
    $filtros = '';
    $tiposFiltros = $tiposBase;
    $paramsFiltros = $paramsBase;
    if ($unidade !== '') convidados_adicionar_filtro($filtros, $tiposFiltros, $paramsFiltros, 'c.unidade', 's', $unidade);
    if ($tipo !== '') convidados_adicionar_filtro($filtros, $tiposFiltros, $paramsFiltros, 'c.tipo_convidado', 's', $tipo);
    if ($origemFiltro === 'MORADOR') {
        // Pré-cadastros feitos pelo morador (Portal do Morador ou aplicativo).
        $filtros .= " AND c.origem_cadastro IN ('PORTAL_MORADOR','APP_MORADOR')";
    } elseif ($origemFiltro !== '') {
        convidados_adicionar_filtro($filtros, $tiposFiltros, $paramsFiltros, 'c.origem_cadastro', 's', $origemFiltro);
    }
    if ($busca !== '') {
        $filtros .= ' AND (c.nome LIKE ? OR c.documento_normalizado LIKE ?)';
        $tiposFiltros .= 'ss';
        $paramsFiltros[] = '%' . $busca . '%';
        $paramsFiltros[] = '%' . convidados_normalizar_documento('RG', $busca) . '%';
    }
    if ($status === 'EXPIRADO') {
        $filtros .= " AND c.status IN ('PREVISTO','PRESENTE') AND c.validade_fim < CURDATE()";
    } elseif ($status !== '') {
        $filtros .= ' AND c.status = ?';
        $tiposFiltros .= 's';
        $paramsFiltros[] = $status;
        if (in_array($status, ['PREVISTO', 'PRESENTE'], true)) {
            $filtros .= ' AND c.validade_fim >= CURDATE()';
        }
    }

    $stmtCount = convidados_executar($conexao, 'SELECT COUNT(*) AS total FROM convidados c WHERE ' . $base . $filtros, $tiposFiltros, $paramsFiltros);
    $total = (int)(convidados_linha($stmtCount)['total'] ?? 0);
    $stmtCount->close();

    $tiposResumo = $tiposBase;
    $paramsResumo = $paramsBase;
    $filtrosResumo = '';
    if ($unidade !== '') convidados_adicionar_filtro($filtrosResumo, $tiposResumo, $paramsResumo, 'c.unidade', 's', $unidade);
    if ($tipo !== '') convidados_adicionar_filtro($filtrosResumo, $tiposResumo, $paramsResumo, 'c.tipo_convidado', 's', $tipo);
    if ($busca !== '') {
        $filtrosResumo .= ' AND (c.nome LIKE ? OR c.documento_normalizado LIKE ?)';
        $tiposResumo .= 'ss';
        $paramsResumo[] = '%' . $busca . '%';
        $paramsResumo[] = '%' . convidados_normalizar_documento('RG', $busca) . '%';
    }
    $sqlResumo = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN c.status = 'PREVISTO' AND c.validade_fim >= CURDATE() THEN 1 ELSE 0 END) AS previstos,
        SUM(CASE WHEN c.status = 'PRESENTE' AND c.validade_fim >= CURDATE() THEN 1 ELSE 0 END) AS presentes,
        SUM(CASE WHEN c.status = 'FINALIZADO' THEN 1 ELSE 0 END) AS finalizados,
        SUM(CASE WHEN c.status IN ('PREVISTO','PRESENTE') AND c.validade_fim < CURDATE() THEN 1 ELSE 0 END) AS expirados,
        SUM(CASE WHEN c.status = 'CANCELADO' THEN 1 ELSE 0 END) AS cancelados,
        SUM(CASE WHEN c.origem_cadastro IN ('PORTAL_MORADOR','APP_MORADOR') AND c.status <> 'CANCELADO' THEN 1 ELSE 0 END) AS pre_cadastros_morador
        FROM convidados c WHERE {$base}{$filtrosResumo}";
    $stmtResumo = convidados_executar($conexao, $sqlResumo, $tiposResumo, $paramsResumo);
    $resumo = convidados_linha($stmtResumo) ?: [];
    $stmtResumo->close();
    foreach (['total', 'previstos', 'presentes', 'finalizados', 'expirados', 'cancelados', 'pre_cadastros_morador'] as $campo) {
        $resumo[$campo] = (int)($resumo[$campo] ?? 0);
    }

    $temColunaConvidadoId = convidados_coluna_existe($conexao, 'registros_acesso', 'convidado_id');
    $sqlLista = convidados_obter_sql_select($temColunaConvidadoId) . " WHERE {$base}{$filtros}
        ORDER BY CASE
            WHEN c.status = 'PRESENTE' AND c.validade_fim >= CURDATE() THEN 1
            WHEN c.status = 'PREVISTO' AND c.validade_fim >= CURDATE() THEN 2
            WHEN c.status IN ('PREVISTO','PRESENTE') AND c.validade_fim < CURDATE() THEN 4
            WHEN c.status = 'CANCELADO' THEN 5
            ELSE 3 END, c.nome ASC LIMIT ? OFFSET ?";
    $paramsListaSemPaginacao = $paramsFiltros;
    $paramsListaSemPaginacao[] = $limite;
    $paramsListaSemPaginacao[] = $offset;
    $stmtLista = convidados_executar($conexao, $sqlLista, $tiposFiltros . 'ii', $paramsListaSemPaginacao);
    $linhas = convidados_todas_linhas($stmtLista);
    $stmtLista->close();
    $convidados = [];
    foreach ($linhas as $linha) $convidados[] = convidados_formatar_linha($linha, convidados_data_hoje(), false);

    error_log('[Convidados] listagem tenant=' . (int)$tenantId . ' data=' . $data . ' total=' . $total);
    convidados_responder(true, 'Convidados listados com sucesso.', [
        'convidados' => $convidados,
        'resumo' => $resumo,
        'total' => $total,
        'pagina' => $pagina,
        'limite' => $limite,
        'total_paginas' => $limite > 0 ? (int)ceil($total / $limite) : 0,
        'data' => $data,
    ]);
}

function convidados_obter(mysqli $conexao, $tenantId, $id, $dataReferencia = null) {
    if ($id <= 0) convidados_erro('ID do convidado inválido.');
    $dataReferencia = $dataReferencia ?: convidados_data_hoje();
    $temColunaConvidadoId = convidados_coluna_existe($conexao, 'registros_acesso', 'convidado_id');
    $stmt = convidados_executar($conexao, convidados_obter_sql_select($temColunaConvidadoId) . ' WHERE c.tenant_id = ? AND c.id = ? LIMIT 1', 'ii', [$tenantId, $id]);
    $linha = convidados_linha($stmt);
    $stmt->close();
    if (!$linha) convidados_erro('Convidado não encontrado neste condomínio.', 404, 'CONVIDADO_NAO_ENCONTRADO');
    $detalhe = convidados_formatar_linha($linha, $dataReferencia, true);
    $detalhe['movimentacoes'] = [];
    if ($temColunaConvidadoId) {
        $stmtMov = convidados_executar(
            $conexao,
            'SELECT id, data_hora, tipo_acesso, tipo, placa, nome_visitante, documento_visitante, morador_id, dependente_id, unidade_destino, status, liberado, usuario_liberou, observacao, modo_registro FROM registros_acesso WHERE tenant_id = ? AND convidado_id = ? ORDER BY data_hora DESC, id DESC LIMIT 200',
            'ii',
            [$tenantId, $id]
        );
        $detalhe['movimentacoes'] = convidados_todas_linhas($stmtMov);
        $stmtMov->close();
    }
    convidados_responder(true, 'Convidado obtido com sucesso.', $detalhe);
}

function convidados_buscar_documento(mysqli $conexao, $tenantId) {
    $tipo = strtoupper(trim((string)($_GET['tipo_documento'] ?? '')));
    $documento = trim((string)($_GET['documento'] ?? ''));
    if (!in_array($tipo, ['CPF', 'RG'], true) || $documento === '') convidados_erro('Informe o tipo e o número do documento.');
    if ($tipo === 'CPF' && !cpf_valido($documento)) convidados_erro('O CPF informado é inválido.');
    $normalizado = convidados_normalizar_documento($tipo, $documento);
    $data = trim((string)($_GET['data'] ?? convidados_data_hoje()));
    if (!convidados_data_valida($data)) convidados_erro('A data informada é inválida.');
    $stmt = convidados_executar(
        $conexao,
        convidados_obter_sql_select() . " WHERE c.tenant_id = ? AND c.tipo_documento = ? AND c.documento_normalizado = ?
            AND c.data_prevista_chegada <= ? AND c.validade_fim >= ? AND c.status IN ('PREVISTO','PRESENTE')
            ORDER BY c.nome ASC",
        'issss',
        [$tenantId, $tipo, $normalizado, $data, $data]
    );
    $linhas = convidados_todas_linhas($stmt);
    $stmt->close();
    $resultado = [];
    foreach ($linhas as $linha) $resultado[] = convidados_formatar_linha($linha, $data, false);
    convidados_responder(true, $resultado ? 'Convidados válidos encontrados.' : 'Nenhum convidado válido encontrado para este documento.', [
        'convidados' => $resultado,
        'data' => $data,
    ]);
}

function convidados_politica_visitantes(mysqli $conexao, $tenantId) {
    convidados_responder(true, 'Política de cadastro carregada.', [
        'campos' => array_values(visitantes_obter_config_campos($conexao, (int)$tenantId)),
        'modo_compatibilidade' => !visitantes_tabela_config_existe($conexao),
    ]);
}

function convidados_verificar_alertas(mysqli $conexao, $tenantId, array $dados) {
    $entrada = convidados_dados_entrada($conexao, $tenantId, $dados, 'criar');
    if (!function_exists('alertas_unidade_migration_instalada') || !alertas_unidade_migration_instalada($conexao)) {
        convidados_responder(true, 'Verificação de alertas indisponível; a portaria revalidará no momento da entrada.', [
            'verificacao_disponivel' => false,
            'alertas' => [],
        ]);
    }
    $dataHora = $entrada['data_prevista_chegada'] . ' 12:00:00';
    $contexto = [
        'contexto' => 'CONVIDADOS',
        'unidade' => $entrada['unidade'],
        'morador_id' => $entrada['morador_id'],
        'dependente_id' => $entrada['anfitriao_tipo'] === 'DEPENDENTE' ? $entrada['dependente_id'] : 0,
        'visitante_id' => $entrada['visitante_id'] ?? 0,
        'tipo_pessoa' => $entrada['tipo_convidado'],
        'documento' => $entrada['documento'],
        'tipo_documento' => $entrada['tipo_documento'],
        'pessoa_nome' => $entrada['nome'],
        'tipo_acesso' => 'Entrada',
        'data_hora' => $dataHora,
    ];
    $alertas = alertas_unidade_avaliar($conexao, $tenantId, $contexto);
    $resumo = [];
    foreach ($alertas as $alerta) {
        $resumo[] = [
            'id' => (int)$alerta['id'],
            'titulo' => (string)$alerta['titulo'],
            'nivel' => (string)$alerta['nivel'],
            'acao' => (string)$alerta['acao'],
        ];
    }
    convidados_responder(true, 'Verificação de alertas concluída.', [
        'verificacao_disponivel' => true,
        'data_hora_verificada' => $dataHora,
        'aviso_horario' => 'O convite informa apenas a data; a verificação usa 12h (horário local). A portaria revalidará os alertas no horário real da entrada.',
        'alertas' => $resumo,
    ]);
}

function convidados_autor_sessao($auth) {
    return [
        'tipo' => 'USUARIO',
        'usuario_id' => (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0),
        'nome' => (string)($auth['nome'] ?? $_SESSION['usuario_nome'] ?? 'Usuário autenticado'),
    ];
}

function convidados_criar(mysqli $conexao, $auth, $tenantId, array $dados) {
    $entrada = convidados_dados_entrada($conexao, $tenantId, $dados, 'criar');
    $autor = convidados_autor_sessao($auth);
    $resultado = convidados_persistir_novo($conexao, $tenantId, $entrada, $autor);
    if ($resultado['idempotente']) {
        convidados_responder(true, 'Convidado já havia sido cadastrado.', ['id' => $resultado['id'], 'idempotente' => true]);
    }
    $id = $resultado['id'];
    error_log('[Convidados] criado tenant=' . (int)$tenantId . ' id=' . $id . ' usuario=' . (int)$autor['usuario_id']);
    if (rbacTabelasDisponiveis($conexao)) {
        rbacAuditar($conexao, [
            'tenant_id' => $tenantId, 'usuario_id' => (int)$autor['usuario_id'], 'modulo_chave' => 'registro',
            'submodulo_chave' => 'convidados', 'acao' => 'CRIAR', 'registro_tipo' => 'convidado',
            'registro_id' => $id, 'dados_depois' => ['morador_id' => $entrada['morador_id'], 'tipo_convidado' => $entrada['tipo_convidado']],
            'resultado' => 'SUCESSO', 'status_http' => 201,
        ]);
    }
    convidados_responder(true, 'Convidado cadastrado com sucesso.', ['id' => $id], 201);
}

function convidados_editar(mysqli $conexao, $auth, $tenantId, array $dados) {
    $id = (int)($dados['id'] ?? $_GET['id'] ?? 0);
    $autor = convidados_autor_sessao($auth);
    // A origem de pré-cadastros do morador (Portal/App) é preservada pelo núcleo.
    convidados_atualizar_existente($conexao, $tenantId, $id, $dados, $autor);
    error_log('[Convidados] editado tenant=' . (int)$tenantId . ' id=' . $id . ' usuario=' . (int)$autor['usuario_id']);
    if (rbacTabelasDisponiveis($conexao)) rbacAuditar($conexao, [
        'tenant_id' => $tenantId, 'usuario_id' => (int)$autor['usuario_id'], 'modulo_chave' => 'registro', 'submodulo_chave' => 'convidados',
        'acao' => 'EDITAR', 'registro_tipo' => 'convidado', 'registro_id' => $id, 'resultado' => 'SUCESSO', 'status_http' => 200,
    ]);
    convidados_responder(true, 'Convidado atualizado com sucesso.', ['id' => $id]);
}

function convidados_cancelar(mysqli $conexao, $auth, $tenantId, array $dados) {
    $id = (int)($dados['id'] ?? $_GET['id'] ?? 0);
    $motivo = trim((string)($dados['motivo'] ?? ''));
    $autor = convidados_autor_sessao($auth);
    convidados_cancelar_existente($conexao, $tenantId, $id, $motivo, $autor);
    error_log('[Convidados] cancelado tenant=' . (int)$tenantId . ' id=' . $id . ' usuario=' . (int)$autor['usuario_id']);
    if (rbacTabelasDisponiveis($conexao)) rbacAuditar($conexao, [
        'tenant_id' => $tenantId, 'usuario_id' => (int)$autor['usuario_id'], 'modulo_chave' => 'registro', 'submodulo_chave' => 'convidados',
        'acao' => 'CANCELAR', 'registro_tipo' => 'convidado', 'registro_id' => $id, 'motivo' => $motivo,
        'resultado' => 'SUCESSO', 'status_http' => 200,
    ]);
    convidados_responder(true, 'Convidado cancelado com sucesso.', ['id' => $id, 'status' => 'CANCELADO']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$dados = [];
if (in_array($metodo, ['POST', 'PUT', 'PATCH'], true)) {
    $entradaJson = json_decode(file_get_contents('php://input'), true);
    if (!is_array($entradaJson)) convidados_erro('Envie um JSON válido no corpo da requisição.');
    $dados = $entradaJson;
}
$acao = strtolower(trim((string)($_GET['acao'] ?? $dados['acao'] ?? '')));
if ($metodo === 'GET' && $acao === '') $acao = 'listar';

try {
    $auth = verificarAutenticacao(true, 'operador');
    $tenantId = (int)($auth['tenant_id'] ?? $_SESSION['tenant_id'] ?? 0);
    if ($tenantId <= 0) convidados_erro('Contexto de condomínio não identificado.', 403, 'TENANT_REQUIRED');
    $conexao = conectar_banco();
    $rbacAtivo = rbacTabelasDisponiveis($conexao);
    $acaoRbac = 'visualizar';
    if ($metodo === 'POST' && in_array($acao, ['criar', 'verificar_alertas'], true)) $acaoRbac = 'criar';
    elseif ($metodo === 'PUT' && $acao === 'editar') $acaoRbac = 'editar';
    elseif ($metodo === 'POST' && $acao === 'cancelar') $acaoRbac = 'excluir';
    elseif ($metodo !== 'GET') convidados_erro('Método não permitido.', 405);
    if ($rbacAtivo) {
        rbacExigir($conexao, 'registro', $acaoRbac, ['submodulo_chave' => 'convidados', 'registro_tipo' => 'convidado']);
    } elseif ($metodo !== 'GET') {
        verificarPermissao('operador');
    }

    if ($metodo === 'GET' && $acao === 'listar') convidados_listar($conexao, $tenantId);
    if ($metodo === 'GET' && $acao === 'obter') convidados_obter($conexao, $tenantId, (int)($_GET['id'] ?? 0), $_GET['data'] ?? null);
    if ($metodo === 'GET' && $acao === 'buscar_documento') convidados_buscar_documento($conexao, $tenantId);
    if ($metodo === 'GET' && $acao === 'politica_visitantes') convidados_politica_visitantes($conexao, $tenantId);
    if ($metodo === 'POST' && $acao === 'verificar_alertas') convidados_verificar_alertas($conexao, $tenantId, $dados);
    if ($metodo === 'POST' && $acao === 'criar') convidados_criar($conexao, $auth, $tenantId, $dados);
    if ($metodo === 'PUT' && $acao === 'editar') convidados_editar($conexao, $auth, $tenantId, $dados);
    if ($metodo === 'POST' && $acao === 'cancelar') convidados_cancelar($conexao, $auth, $tenantId, $dados);
    convidados_erro('Ação ou método não permitido para a Lista de Convidados.', 405, 'ACAO_NAO_PERMITIDA');
} catch (Throwable $erro) {
    error_log('[Convidados][Fatal] tenant=' . (int)($tenantId ?? 0) . ' acao=' . $acao . ' classe=' . get_class($erro) . ' codigo=' . (int)$erro->getCode());
    convidados_responder(false, 'Não foi possível concluir a operação de convidados. Tente novamente ou contate o administrador.', null, 500);
}
