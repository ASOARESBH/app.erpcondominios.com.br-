<?php
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/helpers/cpf_helper.php';
require_once __DIR__ . '/helpers/visitantes_config_helper.php';
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

function convidados_responder($sucesso, $mensagem, $dados = null, $status = 200) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    $resposta = [
        'sucesso' => (bool)$sucesso,
        'mensagem' => (string)$mensagem,
    ];
    $resposta['dados'] = $dados === null ? [] : $dados;
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function convidados_erro($mensagem, $status = 400, $codigo = null, $dados = null) {
    if ($codigo !== null) {
        if (!is_array($dados)) $dados = [];
        $dados['codigo'] = $codigo;
    }
    convidados_responder(false, $mensagem, $dados, $status);
}

set_exception_handler(function (Throwable $erro) {
    error_log('[Convidados][Uncaught] ' . $erro->getMessage());
    convidados_responder(false, 'Não foi possível concluir a operação de convidados. Tente novamente ou contate o administrador.', null, 500);
});

function convidados_parametros($stmt, $tipos, array &$parametros) {
    if ($tipos === '') return;
    $bind = [$tipos];
    foreach ($parametros as $indice => &$parametro) {
        $bind[] = &$parametro;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function convidados_executar(mysqli $conexao, $sql, $tipos = '', array $parametros = []) {
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta de convidados: ' . $conexao->error, (int)$conexao->errno);
    }
    if ($tipos !== '') {
        convidados_parametros($stmt, $tipos, $parametros);
    }
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $errno = (int)$stmt->errno;
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta de convidados: ' . $erro, $errno);
    }
    return $stmt;
}

function convidados_linha(mysqli_stmt $stmt) {
    $resultado = $stmt->get_result();
    return $resultado ? ($resultado->fetch_assoc() ?: null) : null;
}

function convidados_todas_linhas(mysqli_stmt $stmt) {
    $linhas = [];
    $resultado = $stmt->get_result();
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $linhas[] = $linha;
        }
    }
    return $linhas;
}

function convidados_data_valida($valor) {
    $valor = trim((string)$valor);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) return false;
    $data = DateTime::createFromFormat('!Y-m-d', $valor);
    return $data instanceof DateTime && $data->format('Y-m-d') === $valor;
}

