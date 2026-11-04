<?php
/**
 * ERP CONDOMÍNIOS — CONVIDADOS (pré-cadastro do morador)
 *
 * Usado pelo Portal do Morador (frontend/portal_morador.html, aba "Convidados")
 * e pelo aplicativo Flutter (tela "Convidados"). O pré-cadastro cai na mesma
 * tabela `convidados` da aba Registro > Convidados, adiantando o trabalho da portaria.
 *
 * Autenticação: token Bearer de `sessoes_portal`. O morador, a unidade e o tenant
 * vêm SEMPRE da sessão; o cliente nunca escolhe morador, unidade ou origem.
 * Origem automática: PORTAL_MORADOR (portal) ou APP_MORADOR (canal=app; cai para
 * PORTAL_MORADOR se a migration do ENUM ainda não foi aplicada).
 * Autoria: cadastrado_por_tipo = MORADOR.
 *
 * GET  ?acao=opcoes                         → dados do formulário (dependentes, tipos, visitantes da unidade)
 * GET  ?acao=listar&filtro=ativos|historico|todos&pagina=&limite=
 * GET  ?acao=obter&id=
 * POST ?acao=verificar_alertas              → alertas da própria unidade que afetam o convite
 * POST ?acao=criar                          → pré-cadastro (aceita X-Idempotency-Key ou idempotency_key)
 * POST|PUT ?acao=editar                     → somente PREVISTO e cadastrado pelo próprio morador
 * POST ?acao=cancelar  {id, motivo}         → somente PREVISTO do próprio morador
 *
 * Regras de validação, duplicidade e persistência: api/helpers/convidados_core_helper.php
 */

ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/convidados_core_helper.php';
require_once __DIR__ . '/helpers/portal_visitantes_unidade_helper.php';
require_once __DIR__ . '/helpers/alertas_unidade_helper.php';
ob_end_clean();
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$origemHttp = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (preg_match('/^https?:\/\/([a-z0-9-]+\.)?erpcondominios\.com\.br$/i', $origemHttp)
    || preg_match('/^https?:\/\/localhost(:\d+)?$/i', $origemHttp)) {
    header('Access-Control-Allow-Origin: ' . $origemHttp);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Idempotency-Key');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(function (Throwable $erro) {
    error_log('[PortalConvidados][Uncaught] classe=' . get_class($erro) . ' codigo=' . (int)$erro->getCode());
    convidados_responder(false, 'Não foi possível concluir a operação de convidados. Tente novamente.', null, 500);
});

/** Limite de pré-cadastros ativos (PREVISTO) feitos pelo mesmo morador — proteção contra abuso. */
const PORTAL_CONVIDADOS_LIMITE_ATIVOS = 100;

function portal_convidados_token() {
    $cabecalho = '';
    if (function_exists('getallheaders')) {
        $cabecalhos = getallheaders();
        $cabecalho = $cabecalhos['Authorization'] ?? $cabecalhos['authorization'] ?? '';
    }
    if ($cabecalho === '') $cabecalho = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9._~+\/=-]{20,256})$/i', trim($cabecalho), $m)) return null;
    return $m[1];
}

