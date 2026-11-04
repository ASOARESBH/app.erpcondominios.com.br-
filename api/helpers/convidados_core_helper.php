<?php
/**
 * ERP CONDOMÍNIOS — Núcleo da Lista de Convidados.
 *
 * Regras compartilhadas entre:
 *   - api/api_convidados.php          (painel: Registro > aba Convidados, sessão de operador)
 *   - api/api_portal_convidados.php   (pré-cadastro do morador: Portal do Morador e aplicativo)
 *
 * Nenhuma regra de validação, duplicidade, idempotência ou persistência deve ser
 * duplicada nos endpoints: altere aqui para manter painel, portal e app coerentes.
 * Todas as consultas usam prepared statements e tenant_id resolvido no servidor.
 */

require_once __DIR__ . '/cpf_helper.php';

if (!defined('CONVIDADOS_ORIGENS_PAINEL')) {
    define('CONVIDADOS_ORIGENS_PAINEL', ['EMAIL', 'WHATSAPP', 'PRESENCIAL', 'ADMINISTRATIVO']);
    /** Origens geradas automaticamente pelo pré-cadastro do morador. */
    define('CONVIDADOS_ORIGENS_MORADOR', ['PORTAL_MORADOR', 'APP_MORADOR']);
    define('CONVIDADOS_ORIGENS_TODAS', ['EMAIL', 'WHATSAPP', 'PRESENCIAL', 'ADMINISTRATIVO', 'PORTAL_MORADOR', 'APP_MORADOR']);
}

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

/**
 * Valida e normaliza o formulário de convidado. Única fonte das regras de negócio
 * para o painel (Registro > Convidados) e para o pré-cadastro do morador
 * (Portal do Morador e aplicativo).
 *
 * $opcoes:
 *  - origem_forcada (string|null): origem definida pelo servidor (ex.: PORTAL_MORADOR);
 *    quando presente, o valor do cliente é ignorado.
 *  - origens_permitidas (string[]): origens aceitas do cliente (padrão: painel).
 *  - validar_visitante_escopo (callable|null): fn(array $visitante): bool, usada para
 *    restringir visitantes vinculados (ex.: somente da unidade do morador).
 */