function convidados_data_hoje() {
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

function convidados_normalizar_documento($tipo, $documento) {
    $documento = trim((string)$documento);
    if ($tipo === 'CPF') {
        return cpf_somente_digitos($documento);
    }
    $maiusculo = function_exists('mb_strtoupper') ? mb_strtoupper($documento, 'UTF-8') : strtoupper($documento);
    return preg_replace('/[^A-Z0-9]/', '', $maiusculo);
}

function convidados_mascarar_documento($tipo, $normalizado) {
    $normalizado = (string)$normalizado;
    if ($tipo === 'CPF' && strlen($normalizado) === 11) {
        return '***.' . substr($normalizado, 3, 3) . '.' . substr($normalizado, 6, 3) . '-**';
    }
    if (strlen($normalizado) <= 4) return str_repeat('*', max(3, strlen($normalizado)));
    return str_repeat('*', max(3, strlen($normalizado) - 4)) . substr($normalizado, -4);
}

function convidados_texto($valor, $limite) {
    $valor = trim((string)$valor);
    if (function_exists('mb_substr')) return mb_substr($valor, 0, $limite, 'UTF-8');
    return substr($valor, 0, $limite);
}

function convidados_formatar_linha(array $linha, $dataReferencia, $documentoCompleto = false) {
    $statusBase = (string)($linha['status'] ?? '');
    $validadeFim = (string)($linha['validade_fim'] ?? '');
    $expirado = in_array($statusBase, ['PREVISTO', 'PRESENTE'], true) && $validadeFim < convidados_data_hoje();
    $statusExibicao = $expirado ? 'EXPIRADO' : $statusBase;
    $limite = $linha['limite_entradas'] === null ? null : (int)$linha['limite_entradas'];
    $utilizadas = (int)($linha['entradas_utilizadas'] ?? 0);
    $linha['id'] = (int)$linha['id'];
    $linha['tenant_id'] = (int)$linha['tenant_id'];
    $linha['morador_id'] = (int)$linha['morador_id'];
    $linha['dependente_id'] = $linha['dependente_id'] === null ? null : (int)$linha['dependente_id'];
    $linha['visitante_id'] = $linha['visitante_id'] === null ? null : (int)$linha['visitante_id'];
    $linha['dias_permanencia'] = (int)$linha['dias_permanencia'];
    $linha['limite_entradas'] = $limite;
    $linha['entradas_utilizadas'] = $utilizadas;
    $linha['entradas_restantes'] = $limite === null ? null : max(0, $limite - $utilizadas);
    $linha['status_exibicao'] = $statusExibicao;
    $linha['documento_mascarado'] = convidados_mascarar_documento($linha['tipo_documento'], $linha['documento_normalizado']);
    if (!$documentoCompleto) {
        unset($linha['documento'], $linha['documento_normalizado'], $linha['idempotency_key']);
    }
    $linha['anfitriao'] = [
        'tipo' => $linha['anfitriao_tipo'],
        'morador_id' => (int)$linha['morador_id'],
        'morador_nome' => $linha['morador_nome'] ?? null,
        'dependente_id' => $linha['dependente_id'],
        'dependente_nome' => $linha['dependente_nome'] ?? null,
        'nome' => $linha['anfitriao_tipo'] === 'DEPENDENTE'
            ? ($linha['dependente_nome'] ?? $linha['morador_nome'] ?? null)
            : ($linha['morador_nome'] ?? null),
    ];
    $linha['autoria'] = [
        'tipo' => $linha['cadastrado_por_tipo'] ?? null,
        'id' => $linha['cadastrado_por_usuario_id'] !== null
            ? (int)$linha['cadastrado_por_usuario_id']
            : ($linha['cadastrado_por_morador_id'] !== null ? (int)$linha['cadastrado_por_morador_id'] : null),
        'nome' => $linha['cadastrado_por_nome'] ?? null,
        'em' => $linha['criado_em'] ?? null,
    ];
    return $linha;
}

function convidados_coluna_existe(mysqli $conexao, $tabela, $coluna) {
    $stmt = convidados_executar(
        $conexao,
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        'ss',
        [$tabela, $coluna]
    );
    $existe = convidados_linha($stmt) !== null;
    $stmt->close();
    return $existe;
}

function convidados_dados_entrada(mysqli $conexao, $tenantId, array $dados, $modo, $idAtual = 0) {
    $tipoDocumento = strtoupper(trim((string)($dados['tipo_documento'] ?? '')));
    if (!in_array($tipoDocumento, ['CPF', 'RG'], true)) {
        convidados_erro('Selecione um tipo de documento válido.');
    }
    $documento = trim((string)($dados['documento'] ?? ''));
    if ($documento === '' || strlen($documento) > 20) {
        convidados_erro('Informe um documento válido de até 20 caracteres.');
    }
    if ($tipoDocumento === 'CPF' && !cpf_valido($documento)) {
        convidados_erro('O CPF informado é inválido. Confira os dígitos verificadores.');
    }
    $documentoNormalizado = convidados_normalizar_documento($tipoDocumento, $documento);
    if ($documentoNormalizado === '' || strlen($documentoNormalizado) > 20) {
        convidados_erro('O documento informado é inválido.');
    }

    $moradorId = (int)($dados['morador_id'] ?? 0);
    if ($moradorId <= 0) convidados_erro('Selecione o morador anfitrião.');
    $stmtMorador = convidados_executar(
        $conexao,
        'SELECT id, nome, unidade FROM moradores WHERE tenant_id = ? AND id = ? AND ativo = 1 LIMIT 1',
        'ii',
        [$tenantId, $moradorId]
    );
    $morador = convidados_linha($stmtMorador);
    $stmtMorador->close();
    if (!$morador) convidados_erro('O morador anfitrião não foi encontrado ou está inativo neste condomínio.');
    $unidade = trim((string)$morador['unidade']);
    if ($unidade === '') convidados_erro('O morador anfitrião não possui unidade cadastrada.');

    $anfitriaoTipo = strtoupper(trim((string)($dados['anfitriao_tipo'] ?? 'MORADOR')));
    if (!in_array($anfitriaoTipo, ['MORADOR', 'DEPENDENTE'], true)) {
        convidados_erro('Selecione se o convidado é do morador ou de um dependente.');
    }
    $dependenteId = null;
    $dependenteNome = null;
    if ($anfitriaoTipo === 'DEPENDENTE') {
        $dependenteId = (int)($dados['dependente_id'] ?? 0);
        if ($dependenteId <= 0) convidados_erro('Selecione o dependente anfitrião.');
        $stmtDependente = convidados_executar(
            $conexao,
            'SELECT id, nome_completo FROM dependentes WHERE tenant_id = ? AND morador_id = ? AND id = ? AND ativo = 1 LIMIT 1',
            'iii',
            [$tenantId, $moradorId, $dependenteId]
        );
        $dependente = convidados_linha($stmtDependente);
        $stmtDependente->close();
        if (!$dependente) convidados_erro('O dependente não pertence ao morador selecionado ou está inativo.');
        $dependenteNome = $dependente['nome_completo'];
    }

    $visitanteId = isset($dados['visitante_id']) && $dados['visitante_id'] !== '' ? (int)$dados['visitante_id'] : null;
    $visitanteNome = null;
    if ($visitanteId !== null) {
        if ($visitanteId <= 0) convidados_erro('O cadastro de visitante informado é inválido.');
        $stmtVisitante = convidados_executar(
            $conexao,
            'SELECT id, nome_completo, documento, tipo_documento FROM visitantes WHERE tenant_id = ? AND id = ? AND ativo = 1 LIMIT 1',
            'ii',
            [$tenantId, $visitanteId]
        );
        $visitante = convidados_linha($stmtVisitante);
        $stmtVisitante->close();
        if (!$visitante) convidados_erro('O cadastro de visitante não foi encontrado neste condomínio.');
        $docVisitante = convidados_normalizar_documento(strtoupper((string)$visitante['tipo_documento']), $visitante['documento']);
        if (strtoupper((string)$visitante['tipo_documento']) !== $tipoDocumento || $docVisitante !== $documentoNormalizado) {
            convidados_erro('O documento não corresponde ao cadastro de visitante selecionado.');
        }
        $visitanteNome = trim((string)$visitante['nome_completo']);
    }

    if ($tipoDocumento === 'CPF') {
        $stmtMoradorCpf = convidados_executar(
            $conexao,
            "SELECT id FROM moradores WHERE tenant_id = ? AND ativo = 1 AND REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ? LIMIT 1",
            'is',
            [$tenantId, $documentoNormalizado]
        );
        $moradorCpf = convidados_linha($stmtMoradorCpf);
        $stmtMoradorCpf->close();
        if ($moradorCpf) convidados_erro('O CPF informado pertence a um morador ativo e não pode ser cadastrado como convidado.');
    }

    $nome = $visitanteNome !== null ? $visitanteNome : trim((string)($dados['nome'] ?? ''));
    if ($nome === '') convidados_erro('Informe o nome completo do convidado.');
    if (strlen($nome) > 200) convidados_erro('O nome do convidado deve ter até 200 caracteres.');

    $tipoConvidado = strtoupper(trim((string)($dados['tipo_convidado'] ?? '')));
    $tiposConvidado = ['VISITANTE', 'PRESTADOR', 'ENTREGADOR_AUTORIZADO', 'FAMILIAR'];
    if (!in_array($tipoConvidado, $tiposConvidado, true)) convidados_erro('Selecione um tipo de convidado válido.');

    $dataChegada = trim((string)($dados['data_prevista_chegada'] ?? ''));
    if (!convidados_data_valida($dataChegada)) convidados_erro('A data prevista de chegada é inválida.');
    if ($modo === 'criar' && $dataChegada < convidados_data_hoje()) convidados_erro('A data prevista de chegada não pode estar no passado.');
    if ($modo === 'editar' && $dataChegada < convidados_data_hoje()) convidados_erro('A edição não pode definir uma data passada.');

    $diasPermanencia = (int)($dados['dias_permanencia'] ?? 0);
    if ($diasPermanencia < 1 || $diasPermanencia > 365) convidados_erro('Os dias de permanência devem estar entre 1 e 365.');
    $validade = new DateTime($dataChegada);
    $validade->modify('+' . ($diasPermanencia - 1) . ' days');
    $validadeFim = $validade->format('Y-m-d');

    $modalidade = strtoupper(trim((string)($dados['modalidade_entradas'] ?? '')));
    if (!in_array($modalidade, ['UNICO', 'MULTIPLO', 'INDETERMINADO'], true)) convidados_erro('Selecione uma modalidade de entradas válida.');
    $limite = null;
    if ($modalidade === 'UNICO') {
        $limite = 1;
    } elseif ($modalidade === 'MULTIPLO') {
        $limiteRecebido = (int)($dados['limite_entradas'] ?? 0);
        if (!in_array($limiteRecebido, [2, 4, 6, 8, 10], true)) convidados_erro('O limite de entradas deve ser 2, 4, 6, 8 ou 10.');
        $limite = $limiteRecebido;
    } elseif (isset($dados['limite_entradas']) && $dados['limite_entradas'] !== '' && $dados['limite_entradas'] !== null) {
        convidados_erro('A modalidade indeterminada não possui limite de entradas.');
    }

    $origem = strtoupper(trim((string)($dados['origem_cadastro'] ?? '')));
    if (!in_array($origem, ['EMAIL', 'WHATSAPP', 'PRESENCIAL', 'ADMINISTRATIVO'], true)) {
        convidados_erro('Selecione uma origem de cadastro válida para o painel.');
    }
    $observacao = trim((string)($dados['observacao'] ?? ''));
    if (strlen($observacao) > 500) convidados_erro('A observação deve ter até 500 caracteres.');

    return [
        'tenant_id' => (int)$tenantId,
        'unidade' => $unidade,
        'morador_id' => $moradorId,
        'morador_nome' => $morador['nome'],
        'anfitriao_tipo' => $anfitriaoTipo,
        'dependente_id' => $dependenteId,
        'dependente_nome' => $dependenteNome,
        'visitante_id' => $visitanteId,
        'tipo_documento' => $tipoDocumento,
        'documento' => convidados_texto($documento, 20),
        'documento_normalizado' => $documentoNormalizado,
        'nome' => convidados_texto($nome, 200),
        'tipo_convidado' => $tipoConvidado,
        'data_prevista_chegada' => $dataChegada,
        'dias_permanencia' => $diasPermanencia,
        'validade_fim' => $validadeFim,
        'modalidade_entradas' => $modalidade,
        'limite_entradas' => $limite,
        'origem_cadastro' => $origem,
        'observacao' => $observacao === '' ? null : convidados_texto($observacao, 500),
        'idempotency_key' => trim((string)($dados['idempotency_key'] ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''))),
    ];
}