function portal_convidados_auth(mysqli $conexao) {
    $token = portal_convidados_token();
    if ($token === null) convidados_erro('Sessão inválida. Faça login novamente.', 401, 'AUTH_REQUIRED');
    $temTenantSessao = convidados_coluna_existe($conexao, 'sessoes_portal', 'tenant_id');
    $sql = $temTenantSessao
        ? 'SELECT sp.morador_id, m.tenant_id, m.nome, m.unidade FROM sessoes_portal sp
           INNER JOIN moradores m ON m.id = sp.morador_id AND m.ativo = 1 AND (sp.tenant_id IS NULL OR m.tenant_id = sp.tenant_id)
           WHERE sp.token = ? AND sp.ativo = 1 AND sp.data_expiracao > NOW() LIMIT 1'
        : 'SELECT sp.morador_id, m.tenant_id, m.nome, m.unidade FROM sessoes_portal sp
           INNER JOIN moradores m ON m.id = sp.morador_id AND m.ativo = 1
           WHERE sp.token = ? AND sp.ativo = 1 AND sp.data_expiracao > NOW() LIMIT 1';
    $stmt = convidados_executar($conexao, $sql, 's', [$token]);
    $sessao = convidados_linha($stmt);
    $stmt->close();
    if (!$sessao || (int)$sessao['tenant_id'] <= 0 || (int)$sessao['morador_id'] <= 0) {
        convidados_erro('Sessão inválida ou expirada. Faça login novamente.', 401, 'AUTH_INVALID');
    }
    return [
        'tenant_id' => (int)$sessao['tenant_id'],
        'morador_id' => (int)$sessao['morador_id'],
        'nome' => (string)$sessao['nome'],
        'unidade' => trim((string)$sessao['unidade']),
    ];
}

function portal_convidados_autor(array $auth) {
    return ['tipo' => 'MORADOR', 'morador_id' => $auth['morador_id'], 'nome' => $auth['nome']];
}

/** Origem automática conforme o canal (o cliente só informa o canal, nunca a origem). */
function portal_convidados_origem(mysqli $conexao, array $dados) {
    $canal = strtolower(trim((string)($_GET['canal'] ?? $dados['canal'] ?? '')));
    if ($canal === 'app' && convidados_origem_suportada($conexao, 'APP_MORADOR')) return 'APP_MORADOR';
    return 'PORTAL_MORADOR';
}

/** Visitante só pode ser vinculado se pertencer à unidade do morador (mesmo critério da aba Visitantes). */
function portal_convidados_visitante_da_unidade(mysqli $conexao, array $auth, $visitanteId) {
    $visitanteId = (int)$visitanteId;
    if ($visitanteId <= 0) return null;
    list($cond, $tipos, $params) = pvu_condicao_visitante_unidade('v', $auth['tenant_id'], $auth['morador_id'], $auth['unidade']);
    $stmt = convidados_executar(
        $conexao,
        "SELECT v.id, v.nome_completo, v.tipo_documento, v.documento FROM visitantes v WHERE v.tenant_id = ? AND v.id = ? AND v.ativo = 1 AND {$cond} LIMIT 1",
        'ii' . $tipos,
        array_merge([$auth['tenant_id'], $visitanteId], $params)
    );
    $linha = convidados_linha($stmt);
    $stmt->close();
    return $linha ?: null;
}

/** Prepara o payload do morador: força o anfitrião titular e completa o documento a partir do visitante. */
function portal_convidados_payload(mysqli $conexao, array $auth, array $dados) {
    $dados['morador_id'] = $auth['morador_id'];
    $anfitriao = strtoupper(trim((string)($dados['anfitriao_tipo'] ?? 'MORADOR')));
    if ($anfitriao !== 'DEPENDENTE') {
        $dados['anfitriao_tipo'] = 'MORADOR';
        $dados['dependente_id'] = null;
    }
    unset($dados['origem_cadastro']);
    $visitanteId = isset($dados['visitante_id']) && $dados['visitante_id'] !== '' && $dados['visitante_id'] !== null
        ? (int)$dados['visitante_id'] : 0;
    if ($visitanteId > 0) {
        $visitante = portal_convidados_visitante_da_unidade($conexao, $auth, $visitanteId);
        if (!$visitante) convidados_erro('O visitante selecionado não está vinculado à sua unidade.', 403, 'VISITANTE_FORA_DO_ESCOPO');
        $tipoDoc = strtoupper(trim((string)$visitante['tipo_documento']));
        if (!in_array($tipoDoc, ['CPF', 'RG'], true)) {
            convidados_erro('O cadastro deste visitante não possui CPF ou RG. Informe o documento manualmente.', 422, 'DOCUMENTO_VISITANTE_INVALIDO');
        }
        $dados['visitante_id'] = $visitanteId;
        $dados['tipo_documento'] = $tipoDoc;
        $dados['documento'] = (string)$visitante['documento'];
    } else {
        $dados['visitante_id'] = null;
    }
    return $dados;
}