function convidados_dados_entrada(mysqli $conexao, $tenantId, array $dados, $modo, $idAtual = 0, array $opcoes = []) {
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
        if (isset($opcoes['validar_visitante_escopo']) && is_callable($opcoes['validar_visitante_escopo'])
            && !call_user_func($opcoes['validar_visitante_escopo'], $visitante)) {
            convidados_erro('O visitante selecionado não está vinculado à sua unidade.', 403, 'VISITANTE_FORA_DO_ESCOPO');
        }
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

    if (isset($opcoes['origem_forcada']) && $opcoes['origem_forcada'] !== null && $opcoes['origem_forcada'] !== '') {
        $origem = strtoupper((string)$opcoes['origem_forcada']);
        if (!in_array($origem, CONVIDADOS_ORIGENS_TODAS, true)) {
            throw new InvalidArgumentException('Origem forçada inválida para convidados.');
        }
    } else {
        $origem = strtoupper(trim((string)($dados['origem_cadastro'] ?? '')));
        $origensPermitidas = isset($opcoes['origens_permitidas']) && is_array($opcoes['origens_permitidas'])
            ? $opcoes['origens_permitidas']
            : CONVIDADOS_ORIGENS_PAINEL;
        if (!in_array($origem, $origensPermitidas, true)) {
            convidados_erro('Selecione uma origem de cadastro válida para o painel.');
        }
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

/**
 * Condição base do período. $tipos é a string de tipos do bind_param (não um array):
 * a assinatura anterior declarava `array &$tipos` e lançava TypeError ao listar.
 */
function convidados_sql_base($data, &$tipos, array &$parametros) {
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


/**
 * Indica se o ENUM convidados.origem_cadastro já aceita o valor informado.
 * Permite publicar o código antes da migration APP_MORADOR sem quebrar o app:
 * sem a migration, o pré-cadastro do app é gravado como PORTAL_MORADOR.
 */
function convidados_origem_suportada(mysqli $conexao, $origem) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = convidados_executar(
            $conexao,
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convidados' AND COLUMN_NAME = 'origem_cadastro' LIMIT 1"
        );
        $linha = convidados_linha($stmt);
        $stmt->close();
        if ($linha && preg_match_all("/'([^']+)'/", (string)$linha['COLUMN_TYPE'], $m)) {
            $cache = $m[1];
        }
    }
    return in_array((string)$origem, $cache, true);
}

/**
 * Valida o autor da operação. tipo USUARIO exige usuario_id; MORADOR exige morador_id.
 */
function convidados_normalizar_autor(array $autor) {
    $tipo = strtoupper((string)($autor['tipo'] ?? ''));
    $nome = convidados_texto($autor['nome'] ?? '', 150);
    $usuarioId = (int)($autor['usuario_id'] ?? 0);
    $moradorId = (int)($autor['morador_id'] ?? 0);
    if ($tipo === 'USUARIO' && $usuarioId > 0 && $nome !== '') {
        return ['tipo' => 'USUARIO', 'usuario_id' => $usuarioId, 'morador_id' => null, 'id' => $usuarioId, 'nome' => $nome];
    }
    if ($tipo === 'MORADOR' && $moradorId > 0 && $nome !== '') {
        return ['tipo' => 'MORADOR', 'usuario_id' => null, 'morador_id' => $moradorId, 'id' => $moradorId, 'nome' => $nome];
    }
    convidados_erro('Não foi possível identificar o autor da operação.', 401, 'AUTH_REQUIRED');
}

/**
 * Persiste um novo convite (transação + idempotência + duplicidade sobreposta).
 * Retorna ['id' => int, 'idempotente' => bool]. Erros de negócio encerram a requisição.
 */
function convidados_persistir_novo(mysqli $conexao, $tenantId, array $entrada, array $autor) {
    $autor = convidados_normalizar_autor($autor);
    $entrada['idempotency_key'] = convidados_validar_chave_idempotencia($entrada['idempotency_key'] ?? '');

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
                return ['id' => (int)$existente['id'], 'idempotente' => true];
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

        $sql = 'INSERT INTO convidados (tenant_id, unidade, morador_id, anfitriao_tipo, dependente_id, visitante_id, tipo_documento, documento, documento_normalizado, nome, tipo_convidado, data_prevista_chegada, dias_permanencia, validade_fim, modalidade_entradas, limite_entradas, entradas_utilizadas, origem_cadastro, status, observacao, cadastrado_por_tipo, cadastrado_por_usuario_id, cadastrado_por_morador_id, cadastrado_por_dependente_id, cadastrado_por_nome, atualizado_por_tipo, atualizado_por_id, atualizado_por_nome, idempotency_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, \'PREVISTO\', ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)';
        $params = [
            $tenantId, $entrada['unidade'], $entrada['morador_id'], $entrada['anfitriao_tipo'], $entrada['dependente_id'],
            $entrada['visitante_id'], $entrada['tipo_documento'], $entrada['documento'], $entrada['documento_normalizado'],
            $entrada['nome'], $entrada['tipo_convidado'], $entrada['data_prevista_chegada'], $entrada['dias_permanencia'],
            $entrada['validade_fim'], $entrada['modalidade_entradas'], $entrada['limite_entradas'], $entrada['origem_cadastro'],
            $entrada['observacao'], $autor['tipo'], $autor['usuario_id'], $autor['morador_id'], $autor['nome'],
            $autor['tipo'], $autor['id'], $autor['nome'], $entrada['idempotency_key'],
        ];
        $stmt = convidados_executar($conexao, $sql, 'isisiissssssississsiississ', $params);
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
    return ['id' => $id, 'idempotente' => false];
}

/**
 * Atualiza um convite PREVISTO.
 * $restricoes:
 *  - morador_id (int): exige que o convite pertença a este morador (escopo do portal);
 *  - somente_cadastro_morador (bool): só permite editar convites cadastrados pelo próprio morador.
 * A origem de convites pré-cadastrados pelo morador é sempre preservada.
 */
function convidados_atualizar_existente(mysqli $conexao, $tenantId, $id, array $dados, array $autor, array $opcoesEntrada = [], array $restricoes = []) {
    $autor = convidados_normalizar_autor($autor);
    if ((int)$id <= 0) convidados_erro('ID do convidado inválido.');
    $conexao->begin_transaction();
    try {
        $stmtAtual = convidados_executar($conexao, 'SELECT * FROM convidados WHERE tenant_id = ? AND id = ? LIMIT 1 FOR UPDATE', 'ii', [$tenantId, $id]);
        $atual = convidados_linha($stmtAtual);
        $stmtAtual->close();
        if (!$atual || (isset($restricoes['morador_id']) && (int)$atual['morador_id'] !== (int)$restricoes['morador_id'])) {
            $conexao->rollback();
            convidados_erro('Convidado não encontrado neste condomínio.', 404, 'CONVIDADO_NAO_ENCONTRADO');
        }
        if (!empty($restricoes['somente_cadastro_morador'])
            && ($atual['cadastrado_por_tipo'] !== 'MORADOR' || (int)$atual['cadastrado_por_morador_id'] !== (int)$restricoes['morador_id'])) {
            $conexao->rollback();
            convidados_erro('Este convidado foi cadastrado pela portaria. Para alterá-lo, cancele e faça um novo pré-cadastro ou contate a portaria.', 403, 'EDICAO_NAO_PERMITIDA');
        }
        if ($atual['status'] !== 'PREVISTO') {
            $conexao->rollback();
            convidados_erro('Somente convidados com status PREVISTO podem ser editados.', 409, 'STATUS_NAO_EDITAVEL');
        }
        if (in_array((string)$atual['origem_cadastro'], CONVIDADOS_ORIGENS_MORADOR, true)) {
            $opcoesEntrada['origem_forcada'] = (string)$atual['origem_cadastro'];
        }
        $entrada = convidados_dados_entrada($conexao, $tenantId, $dados, 'editar', $id, $opcoesEntrada);
        if (isset($restricoes['morador_id']) && (int)$entrada['morador_id'] !== (int)$restricoes['morador_id']) {
            $conexao->rollback();
            convidados_erro('O anfitrião deve ser o morador autenticado ou um dependente dele.', 403, 'ANFITRIAO_FORA_DO_ESCOPO');
        }
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
        $sql = 'UPDATE convidados SET unidade=?, morador_id=?, anfitriao_tipo=?, dependente_id=?, visitante_id=?, tipo_documento=?, documento=?, documento_normalizado=?, nome=?, tipo_convidado=?, data_prevista_chegada=?, dias_permanencia=?, validade_fim=?, modalidade_entradas=?, limite_entradas=?, origem_cadastro=?, observacao=?, atualizado_por_tipo=?, atualizado_por_id=?, atualizado_por_nome=? WHERE tenant_id=? AND id=? AND status=\'PREVISTO\'';
        convidados_executar($conexao, $sql, 'sisiissssssississsisii', [
            $entrada['unidade'], $entrada['morador_id'], $entrada['anfitriao_tipo'], $entrada['dependente_id'], $entrada['visitante_id'],
            $entrada['tipo_documento'], $entrada['documento'], $entrada['documento_normalizado'], $entrada['nome'], $entrada['tipo_convidado'],
            $entrada['data_prevista_chegada'], $entrada['dias_permanencia'], $entrada['validade_fim'], $entrada['modalidade_entradas'], $entrada['limite_entradas'],
            $entrada['origem_cadastro'], $entrada['observacao'], $autor['tipo'], $autor['id'], $autor['nome'], $tenantId, $id,
        ])->close();
        $conexao->commit();
    } catch (Throwable $erro) {
        $conexao->rollback();
        throw $erro;
    }
    return (int)$id;
}

/**
 * Cancela um convite PREVISTO. $restricoes['morador_id'] limita ao anfitrião autenticado.
 */
function convidados_cancelar_existente(mysqli $conexao, $tenantId, $id, $motivo, array $autor, array $restricoes = []) {
    $autor = convidados_normalizar_autor($autor);
    $motivo = trim((string)$motivo);
    if ((int)$id <= 0) convidados_erro('ID do convidado inválido.');
    if ($motivo === '') convidados_erro('Informe o motivo do cancelamento.');
    if (strlen($motivo) > 255) convidados_erro('O motivo do cancelamento deve ter até 255 caracteres.');
    $sql = "UPDATE convidados SET status='CANCELADO', cancelado_por_nome=?, cancelado_em=NOW(), motivo_cancelamento=?, atualizado_por_tipo=?, atualizado_por_id=?, atualizado_por_nome=? WHERE tenant_id=? AND id=? AND status='PREVISTO'";
    $tipos = 'sssisii';
    $params = [$autor['nome'], convidados_texto($motivo, 255), $autor['tipo'], $autor['id'], $autor['nome'], $tenantId, (int)$id];
    if (isset($restricoes['morador_id'])) {
        $sql .= ' AND morador_id=?';
        $tipos .= 'i';
        $params[] = (int)$restricoes['morador_id'];
    }
    $stmt = convidados_executar($conexao, $sql, $tipos, $params);
    $afetadas = $stmt->affected_rows;
    $stmt->close();
    if ($afetadas < 1) {
        convidados_erro('Somente convites ainda previstos podem ser cancelados. Registre a saída de quem já está presente.', 409, 'STATUS_NAO_CANCELAVEL');
    }
    return (int)$id;
}