function convidados_validar_chave_idempotencia($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') return null;
    if (strlen($valor) > 36) convidados_erro('A chave de idempotência deve ter até 36 caracteres.');
    return $valor;
}

function convidados_idempotencia_igual(array $existente, array $entrada) {
    $campos = [
        'unidade', 'morador_id', 'anfitriao_tipo', 'dependente_id', 'visitante_id',
        'tipo_documento', 'documento_normalizado', 'nome', 'tipo_convidado',
        'data_prevista_chegada', 'dias_permanencia', 'validade_fim',
        'modalidade_entradas', 'limite_entradas', 'origem_cadastro', 'observacao',
    ];
    foreach ($campos as $campo) {
        $a = $existente[$campo] ?? null;
        $b = $entrada[$campo] ?? null;
        if ((string)$a !== (string)$b) return false;
    }
    return true;
}

function convidados_sql_base($data, array &$tipos, array &$parametros) {
    $tipos = 'sssi';
    $parametros = [$data, $data, $data, 0];
    return "c.data_prevista_chegada <= ?
        AND (c.validade_fim >= ? OR (c.status IN ('PREVISTO','PRESENTE') AND c.validade_fim < CURDATE() AND ? = CURDATE()))
        AND c.tenant_id = ?";
}

function convidados_adicionar_filtro(&$where, &$tipos, &$parametros, $campo, $tipo, $valor) {
    $where .= ' AND ' . $campo . ' = ?';
    $tipos .= $tipo;
    $parametros[] = $valor;
}