function portal_convidados_opcoes_entrada(mysqli $conexao, array $auth, array $dados) {
    return [
        'origem_forcada' => portal_convidados_origem($conexao, $dados),
        'validar_visitante_escopo' => function (array $visitante) use ($conexao, $auth) {
            return portal_convidados_visitante_da_unidade($conexao, $auth, (int)$visitante['id']) !== null;
        },
    ];
}

/** Campos expostos ao morador (sem dados internos da portaria além do necessário). */
function portal_convidados_publico(array $linha, array $auth, $documentoCompleto = false) {
    $statusExibicao = (string)($linha['status_exibicao'] ?? $linha['status']);
    $cadastroProprio = ($linha['cadastrado_por_tipo'] ?? '') === 'MORADOR'
        && (int)($linha['cadastrado_por_morador_id'] ?? 0) === (int)$auth['morador_id'];
    $saida = [
        'id' => (int)$linha['id'],
        'nome' => (string)$linha['nome'],
        'tipo_convidado' => (string)$linha['tipo_convidado'],
        'tipo_documento' => (string)$linha['tipo_documento'],
        'documento_mascarado' => (string)$linha['documento_mascarado'],
        'anfitriao_tipo' => (string)$linha['anfitriao_tipo'],
        'dependente_id' => $linha['dependente_id'] === null ? null : (int)$linha['dependente_id'],
        'anfitriao_nome' => $linha['anfitriao']['nome'] ?? null,
        'visitante_id' => $linha['visitante_id'] === null ? null : (int)$linha['visitante_id'],
        'unidade' => (string)$linha['unidade'],
        'data_prevista_chegada' => (string)$linha['data_prevista_chegada'],
        'dias_permanencia' => (int)$linha['dias_permanencia'],
        'validade_fim' => (string)$linha['validade_fim'],
        'modalidade_entradas' => (string)$linha['modalidade_entradas'],
        'limite_entradas' => $linha['limite_entradas'],
        'entradas_utilizadas' => (int)$linha['entradas_utilizadas'],
        'entradas_restantes' => $linha['entradas_restantes'],
        'origem_cadastro' => (string)$linha['origem_cadastro'],
        'status' => (string)$linha['status'],
        'status_exibicao' => $statusExibicao,
        'observacao' => $linha['observacao'],
        'cadastrado_por_tipo' => (string)($linha['cadastrado_por_tipo'] ?? ''),
        'cadastrado_por_nome' => (string)($linha['cadastrado_por_nome'] ?? ''),
        'criado_em' => (string)($linha['criado_em'] ?? ''),
        'cancelado_em' => $linha['cancelado_em'] ?? null,
        'motivo_cancelamento' => $linha['motivo_cancelamento'] ?? null,
        'primeira_entrada_em' => $linha['primeira_entrada_em'] ?? null,
        'ultima_movimentacao_em' => $linha['ultima_movimentacao_em'] ?? null,
        'cadastro_proprio' => $cadastroProprio,
        'pode_editar' => $cadastroProprio && $statusExibicao === 'PREVISTO',
        'pode_cancelar' => $statusExibicao === 'PREVISTO',
    ];
    // O documento completo só retorna para o próprio autor, para preencher a edição.
    if ($documentoCompleto && $cadastroProprio) {
        $saida['documento'] = (string)($linha['documento'] ?? '');
    }
    return $saida;
}

function portal_convidados_opcoes(mysqli $conexao, array $auth) {
    $stmtDep = convidados_executar(
        $conexao,
        'SELECT id, nome_completo FROM dependentes WHERE tenant_id = ? AND morador_id = ? AND ativo = 1 ORDER BY nome_completo ASC',
        'ii',
        [$auth['tenant_id'], $auth['morador_id']]
    );
    $dependentes = [];
    foreach (convidados_todas_linhas($stmtDep) as $d) {
        $dependentes[] = ['id' => (int)$d['id'], 'nome' => (string)$d['nome_completo']];
    }
    $stmtDep->close();

    list($cond, $tipos, $params) = pvu_condicao_visitante_unidade('v', $auth['tenant_id'], $auth['morador_id'], $auth['unidade']);
    $stmtVis = convidados_executar(
        $conexao,
        "SELECT v.id, v.nome_completo, v.tipo_documento, v.documento FROM visitantes v
         WHERE v.tenant_id = ? AND v.ativo = 1 AND {$cond}
         ORDER BY v.nome_completo ASC LIMIT 300",
        'i' . $tipos,
        array_merge([$auth['tenant_id']], $params)
    );
    $visitantes = [];
    foreach (convidados_todas_linhas($stmtVis) as $v) {
        $tipoDoc = strtoupper(trim((string)$v['tipo_documento']));
        if (!in_array($tipoDoc, ['CPF', 'RG'], true)) continue;
        $visitantes[] = [
            'id' => (int)$v['id'],
            'nome' => (string)$v['nome_completo'],
            'tipo_documento' => $tipoDoc,
            'documento_mascarado' => convidados_mascarar_documento($tipoDoc, convidados_normalizar_documento($tipoDoc, $v['documento'])),
        ];
    }
    $stmtVis->close();

    convidados_responder(true, 'Opções carregadas.', [
        'morador' => ['id' => $auth['morador_id'], 'nome' => $auth['nome'], 'unidade' => $auth['unidade']],
        'dependentes' => $dependentes,
        'visitantes' => $visitantes,
        'tipos' => [
            ['valor' => 'VISITANTE', 'rotulo' => 'Visitante'],
            ['valor' => 'PRESTADOR', 'rotulo' => 'Prestador de serviço'],
            ['valor' => 'ENTREGADOR_AUTORIZADO', 'rotulo' => 'Entregador autorizado'],
            ['valor' => 'FAMILIAR', 'rotulo' => 'Familiar (visitante)'],
        ],
        'modalidades' => [
            ['valor' => 'UNICO', 'rotulo' => 'Acesso único', 'limite_entradas' => 1],
            ['valor' => 'MULTIPLO', 'rotulo' => '2 entradas', 'limite_entradas' => 2],
            ['valor' => 'MULTIPLO', 'rotulo' => '4 entradas', 'limite_entradas' => 4],
            ['valor' => 'MULTIPLO', 'rotulo' => '6 entradas', 'limite_entradas' => 6],
            ['valor' => 'MULTIPLO', 'rotulo' => '8 entradas', 'limite_entradas' => 8],
            ['valor' => 'MULTIPLO', 'rotulo' => '10 entradas', 'limite_entradas' => 10],
            ['valor' => 'INDETERMINADO', 'rotulo' => 'Entradas livres no período', 'limite_entradas' => null],
        ],
        'dias_permanencia_max' => 365,
        'data_minima' => convidados_data_hoje(),
        'limite_ativos' => PORTAL_CONVIDADOS_LIMITE_ATIVOS,
    ]);
}