function convidados_obter_sql_select($comUltimaMovimentacao = false) {
    $ultimaMovimentacao = $comUltimaMovimentacao
        ? '(SELECT ra.tipo_acesso FROM registros_acesso ra WHERE ra.tenant_id = c.tenant_id AND ra.convidado_id = c.id ORDER BY ra.data_hora DESC, ra.id DESC LIMIT 1)'
        : 'NULL';
    return "SELECT c.*, m.nome AS morador_nome, d.nome_completo AS dependente_nome
        , {$ultimaMovimentacao} AS ultima_tipo_acesso
        FROM convidados c
        LEFT JOIN moradores m ON m.tenant_id = c.tenant_id AND m.id = c.morador_id
        LEFT JOIN dependentes d ON d.tenant_id = c.tenant_id AND d.id = c.dependente_id";
}

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
        SUM(CASE WHEN c.status = 'CANCELADO' THEN 1 ELSE 0 END) AS cancelados
        FROM convidados c WHERE {$base}{$filtrosResumo}";
    $stmtResumo = convidados_executar($conexao, $sqlResumo, $tiposResumo, $paramsResumo);
    $resumo = convidados_linha($stmtResumo) ?: [];
    $stmtResumo->close();
    foreach (['total', 'previstos', 'presentes', 'finalizados', 'expirados', 'cancelados'] as $campo) {
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

function convidados_criar(mysqli $conexao, $auth, $tenantId, array $dados) {
    $entrada = convidados_dados_entrada($conexao, $tenantId, $dados, 'criar');
    $entrada['idempotency_key'] = convidados_validar_chave_idempotencia($entrada['idempotency_key']);
    $usuarioId = (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0);
    $usuarioNome = convidados_texto($auth['nome'] ?? $_SESSION['usuario_nome'] ?? 'Usuário autenticado', 150);
    if ($usuarioId <= 0 || $usuarioNome === '') convidados_erro('Não foi possível identificar o usuário da sessão.', 401, 'AUTH_REQUIRED');

    $conexao->begin_transaction();
    try {
        if ($entrada['idempotency_key'] !== null) {
            $stmtIdem = convidados_executar(
                $conexao,
                'SELECT * FROM convidados WHERE tenant_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE',
                'is',
                [$tenantId, $entrada['idempotency_key']]
            );
            $existente = convidados_linha($stmtIdem);
            $stmtIdem->close();
            if ($existente) {
                $conexao->commit();
                if (!convidados_idempotencia_igual($existente, $entrada)) {
                    convidados_erro('A chave de idempotência já foi usada para outro convidado. Gere uma nova chave.', 409, 'IDEMPOTENCY_CONFLICT');
                }
                convidados_responder(true, 'Convidado já havia sido cadastrado.', [
                    'id' => (int)$existente['id'],
                    'idempotente' => true,
                ]);
            }
        }
        $stmtDuplicidade = convidados_executar(
            $conexao,
            "SELECT id FROM convidados
             WHERE tenant_id = ? AND morador_id = ? AND documento_normalizado = ?
               AND status IN ('PREVISTO','PRESENTE')
               AND data_prevista_chegada <= ? AND validade_fim >= ?
             LIMIT 1 FOR UPDATE",
            'iisss',
            [$tenantId, $entrada['morador_id'], $entrada['documento_normalizado'], $entrada['validade_fim'], $entrada['data_prevista_chegada']]
        );
        $duplicado = convidados_linha($stmtDuplicidade);
        $stmtDuplicidade->close();
        if ($duplicado) {
            $conexao->rollback();
            convidados_erro('Já existe um convidado com este documento e este anfitrião em período sobreposto.', 409, 'CONVIDADO_DUPLICADO', ['id' => (int)$duplicado['id']]);
        }

        $sql = 'INSERT INTO convidados (tenant_id, unidade, morador_id, anfitriao_tipo, dependente_id, visitante_id, tipo_documento, documento, documento_normalizado, nome, tipo_convidado, data_prevista_chegada, dias_permanencia, validade_fim, modalidade_entradas, limite_entradas, entradas_utilizadas, origem_cadastro, status, observacao, cadastrado_por_tipo, cadastrado_por_usuario_id, cadastrado_por_morador_id, cadastrado_por_dependente_id, cadastrado_por_nome, atualizado_por_tipo, atualizado_por_id, atualizado_por_nome, idempotency_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, \'PREVISTO\', ?, \'USUARIO\', ?, NULL, NULL, ?, \'USUARIO\', ?, ?, ?)';
        $params = [
            $tenantId, $entrada['unidade'], $entrada['morador_id'], $entrada['anfitriao_tipo'], $entrada['dependente_id'],
            $entrada['visitante_id'], $entrada['tipo_documento'], $entrada['documento'], $entrada['documento_normalizado'],
            $entrada['nome'], $entrada['tipo_convidado'], $entrada['data_prevista_chegada'], $entrada['dias_permanencia'],
            $entrada['validade_fim'], $entrada['modalidade_entradas'], $entrada['limite_entradas'], $entrada['origem_cadastro'],
            $entrada['observacao'], $usuarioId, $usuarioNome, $usuarioId, $usuarioNome, $entrada['idempotency_key'],
        ];
        $stmt = convidados_executar($conexao, $sql, 'isisiissssssississisiss', $params);
        $id = (int)$conexao->insert_id;
        $stmt->close();
        $conexao->commit();
    } catch (Throwable $erro) {
        $conexao->rollback();
        if ((int)$erro->getCode() === 1062) {
            convidados_erro('Este convidado já foi cadastrado. Verifique a lista antes de tentar novamente.', 409, 'CONVIDADO_DUPLICADO');
        }
        throw $erro;
    }
    error_log('[Convidados] criado tenant=' . (int)$tenantId . ' id=' . $id . ' usuario=' . $usuarioId);
    if (rbacTabelasDisponiveis($conexao)) {
        rbacAuditar($conexao, [
            'tenant_id' => $tenantId, 'usuario_id' => $usuarioId, 'modulo_chave' => 'registro',
            'submodulo_chave' => 'convidados', 'acao' => 'CRIAR', 'registro_tipo' => 'convidado',
            'registro_id' => $id, 'dados_depois' => ['morador_id' => $entrada['morador_id'], 'tipo_convidado' => $entrada['tipo_convidado']],
            'resultado' => 'SUCESSO', 'status_http' => 201,
        ]);
    }
    convidados_responder(true, 'Convidado cadastrado com sucesso.', ['id' => $id], 201);
}

function convidados_editar(mysqli $conexao, $auth, $tenantId, array $dados) {
    $id = (int)($dados['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) convidados_erro('ID do convidado inválido.');
    $usuarioId = (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0);
    $usuarioNome = convidados_texto($auth['nome'] ?? $_SESSION['usuario_nome'] ?? 'Usuário autenticado', 150);
    $conexao->begin_transaction();
    try {
        $stmtAtual = convidados_executar($conexao, 'SELECT * FROM convidados WHERE tenant_id = ? AND id = ? LIMIT 1 FOR UPDATE', 'ii', [$tenantId, $id]);
        $atual = convidados_linha($stmtAtual);
        $stmtAtual->close();
        if (!$atual) {
            $conexao->rollback();
            convidados_erro('Convidado não encontrado neste condomínio.', 404, 'CONVIDADO_NAO_ENCONTRADO');
        }
        if ($atual['status'] !== 'PREVISTO') {
            $conexao->rollback();
            convidados_erro('Somente convidados com status PREVISTO podem ser editados.', 409, 'STATUS_NAO_EDITAVEL');
        }
        $entrada = convidados_dados_entrada($conexao, $tenantId, $dados, 'editar', $id);
        $stmtDuplicidade = convidados_executar(
            $conexao,
            "SELECT id FROM convidados WHERE tenant_id = ? AND morador_id = ? AND documento_normalizado = ? AND id <> ?
                AND status IN ('PREVISTO','PRESENTE') AND data_prevista_chegada <= ? AND validade_fim >= ? LIMIT 1 FOR UPDATE",
            'iisiss',
            [$tenantId, $entrada['morador_id'], $entrada['documento_normalizado'], $id, $entrada['validade_fim'], $entrada['data_prevista_chegada']]
        );
        $duplicado = convidados_linha($stmtDuplicidade);
        $stmtDuplicidade->close();
        if ($duplicado) {
            $conexao->rollback();
            convidados_erro('Já existe outro convidado com este documento e este anfitrião em período sobreposto.', 409, 'CONVIDADO_DUPLICADO', ['id' => (int)$duplicado['id']]);
        }
        $sql = 'UPDATE convidados SET unidade=?, morador_id=?, anfitriao_tipo=?, dependente_id=?, visitante_id=?, tipo_documento=?, documento=?, documento_normalizado=?, nome=?, tipo_convidado=?, data_prevista_chegada=?, dias_permanencia=?, validade_fim=?, modalidade_entradas=?, limite_entradas=?, origem_cadastro=?, observacao=?, atualizado_por_tipo=\'USUARIO\', atualizado_por_id=?, atualizado_por_nome=? WHERE tenant_id=? AND id=? AND status=\'PREVISTO\'';
        convidados_executar($conexao, $sql, 'sisiissssssississisii', [
            $entrada['unidade'], $entrada['morador_id'], $entrada['anfitriao_tipo'], $entrada['dependente_id'], $entrada['visitante_id'],
            $entrada['tipo_documento'], $entrada['documento'], $entrada['documento_normalizado'], $entrada['nome'], $entrada['tipo_convidado'],
            $entrada['data_prevista_chegada'], $entrada['dias_permanencia'], $entrada['validade_fim'], $entrada['modalidade_entradas'], $entrada['limite_entradas'],
            $entrada['origem_cadastro'], $entrada['observacao'], $usuarioId, $usuarioNome, $tenantId, $id,
        ])->close();
        $conexao->commit();
    } catch (Throwable $erro) {
        $conexao->rollback();
        throw $erro;
    }
    error_log('[Convidados] editado tenant=' . (int)$tenantId . ' id=' . $id . ' usuario=' . $usuarioId);
    if (rbacTabelasDisponiveis($conexao)) rbacAuditar($conexao, [
        'tenant_id' => $tenantId, 'usuario_id' => $usuarioId, 'modulo_chave' => 'registro', 'submodulo_chave' => 'convidados',
        'acao' => 'EDITAR', 'registro_tipo' => 'convidado', 'registro_id' => $id, 'resultado' => 'SUCESSO', 'status_http' => 200,
    ]);
    convidados_responder(true, 'Convidado atualizado com sucesso.', ['id' => $id]);
}

function convidados_cancelar(mysqli $conexao, $auth, $tenantId, array $dados) {
    $id = (int)($dados['id'] ?? $_GET['id'] ?? 0);
    $motivo = trim((string)($dados['motivo'] ?? ''));
    if ($id <= 0) convidados_erro('ID do convidado inválido.');
    if ($motivo === '') convidados_erro('Informe o motivo do cancelamento.');
    if (strlen($motivo) > 255) convidados_erro('O motivo do cancelamento deve ter até 255 caracteres.');
    $usuarioId = (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0);
    $usuarioNome = convidados_texto($auth['nome'] ?? $_SESSION['usuario_nome'] ?? 'Usuário autenticado', 150);
    $stmt = convidados_executar(
        $conexao,
        "UPDATE convidados SET status='CANCELADO', cancelado_por_nome=?, cancelado_em=NOW(), motivo_cancelamento=?, atualizado_por_tipo='USUARIO', atualizado_por_id=?, atualizado_por_nome=? WHERE tenant_id=? AND id=? AND status='PREVISTO'",
        'ssisii',
        [$usuarioNome, convidados_texto($motivo, 255), $usuarioId, $usuarioNome, $tenantId, $id]
    );
    $afetadas = $stmt->affected_rows;
    $stmt->close();
    if ($afetadas < 1) convidados_erro('Somente convites ainda previstos podem ser cancelados. Registre a saída de quem já está presente.', 409, 'STATUS_NAO_CANCELAVEL');
    error_log('[Convidados] cancelado tenant=' . (int)$tenantId . ' id=' . $id . ' usuario=' . $usuarioId);
    if (rbacTabelasDisponiveis($conexao)) rbacAuditar($conexao, [
        'tenant_id' => $tenantId, 'usuario_id' => $usuarioId, 'modulo_chave' => 'registro', 'submodulo_chave' => 'convidados',
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
    if ($metodo === 'POST' && $acao === 'criar') $acaoRbac = 'criar';
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
    if ($metodo === 'POST' && $acao === 'criar') convidados_criar($conexao, $auth, $tenantId, $dados);
    if ($metodo === 'PUT' && $acao === 'editar') convidados_editar($conexao, $auth, $tenantId, $dados);
    if ($metodo === 'POST' && $acao === 'cancelar') convidados_cancelar($conexao, $auth, $tenantId, $dados);
    convidados_erro('Ação ou método não permitido para a Lista de Convidados.', 405, 'ACAO_NAO_PERMITIDA');
} catch (Throwable $erro) {
    error_log('[Convidados][Fatal] tenant=' . (int)($tenantId ?? 0) . ' acao=' . $acao . ' erro=' . $erro->getMessage());
    convidados_responder(false, 'Não foi possível concluir a operação de convidados. Tente novamente ou contate o administrador.', null, 500);
}