function portal_convidados_listar(mysqli $conexao, array $auth) {
    $filtro = strtolower(trim((string)($_GET['filtro'] ?? 'ativos')));
    if (!in_array($filtro, ['ativos', 'historico', 'todos'], true)) convidados_erro('Filtro inválido.');
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $limite = min(50, max(1, (int)($_GET['limite'] ?? 20)));
    $offset = ($pagina - 1) * $limite;
    $hoje = convidados_data_hoje();

    $where = 'c.tenant_id = ? AND c.morador_id = ?';
    $tipos = 'ii';
    $params = [$auth['tenant_id'], $auth['morador_id']];
    if ($filtro === 'ativos') {
        $where .= " AND c.status IN ('PREVISTO','PRESENTE') AND c.validade_fim >= ?";
        $tipos .= 's';
        $params[] = $hoje;
    } elseif ($filtro === 'historico') {
        $where .= " AND (c.status IN ('FINALIZADO','CANCELADO') OR c.validade_fim < ?)";
        $tipos .= 's';
        $params[] = $hoje;
    }

    $stmtTotal = convidados_executar($conexao, "SELECT COUNT(*) AS total FROM convidados c WHERE {$where}", $tipos, $params);
    $total = (int)(convidados_linha($stmtTotal)['total'] ?? 0);
    $stmtTotal->close();

    $stmtResumo = convidados_executar(
        $conexao,
        "SELECT
            SUM(CASE WHEN c.status = 'PREVISTO' AND c.validade_fim >= ? THEN 1 ELSE 0 END) AS previstos,
            SUM(CASE WHEN c.status = 'PRESENTE' AND c.validade_fim >= ? THEN 1 ELSE 0 END) AS presentes,
            SUM(CASE WHEN c.status IN ('FINALIZADO','CANCELADO') OR c.validade_fim < ? THEN 1 ELSE 0 END) AS historico
         FROM convidados c WHERE c.tenant_id = ? AND c.morador_id = ?",
        'sssii',
        [$hoje, $hoje, $hoje, $auth['tenant_id'], $auth['morador_id']]
    );
    $resumo = convidados_linha($stmtResumo) ?: [];
    $stmtResumo->close();
    foreach (['previstos', 'presentes', 'historico'] as $campo) $resumo[$campo] = (int)($resumo[$campo] ?? 0);

    $ordem = $filtro === 'historico'
        ? 'c.data_prevista_chegada DESC, c.id DESC'
        : "CASE WHEN c.status = 'PRESENTE' THEN 0 ELSE 1 END, c.data_prevista_chegada ASC, c.nome ASC";
    $paramsLista = $params;
    $paramsLista[] = $limite;
    $paramsLista[] = $offset;
    $stmt = convidados_executar(
        $conexao,
        convidados_obter_sql_select(false) . " WHERE {$where} ORDER BY {$ordem} LIMIT ? OFFSET ?",
        $tipos . 'ii',
        $paramsLista
    );
    $convidados = [];
    foreach (convidados_todas_linhas($stmt) as $linha) {
        $convidados[] = portal_convidados_publico(convidados_formatar_linha($linha, $hoje, false), $auth);
    }
    $stmt->close();

    convidados_responder(true, 'Convidados carregados.', [
        'convidados' => $convidados,
        'resumo' => $resumo,
        'filtro' => $filtro,
        'total' => $total,
        'pagina' => $pagina,
        'limite' => $limite,
        'total_paginas' => (int)ceil($total / $limite),
    ]);
}

function portal_convidados_obter(mysqli $conexao, array $auth, $id) {
    $id = (int)$id;
    if ($id <= 0) convidados_erro('ID do convidado inválido.');
    $stmt = convidados_executar(
        $conexao,
        convidados_obter_sql_select(false) . ' WHERE c.tenant_id = ? AND c.morador_id = ? AND c.id = ? LIMIT 1',
        'iii',
        [$auth['tenant_id'], $auth['morador_id'], $id]
    );
    $linha = convidados_linha($stmt);
    $stmt->close();
    if (!$linha) convidados_erro('Convidado não encontrado.', 404, 'CONVIDADO_NAO_ENCONTRADO');
    $detalhe = portal_convidados_publico(convidados_formatar_linha($linha, convidados_data_hoje(), true), $auth, true);
    $detalhe['movimentacoes'] = [];
    if (convidados_coluna_existe($conexao, 'registros_acesso', 'convidado_id')) {
        $stmtMov = convidados_executar(
            $conexao,
            'SELECT data_hora, tipo_acesso FROM registros_acesso WHERE tenant_id = ? AND convidado_id = ? ORDER BY data_hora DESC, id DESC LIMIT 100',
            'ii',
            [$auth['tenant_id'], $id]
        );
        $detalhe['movimentacoes'] = convidados_todas_linhas($stmtMov);
        $stmtMov->close();
    }
    convidados_responder(true, 'Convidado carregado.', $detalhe);
}

function portal_convidados_verificar_alertas(mysqli $conexao, array $auth, array $dados) {
    $dados = portal_convidados_payload($conexao, $auth, $dados);
    $entrada = convidados_dados_entrada($conexao, $auth['tenant_id'], $dados, 'criar', 0, portal_convidados_opcoes_entrada($conexao, $auth, $dados));
    if (!function_exists('alertas_unidade_migration_instalada') || !alertas_unidade_migration_instalada($conexao)) {
        convidados_responder(true, 'Verificação de alertas indisponível.', ['verificacao_disponivel' => false, 'alertas' => []]);
    }
    $alertas = alertas_unidade_avaliar($conexao, $auth['tenant_id'], [
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
        'data_hora' => $entrada['data_prevista_chegada'] . ' 12:00:00',
    ]);
    $resumo = [];
    foreach ($alertas as $alerta) {
        // O morador só vê alertas da própria unidade (alertas de outras unidades por documento não são expostos).
        if (!hash_equals((string)($alerta['unidade'] ?? ''), (string)$entrada['unidade'])) continue;
        $resumo[] = [
            'id' => (int)$alerta['id'],
            'titulo' => (string)$alerta['titulo'],
            'nivel' => (string)$alerta['nivel'],
            'acao' => (string)$alerta['acao'],
        ];
    }
    convidados_responder(true, 'Verificação de alertas concluída.', [
        'verificacao_disponivel' => true,
        'alertas' => $resumo,
        'aviso' => $resumo
            ? 'Existe alerta ativo na sua unidade que pode afetar a entrada deste convidado. A portaria seguirá o alerta no momento da chegada.'
            : '',
    ]);
}

function portal_convidados_criar(mysqli $conexao, array $auth, array $dados) {
    $stmtAtivos = convidados_executar(
        $conexao,
        "SELECT COUNT(*) AS total FROM convidados WHERE tenant_id = ? AND cadastrado_por_tipo = 'MORADOR' AND cadastrado_por_morador_id = ? AND status = 'PREVISTO' AND validade_fim >= ?",
        'iis',
        [$auth['tenant_id'], $auth['morador_id'], convidados_data_hoje()]
    );
    $ativos = (int)(convidados_linha($stmtAtivos)['total'] ?? 0);
    $stmtAtivos->close();
    if ($ativos >= PORTAL_CONVIDADOS_LIMITE_ATIVOS) {
        convidados_erro('Você atingiu o limite de ' . PORTAL_CONVIDADOS_LIMITE_ATIVOS . ' convidados previstos. Cancele convites que não serão usados.', 429, 'LIMITE_CONVIDADOS');
    }
    $dados = portal_convidados_payload($conexao, $auth, $dados);
    $entrada = convidados_dados_entrada($conexao, $auth['tenant_id'], $dados, 'criar', 0, portal_convidados_opcoes_entrada($conexao, $auth, $dados));
    $resultado = convidados_persistir_novo($conexao, $auth['tenant_id'], $entrada, portal_convidados_autor($auth));
    if ($resultado['idempotente']) {
        convidados_responder(true, 'Convidado já havia sido cadastrado.', ['id' => $resultado['id'], 'idempotente' => true]);
    }
    error_log('[PortalConvidados] criado tenant=' . $auth['tenant_id'] . ' id=' . $resultado['id'] . ' morador=' . $auth['morador_id'] . ' origem=' . $entrada['origem_cadastro']);
    convidados_responder(true, 'Convidado cadastrado. A portaria já verá o pré-cadastro na Lista de Convidados.', [
        'id' => $resultado['id'],
        'origem_cadastro' => $entrada['origem_cadastro'],
        'validade_fim' => $entrada['validade_fim'],
    ], 201);
}

function portal_convidados_editar(mysqli $conexao, array $auth, array $dados) {
    $id = (int)($dados['id'] ?? $_GET['id'] ?? 0);
    $dados = portal_convidados_payload($conexao, $auth, $dados);
    convidados_atualizar_existente(
        $conexao,
        $auth['tenant_id'],
        $id,
        $dados,
        portal_convidados_autor($auth),
        portal_convidados_opcoes_entrada($conexao, $auth, $dados),
        ['morador_id' => $auth['morador_id'], 'somente_cadastro_morador' => true]
    );
    error_log('[PortalConvidados] editado tenant=' . $auth['tenant_id'] . ' id=' . $id . ' morador=' . $auth['morador_id']);
    convidados_responder(true, 'Convidado atualizado.', ['id' => $id]);
}

function portal_convidados_cancelar(mysqli $conexao, array $auth, array $dados) {
    $id = (int)($dados['id'] ?? $_GET['id'] ?? 0);
    convidados_cancelar_existente($conexao, $auth['tenant_id'], $id, (string)($dados['motivo'] ?? ''), portal_convidados_autor($auth), ['morador_id' => $auth['morador_id']]);
    error_log('[PortalConvidados] cancelado tenant=' . $auth['tenant_id'] . ' id=' . $id . ' morador=' . $auth['morador_id']);
    convidados_responder(true, 'Convite cancelado.', ['id' => $id, 'status' => 'CANCELADO']);
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$dados = [];
if (in_array($metodo, ['POST', 'PUT'], true)) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) convidados_erro('Envie um JSON válido no corpo da requisição.');
    $dados = $json;
}
$acao = strtolower(trim((string)($_GET['acao'] ?? $dados['acao'] ?? '')));
if ($metodo === 'GET' && $acao === '') $acao = 'listar';

try {
    $conexao = conectar_banco();
    if (!$conexao) convidados_erro('Serviço indisponível. Tente novamente.', 503, 'DB_INDISPONIVEL');
    $conexao->set_charset('utf8mb4');
    $auth = portal_convidados_auth($conexao);

    if ($metodo === 'GET' && $acao === 'opcoes') portal_convidados_opcoes($conexao, $auth);
    if ($metodo === 'GET' && $acao === 'listar') portal_convidados_listar($conexao, $auth);
    if ($metodo === 'GET' && $acao === 'obter') portal_convidados_obter($conexao, $auth, $_GET['id'] ?? 0);
    if ($metodo === 'POST' && $acao === 'verificar_alertas') portal_convidados_verificar_alertas($conexao, $auth, $dados);
    if ($metodo === 'POST' && $acao === 'criar') portal_convidados_criar($conexao, $auth, $dados);
    if (in_array($metodo, ['POST', 'PUT'], true) && $acao === 'editar') portal_convidados_editar($conexao, $auth, $dados);
    if ($metodo === 'POST' && $acao === 'cancelar') portal_convidados_cancelar($conexao, $auth, $dados);
    convidados_erro('Ação ou método não permitido.', 405, 'ACAO_NAO_PERMITIDA');
} catch (Throwable $erro) {
    error_log('[PortalConvidados][Fatal] acao=' . $acao . ' classe=' . get_class($erro) . ' codigo=' . (int)$erro->getCode());
    convidados_responder(false, 'Não foi possível concluir a operação de convidados. Tente novamente.', null, 500);
}
