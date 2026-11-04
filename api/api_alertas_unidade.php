<?php
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/rbac_helper.php';
require_once __DIR__ . '/helpers/alertas_unidade_helper.php';
ob_end_clean();

date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$origem = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (preg_match('/^https?:\/\/([a-z0-9-]+\.)?erpcondominios\.com\.br$/i', $origem) || preg_match('/^https?:\/\/localhost(:\d+)?$/i', $origem)) {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Idempotency-Key');

function au_api_responder($sucesso, $mensagem, $dados = [], $status = 200, $codigo = null) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    $resposta = ['sucesso' => (bool)$sucesso, 'mensagem' => (string)$mensagem, 'dados' => $dados === null ? [] : $dados];
    if ($codigo !== null) $resposta['codigo'] = $codigo;
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function au_api_erro($mensagem, $status = 422, $codigo = null, $dados = []) {
    au_api_responder(false, $mensagem, $dados, $status, $codigo);
}
set_exception_handler(function (Throwable $erro) {
    $detalhe = preg_replace('/[\r\n\t]+/', ' ', (string)$erro->getMessage());
    error_log('[AlertasUnidade] ' . get_class($erro) . ' código=' . (int)$erro->getCode() . ': ' . substr($detalhe, 0, 500));
    au_api_responder(false, 'Não foi possível concluir a operação de alertas. Tente novamente.', [], 500, 'INTERNAL_ERROR');
});
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function au_api_texto($valor, $limite) {
    $valor = trim(strip_tags((string)$valor));
    return function_exists('mb_substr') ? mb_substr($valor, 0, $limite, 'UTF-8') : substr($valor, 0, $limite);
}
function au_api_json($valor) {
    return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function au_api_array($valor) {
    return is_array($valor) ? $valor : [];
}
function au_api_usuario_id($auth) {
    return (int)($auth['id'] ?? $_SESSION['usuario_id'] ?? 0);
}
function au_api_usuario_nome($auth) {
    return au_api_texto($auth['nome'] ?? $_SESSION['usuario_nome'] ?? 'Usuário autenticado', 150);
}
function au_api_rbac(mysqli $c, $tenantId, $acao, array $contexto = []) {
    if (!function_exists('rbacTabelasDisponiveis') || !rbacTabelasDisponiveis($c)) return true;
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    if (in_array($acao, ['verificar', 'registrar_ciencia'], true)) {
        $permitido = rbacPode($c, 'registro', 'criar', $uid, $tenantId) || rbacPode($c, 'acesso', 'executar', $uid, $tenantId);
    } else {
        $permitido = rbacPode($c, 'moradores', $acao, $uid, $tenantId);
    }
    if (!$permitido) {
        if (function_exists('rbacAuditar')) {
            rbacAuditar($c, [
                'tenant_id' => $tenantId,
                'usuario_id' => $uid,
                'modulo_chave' => 'moradores',
                'submodulo_chave' => $contexto['submodulo'] ?? null,
                'acao' => 'ACESSO_NEGADO',
                'resultado' => 'NEGADO',
                'status_http' => 403,
                'motivo' => 'Permissão necessária: moradores.' . $acao,
            ]);
        }
        au_api_erro('Acesso não autorizado para esta operação.', 403, 'RBAC_DENIED');
    }
}
function au_api_data($valor, $obrigatoria = false) {
    $valor = trim((string)$valor);
    if ($valor === '' && !$obrigatoria) return null;
    $data = au_now($valor);
    if (!$data) au_api_erro('Informe uma data e hora válidas.', 422, 'DATA_INVALIDA');
    return $data->format('Y-m-d H:i:s');
}
function au_api_hora($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') return null;
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $valor)) {
        au_api_erro('A janela de horário é inválida.', 422, 'HORA_INVALIDA');
    }
    return strlen($valor) === 5 ? $valor . ':00' : $valor;
}
function au_api_dias($valor) {
    if ($valor === null || $valor === '') return null;
    $dias = au_dias_validos($valor);
    $partes = is_array($valor) ? $valor : preg_split('/\s*,\s*/', trim((string)$valor), -1, PREG_SPLIT_NO_EMPTY);
    if (!$dias || count($dias) !== count($partes)) au_api_erro('Os dias da semana devem estar entre 1 e 7.', 422, 'DIAS_INVALIDOS');
    return implode(',', $dias);
}
function au_api_status_exibicao(array $alerta) {
    if (($alerta['status'] ?? '') === 'ATIVO' && !empty($alerta['vigencia_fim']) && (string)$alerta['vigencia_fim'] < date('Y-m-d H:i:s')) return 'EXPIRADO';
    return (string)($alerta['status'] ?? '');
}
function au_api_snapshot(array $alerta) {
    $campos = ['id','tenant_id','unidade','titular_tipo','morador_id','dependente_id','titulo','mensagem','nivel','acao','publico','abrangencia','vigencia_inicio','vigencia_fim','dias_semana','hora_inicio','hora_fim','instrucao_operador','telefone_contato','status','origem','criado_por_tipo','criado_por_usuario_id','criado_por_morador_id','total_acionamentos','ultimo_acionamento_em'];
    $saida = [];
    foreach ($campos as $campo) if (array_key_exists($campo, $alerta)) $saida[$campo] = $alerta[$campo];
    if (isset($saida['dias_semana'])) $saida['dias_semana'] = au_dias_validos($saida['dias_semana']);
    return $saida;
}
function au_api_alvo_saida(array $alvo, bool $incluirDocumentoEdicao = false) {
    $saida = [
        'id' => (int)$alvo['id'],
        'alerta_id' => (int)$alvo['alerta_id'],
        'tipo_alvo' => (string)$alvo['tipo_alvo'],
        'visitante_id' => $alvo['visitante_id'] === null ? null : (int)$alvo['visitante_id'],
        'tipo_documento' => $alvo['tipo_documento'] ?? null,
        'documento_mascarado' => !empty($alvo['documento_normalizado']) ? alertas_unidade_mascarar_documento($alvo['documento_normalizado'], $alvo['tipo_documento'] ?? '') : null,
        'placa' => $alvo['placa_normalizada'] ?? null,
        'nome_referencia' => $alvo['nome_referencia'] ?? null,
    ];
    if ($incluirDocumentoEdicao && ($alvo['tipo_alvo'] ?? '') === 'DOCUMENTO') $saida['documento_edicao'] = $alvo['documento_normalizado'] ?? null;
    return $saida;
}
function au_api_buscar_morador(mysqli $c, $tenantId, $id) {
    $stmt = au_query($c, 'SELECT id,nome,unidade,ativo FROM moradores WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, (int)$id]);
    $morador = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$morador) au_api_erro('O morador titular não foi encontrado neste condomínio.', 422, 'MORADOR_INVALIDO');
    return $morador;
}
function au_api_validar_titular(mysqli $c, $tenantId, array $dados) {
    $morador = au_api_buscar_morador($c, $tenantId, (int)($dados['morador_id'] ?? 0));
    $unidadeInformada = trim((string)($dados['unidade'] ?? ''));
    if ($unidadeInformada !== '' && strcasecmp($unidadeInformada, trim((string)$morador['unidade'])) !== 0) au_api_erro('A unidade não corresponde ao cadastro oficial do morador.', 422, 'UNIDADE_DIVERGENTE');
    $titularTipo = strtoupper(trim((string)($dados['titular_tipo'] ?? 'MORADOR')));
    if (!in_array($titularTipo, ['MORADOR', 'DEPENDENTE'], true)) au_api_erro('O tipo de titular é inválido.', 422, 'TITULAR_INVALIDO');
    $dependenteId = null;
    if ($titularTipo === 'DEPENDENTE') {
        $dependenteId = (int)($dados['dependente_id'] ?? 0);
        $stmt = au_query($c, 'SELECT id FROM dependentes WHERE tenant_id=? AND id=? AND morador_id=? AND ativo=1 LIMIT 1', 'iii', [$tenantId, $dependenteId, (int)$morador['id']]);
        $dependente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$dependente) au_api_erro('O dependente não pertence ao morador selecionado ou está inativo.', 422, 'DEPENDENTE_INVALIDO');
    } elseif (!empty($dados['dependente_id'])) {
        au_api_erro('Não informe dependente quando o titular é o morador.', 422, 'TITULAR_INVALIDO');
    }
    return ['morador' => $morador, 'titular_tipo' => $titularTipo, 'dependente_id' => $dependenteId, 'unidade' => (string)$morador['unidade']];
}
function au_api_validar_alvos(mysqli $c, $tenantId, $abrangencia, array $alvos) {
    if ($abrangencia === 'TODOS_DO_TIPO') return [];
    if ($abrangencia !== 'ESPECIFICO' || !$alvos) au_api_erro('A abrangência específica exige pelo menos um alvo.', 422, 'ALVOS_OBRIGATORIOS');
    $resultado = [];
    foreach ($alvos as $alvo) {
        if (!is_array($alvo)) continue;
        $tipo = strtoupper(trim((string)($alvo['tipo_alvo'] ?? $alvo['tipo'] ?? '')));
        if (!in_array($tipo, ['VISITANTE', 'DOCUMENTO', 'PLACA'], true)) au_api_erro('O tipo de alvo é inválido.', 422, 'ALVO_INVALIDO');
        $visitanteId = null;
        $tipoDocumento = null;
        $documento = null;
        $placa = null;
        if ($tipo === 'VISITANTE') {
            $visitanteId = (int)($alvo['visitante_id'] ?? $alvo['id'] ?? 0);
            $stmt = au_query($c, 'SELECT id,nome_completo,documento,tipo_documento FROM visitantes WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $visitanteId]);
            $visitante = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$visitante) au_api_erro('O visitante selecionado não pertence a este condomínio.', 422, 'ALVO_INVALIDO');
            $tipoDocumento = strtoupper((string)$visitante['tipo_documento']);
            $documento = alertas_unidade_normalizar_documento($visitante['documento'], $tipoDocumento);
        } elseif ($tipo === 'DOCUMENTO') {
            $tipoDocumento = strtoupper(trim((string)($alvo['tipo_documento'] ?? 'OUTRO')));
            if (!in_array($tipoDocumento, ['CPF', 'RG', 'OUTRO'], true)) $tipoDocumento = 'OUTRO';
            $documento = alertas_unidade_normalizar_documento($alvo['documento'] ?? '', $tipoDocumento);
            if ($documento === '' || strlen($documento) > 20) au_api_erro('Informe um documento válido para o alvo.', 422, 'ALVO_INVALIDO');
        } else {
            $placa = alertas_unidade_normalizar_placa($alvo['placa'] ?? '');
            if ($placa === '' || strlen($placa) > 10) au_api_erro('Informe uma placa válida para o alvo.', 422, 'ALVO_INVALIDO');
        }
        $resultado[] = [
            'tipo_alvo' => $tipo,
            'visitante_id' => $visitanteId,
            'tipo_documento' => $tipoDocumento,
            'documento_normalizado' => $documento,
            'placa_normalizada' => $placa,
            'nome_referencia' => au_api_texto($alvo['nome_referencia'] ?? $alvo['nome'] ?? '', 150) ?: null,
        ];
    }
    if (!$resultado) au_api_erro('Adicione pelo menos um alvo específico.', 422, 'ALVOS_OBRIGATORIOS');
    return $resultado;
}
function au_api_normalizar_entrada(mysqli $c, $tenantId, array $dados, $origem = 'SISTEMA') {
    $titular = au_api_validar_titular($c, $tenantId, $dados);
    $titulo = au_api_texto($dados['titulo'] ?? '', 120);
    $mensagem = au_api_texto($dados['mensagem'] ?? '', 1000);
    if ($titulo === '' || $mensagem === '') au_api_erro('Informe título e mensagem do alerta.', 422, 'CAMPOS_OBRIGATORIOS');
    $nivel = strtoupper(trim((string)($dados['nivel'] ?? '')));
    $acao = strtoupper(trim((string)($dados['acao'] ?? '')));
    $publico = strtoupper(trim((string)($dados['publico'] ?? '')));
    $abrangencia = strtoupper(trim((string)($dados['abrangencia'] ?? 'TODOS_DO_TIPO')));
    if (!in_array($nivel, ['INFORMATIVO', 'ATENCAO', 'CRITICO'], true) || !in_array($acao, ['APENAS_AVISAR', 'EXIGIR_CIENTE', 'CONTATAR_MORADOR', 'BLOQUEAR'], true)) au_api_erro('Nível ou ação de alerta inválido.', 422, 'ENUM_INVALIDO');
    if (!in_array($publico, ['TODOS', 'VISITANTE', 'PRESTADOR', 'ENTREGADOR_AUTORIZADO', 'FAMILIAR', 'VEICULO'], true) || !in_array($abrangencia, ['TODOS_DO_TIPO', 'ESPECIFICO'], true)) au_api_erro('Público ou abrangência inválida.', 422, 'ENUM_INVALIDO');
    if ($acao === 'APENAS_AVISAR' && $nivel !== 'INFORMATIVO') au_api_erro('A ação Apenas avisar só pode ser usada com nível Informativo.', 422, 'COMBINACAO_INVALIDA');
    if ($acao === 'BLOQUEAR' && $nivel !== 'CRITICO') au_api_erro('A ação Bloquear só pode ser usada com nível Crítico.', 422, 'COMBINACAO_INVALIDA');
    $inicio = au_api_data($dados['vigencia_inicio'] ?? '', true);
    $fim = au_api_data($dados['vigencia_fim'] ?? '', false);
    if ($fim !== null && $fim < $inicio) au_api_erro('A vigência final deve ser posterior à inicial.', 422, 'VIGENCIA_INVALIDA');
    if ($origem !== 'SISTEMA' && $fim === null) au_api_erro('Alertas de moradores precisam de vigência final.', 422, 'VIGENCIA_OBRIGATORIA');
    if ($origem !== 'SISTEMA' && $fim !== null && strtotime($fim) > strtotime($inicio . ' +365 days')) au_api_erro('A vigência do alerta deve ser de no máximo 365 dias.', 422, 'VIGENCIA_EXCEDIDA');
    $dias = au_api_dias($dados['dias_semana'] ?? null);
    $horaInicio = au_api_hora($dados['hora_inicio'] ?? null);
    $horaFim = au_api_hora($dados['hora_fim'] ?? null);
    if (($horaInicio === null) !== ($horaFim === null)) au_api_erro('Informe início e fim da janela de horário.', 422, 'JANELA_INVALIDA');
    if ($publico === 'VEICULO' && $abrangencia === 'TODOS_DO_TIPO') au_api_erro('Veículo exige abrangência específica por placa.', 422, 'COMBINACAO_INVALIDA');
    $alvos = au_api_validar_alvos($c, $tenantId, $abrangencia, au_api_array($dados['alvos'] ?? []));
    return [
        'unidade' => $titular['unidade'], 'titular_tipo' => $titular['titular_tipo'],
        'morador_id' => (int)$titular['morador']['id'], 'dependente_id' => $titular['dependente_id'],
        'titulo' => $titulo, 'mensagem' => $mensagem, 'nivel' => $nivel, 'acao' => $acao,
        'publico' => $publico, 'abrangencia' => $abrangencia, 'vigencia_inicio' => $inicio,
        'vigencia_fim' => $fim, 'dias_semana' => $dias, 'hora_inicio' => $horaInicio, 'hora_fim' => $horaFim,
        'instrucao_operador' => au_api_texto($dados['instrucao_operador'] ?? '', 500) ?: null,
        'telefone_contato' => au_api_texto($dados['telefone_contato'] ?? '', 30) ?: null,
        'status' => 'ATIVO', 'origem' => $origem, 'alvos' => $alvos,
        'idempotency_key' => au_api_texto($dados['idempotency_key'] ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''), 64) ?: null,
    ];
}
function au_api_contexto(mysqli $c, $tenantId, array $dados, $contextoPadrao = 'REGISTRO_MANUAL') {
    $tipo = alertas_unidade_mapear_tipo($dados['tipo_pessoa'] ?? $dados['tipo'] ?? '');
    $contexto = strtoupper(trim((string)($dados['contexto'] ?? $contextoPadrao)));
    if (!in_array($contexto, ['REGISTRO_MANUAL', 'CONTROLE_ACESSO', 'RFID', 'CONVIDADOS', 'LPR'], true)) au_api_erro('Contexto de acesso inválido.', 422, 'CONTEXTO_INVALIDO');
    $moradorId = (int)($dados['morador_id'] ?? 0);
    $unidade = au_api_texto($dados['unidade'] ?? '', 50);
    if ($moradorId > 0) {
        $morador = au_api_buscar_morador($c, $tenantId, $moradorId);
        $unidade = (string)$morador['unidade'];
    }
    $dependenteId = (int)($dados['dependente_id'] ?? 0);
    if ($dependenteId > 0) {
        $stmt = au_query($c, 'SELECT id,morador_id FROM dependentes WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $dependenteId]);
        $dependente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$dependente || ($moradorId > 0 && (int)$dependente['morador_id'] !== $moradorId)) au_api_erro('O dependente não pertence ao condomínio atual.', 422, 'DEPENDENTE_INVALIDO');
        if ($moradorId <= 0) {
            $moradorId = (int)$dependente['morador_id'];
            $morador = au_api_buscar_morador($c, $tenantId, $moradorId);
            $unidade = (string)$morador['unidade'];
        }
    }
    $visitanteId = (int)($dados['visitante_id'] ?? 0);
    $pessoaNome = '';
    if ($visitanteId > 0) {
        $stmt = au_query($c, 'SELECT id,nome_completo FROM visitantes WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $visitanteId]);
        $visitante = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$visitante) au_api_erro('O visitante não pertence a este condomínio.', 422, 'VISITANTE_INVALIDO');
        $pessoaNome = (string)$visitante['nome_completo'];
    }
    $data = au_api_data($dados['data_hora'] ?? '', false) ?: date('Y-m-d H:i:s');
    return [
        'contexto' => $contexto, 'unidade' => $unidade, 'morador_id' => $moradorId,
        'dependente_id' => $dependenteId, 'tipo_pessoa' => $tipo ?: au_api_texto($dados['tipo_pessoa'] ?? '', 50),
        'visitante_id' => $visitanteId, 'documento' => $dados['documento'] ?? '',
        'documento_normalizado' => alertas_unidade_normalizar_documento($dados['documento'] ?? '', $dados['tipo_documento'] ?? ''),
        'tipo_documento' => strtoupper(au_api_texto($dados['tipo_documento'] ?? '', 10)),
        'placa' => alertas_unidade_normalizar_placa($dados['placa'] ?? ''),
        'tipo_acesso' => trim((string)($dados['tipo_acesso'] ?? 'Entrada')), 'data_hora' => $data,
        'pessoa_nome' => $pessoaNome ?: au_api_texto($dados['pessoa_nome'] ?? $dados['nome_visitante'] ?? '', 200),
    ];
}
function au_api_validar_visitante_contexto(mysqli $c, int $tenantId, array &$ctx) {
    $visitanteId = (int)($ctx['visitante_id'] ?? 0);
    if ($visitanteId <= 0) au_api_erro('Identifique o visitante antes de consultar alertas específicos.', 422, 'VISITANTE_OBRIGATORIO');
    $stmt = au_query($c, 'SELECT id,tipo_documento,documento FROM visitantes WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $visitanteId]);
    $visitante = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$visitante) au_api_erro('O visitante não pertence a este condomínio.', 422, 'VISITANTE_INVALIDO');
    $documentoCanonico = alertas_unidade_normalizar_documento($visitante['documento'] ?? '', $visitante['tipo_documento'] ?? '');
    $documentoRecebido = (string)($ctx['documento_normalizado'] ?? '');
    $tipoRecebido = strtoupper(trim((string)($ctx['tipo_documento'] ?? '')));
    $tipoCanonico = strtoupper(trim((string)($visitante['tipo_documento'] ?? '')));
    if ($documentoCanonico === '') au_api_erro('O visitante não possui documento válido para esta consulta.', 422, 'DOCUMENTO_VISITANTE_INVALIDO');
    if (($documentoRecebido !== '' && !hash_equals($documentoCanonico, $documentoRecebido))
        || ($tipoRecebido !== '' && $tipoRecebido !== $tipoCanonico)) {
        au_api_erro('O documento informado não corresponde ao visitante selecionado.', 422, 'DOCUMENTO_DIVERGENTE');
    }
    $ctx['documento'] = (string)$visitante['documento'];
    $ctx['documento_normalizado'] = $documentoCanonico;
    $ctx['tipo_documento'] = $tipoCanonico;
}
function au_api_contar_especificos_pendentes(mysqli $c, int $tenantId, array $ctx, string $etapa): int {
    if ($etapa === 'VEICULO') return 0;
    $unidade = trim((string)($ctx['unidade'] ?? ''));
    $tipo = alertas_unidade_mapear_tipo($ctx['tipo_pessoa'] ?? '');
    $data = au_now($ctx['data_hora'] ?? null);
    if ($tenantId <= 0 || $unidade === '' || !$tipo || !$data) return 0;
    $dataSql = $data->format('Y-m-d H:i:s');
    $tiposVisitante = ['VISITANTE', 'PRESTADOR', 'ENTREGADOR_AUTORIZADO', 'FAMILIAR'];
    $tiposAlvo = $etapa === 'DESTINO' && in_array($tipo, $tiposVisitante, true)
        ? "'VISITANTE','DOCUMENTO','PLACA'"
        : "'PLACA'";
    $identificados = [];
    if ($etapa === 'IDENTIFICACAO') {
        foreach (alertas_unidade_avaliar($c, $tenantId, $ctx) as $alerta) {
            $tipoAlvo = strtoupper((string)($alerta['tipo_alvo_correspondente'] ?? ''));
            if ((string)($alerta['abrangencia'] ?? '') === 'ESPECIFICO'
                && in_array($tipoAlvo, ['VISITANTE', 'DOCUMENTO'], true)) {
                $id = (int)($alerta['id'] ?? 0);
                if ($id > 0) $identificados[$id] = $id;
            }
        }
    }
    $exclusaoSql = '';
    $types = 'issss';
    $params = [$tenantId, $unidade, $dataSql, $dataSql, $tipo];
    if ($identificados) {
        $exclusaoSql = ' AND a.id NOT IN (' . implode(',', array_fill(0, count($identificados), '?')) . ')';
        $types .= str_repeat('i', count($identificados));
        $params = array_merge($params, array_values($identificados));
    }
    $stmt = au_query($c, "SELECT a.id,a.dias_semana,a.hora_inicio,a.hora_fim FROM alertas_unidade a
        WHERE a.tenant_id=? AND a.unidade=? AND a.status='ATIVO' AND a.abrangencia='ESPECIFICO'
          AND a.vigencia_inicio<=? AND (a.vigencia_fim IS NULL OR a.vigencia_fim>=?)
          AND a.publico IN ('TODOS',?,'VEICULO')
          AND EXISTS (SELECT 1 FROM alertas_unidade_alvos x WHERE x.tenant_id=a.tenant_id AND x.alerta_id=a.id AND x.tipo_alvo IN ($tiposAlvo))
          $exclusaoSql ORDER BY a.id ASC", $types, $params);
    $quantidade = 0;
    foreach (au_rows($stmt) as $alerta) if (au_janela_valida($alerta, $data)) $quantidade++;
    $stmt->close();
    return $quantidade;
}
function au_api_rate_limit(mysqli $c, $tenantId, $uid, $unidade) {
    $unidade = au_api_texto($unidade, 50);
    if ($unidade === '') return;
    $bucket = date('Y-m-d H:i:00', floor(time() / 60) * 60);
    $stmt = au_query($c, 'INSERT INTO alertas_unidade_rate_limits (tenant_id,usuario_id,unidade,bucket_inicio,contador) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE contador=contador+1,atualizado_em=NOW()', 'iiss', [$tenantId, $uid, $unidade, $bucket]);
    $stmt->close();
    $stmt = au_query($c, 'SELECT contador FROM alertas_unidade_rate_limits WHERE tenant_id=? AND usuario_id=? AND unidade=? AND bucket_inicio=? LIMIT 1', 'iiss', [$tenantId, $uid, $unidade, $bucket]);
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($linha['contador'] ?? 0) > 60) au_api_erro('Limite temporário de verificações atingido. Aguarde um minuto.', 429, 'RATE_LIMIT');
}
function au_api_decisoes_ids(array $decisoes) {
    $ids = [];
    foreach ($decisoes as $decisao) $ids[(int)($decisao['alerta_id'] ?? 0)] = true;
    return array_keys($ids);
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = [];
if ($metodo === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) au_api_erro('Envie um JSON válido.', 400, 'JSON_INVALIDO');
}
$acao = strtolower(trim((string)($_GET['acao'] ?? $body['acao'] ?? '')));
if ($acao === '') $acao = $metodo === 'GET' ? 'listar' : '';
if (!in_array($metodo, ['GET', 'POST'], true)) au_api_erro('Método não permitido.', 405, 'METHOD_NOT_ALLOWED');
$auth = verificarAutenticacao(true, 'operador');
$tenantId = (int)exigirTenantId();
$conexao = conectar_banco();
$conexao->set_charset('utf8mb4');
if (!alertas_unidade_migration_instalada($conexao)) au_api_erro('A estrutura de Alertas da Unidade ainda não foi instalada. Aplique a migration do módulo antes de usar esta função.', 503, 'MIGRATION_ALERTAS_PENDENTE');
$uid = au_api_usuario_id($auth);
$uname = au_api_usuario_nome($auth);

if ($acao === 'listar') {
    au_api_rbac($conexao, $tenantId, 'visualizar');
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $porPagina = min(100, max(1, (int)($_GET['por_pagina'] ?? $_GET['pageSize'] ?? 25)));
    $offset = ($pagina - 1) * $porPagina;
    $where = ['a.tenant_id=?'];
    $types = 'i';
    $params = [$tenantId];
    foreach (['unidade' => 'a.unidade', 'nivel' => 'a.nivel', 'publico' => 'a.publico', 'origem' => 'a.origem'] as $chave => $coluna) {
        $valor = au_api_texto($_GET[$chave] ?? '', 50);
        if ($valor !== '') { $where[] = $coluna . '=?'; $types .= 's'; $params[] = strtoupper($valor); }
    }
    $status = strtoupper(au_api_texto($_GET['status'] ?? '', 30));
    if ($status !== '') {
        if ($status === 'EXPIRADO') $where[] = "a.status='ATIVO' AND a.vigencia_fim IS NOT NULL AND a.vigencia_fim < NOW()";
        else { $where[] = 'a.status=?'; $types .= 's'; $params[] = $status; }
    }
    $busca = au_api_texto($_GET['busca'] ?? '', 100);
    if ($busca !== '') {
        $like = '%' . $busca . '%';
        $where[] = '(a.titulo LIKE ? OR a.unidade LIKE ? OR m.nome LIKE ? OR EXISTS (SELECT 1 FROM alertas_unidade_alvos sx WHERE sx.tenant_id=a.tenant_id AND sx.alerta_id=a.id AND (sx.nome_referencia LIKE ? OR sx.placa_normalizada LIKE ?)))';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, '%' . alertas_unidade_normalizar_placa($busca) . '%');
    }
    foreach (['data_inicio' => '>=', 'data_fim' => '<='] as $chave => $operador) {
        $valor = trim((string)($_GET[$chave] ?? ''));
        if ($valor !== '') { $where[] = 'a.vigencia_inicio' . $operador . '?'; $types .= 's'; $params[] = au_api_data($valor, true); }
    }
    $whereSql = implode(' AND ', $where);
    $stmt = au_query($conexao, "SELECT COUNT(*) total FROM alertas_unidade a LEFT JOIN moradores m ON m.tenant_id=a.tenant_id AND m.id=a.morador_id WHERE $whereSql", $types, $params);
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$porPagina, $offset]);
    $stmt = au_query($conexao, "SELECT a.*,m.nome morador_nome,d.nome_completo dependente_nome FROM alertas_unidade a LEFT JOIN moradores m ON m.tenant_id=a.tenant_id AND m.id=a.morador_id LEFT JOIN dependentes d ON d.tenant_id=a.tenant_id AND d.id=a.dependente_id WHERE $whereSql ORDER BY CASE a.nivel WHEN 'CRITICO' THEN 1 WHEN 'ATENCAO' THEN 2 ELSE 3 END,a.vigencia_inicio DESC,a.id DESC LIMIT ? OFFSET ?", $listTypes, $listParams);
    $itens = [];
    foreach (au_rows($stmt) as $item) {
        $item['id'] = (int)$item['id'];
        $item['morador_id'] = (int)$item['morador_id'];
        $item['dependente_id'] = $item['dependente_id'] === null ? null : (int)$item['dependente_id'];
        $item['status'] = au_api_status_exibicao($item);
        $item['dias_semana'] = au_dias_validos($item['dias_semana'] ?? '');
        unset($item['idempotency_payload_hash']);
        $itens[] = $item;
    }
    $stmt->close();
    au_api_responder(true, 'Alertas listados com sucesso.', ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina]);
}

if ($acao === 'kpis') {
    au_api_rbac($conexao, $tenantId, 'visualizar');
    $stmt = au_query($conexao, "SELECT COUNT(*) total,SUM(status='ATIVO' AND (vigencia_fim IS NULL OR vigencia_fim>=NOW())) ativos,SUM(status='ATIVO' AND nivel='CRITICO' AND (vigencia_fim IS NULL OR vigencia_fim>=NOW())) criticos,SUM(status='PAUSADO') pausados,SUM(status='ATIVO' AND acao<>'APENAS_AVISAR' AND (vigencia_fim IS NULL OR vigencia_fim>=NOW())) pendentes_ciencia FROM alertas_unidade WHERE tenant_id=?", 'i', [$tenantId]);
    $kpis = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach (['total','ativos','criticos','pausados','pendentes_ciencia'] as $campo) $kpis[$campo] = (int)($kpis[$campo] ?? 0);
    $stmt = au_query($conexao, "SELECT COUNT(*) total FROM alertas_unidade WHERE tenant_id=? AND status='ATIVO' AND vigencia_fim>=NOW() AND vigencia_fim<=DATE_ADD(NOW(),INTERVAL 7 DAY)", 'i', [$tenantId]);
    $kpis['expiram_7_dias'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $stmt = au_query($conexao, 'SELECT COUNT(*) total FROM alertas_unidade_acionamentos WHERE tenant_id=? AND criado_em>=CURDATE() AND criado_em<DATE_ADD(CURDATE(),INTERVAL 1 DAY)', 'i', [$tenantId]);
    $kpis['acionamentos_hoje'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $stmt = au_query($conexao, "SELECT COUNT(*) total FROM alertas_unidade_ciencias WHERE tenant_id=? AND criado_em>=DATE_FORMAT(NOW(),'%Y-%m-01 00:00:00') AND decisao='OVERRIDE_SUPERVISOR'", 'i', [$tenantId]);
    $kpis['overrides_mes'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    au_api_responder(true, 'KPIs carregados.', $kpis);
}

if ($acao === 'opcoes') {
    au_api_rbac($conexao, $tenantId, 'visualizar');
    $stmt = au_query($conexao, 'SELECT DISTINCT unidade FROM moradores WHERE tenant_id=? AND ativo=1 AND unidade IS NOT NULL AND unidade<>? ORDER BY unidade', 'is', [$tenantId, '']);
    $unidades = [];
    foreach (au_rows($stmt) as $item) {
        $unidade = trim((string)$item['unidade']);
        if ($unidade !== '' && !isset($unidades[strtoupper($unidade)])) $unidades[strtoupper($unidade)] = $unidade;
    }
    $stmt->close();
    // Ordenação natural (Gleba 2 antes de Gleba 10), sem duplicar unidades que só diferem por espaço/caixa.
    $unidades = array_values($unidades);
    usort($unidades, function ($a, $b) { return strnatcasecmp($a, $b); });
    $stmt = au_query($conexao, 'SELECT id,nome,unidade FROM moradores WHERE tenant_id=? AND ativo=1 ORDER BY nome', 'i', [$tenantId]);
    $moradores = au_rows($stmt); $stmt->close();
    $stmt = au_query($conexao, 'SELECT d.id,d.morador_id,d.nome_completo,m.nome morador_nome FROM dependentes d INNER JOIN moradores m ON m.tenant_id=d.tenant_id AND m.id=d.morador_id WHERE d.tenant_id=? AND d.ativo=1 AND m.ativo=1 ORDER BY d.nome_completo', 'i', [$tenantId]);
    $dependentes = au_rows($stmt); $stmt->close();
    $permissoes = [];
    foreach (['visualizar','criar','editar','excluir','exportar','aprovar'] as $permissao) $permissoes[$permissao] = function_exists('rbacPode') ? rbacPode($conexao, 'moradores', $permissao, $uid, $tenantId) : true;
    au_api_responder(true, 'Opções carregadas.', ['unidades'=>$unidades, 'moradores'=>$moradores, 'dependentes'=>$dependentes, 'permissoes'=>$permissoes]);
}

if ($acao === 'buscar_alvo') {
    au_api_rbac($conexao, $tenantId, 'visualizar');
    $busca = au_api_texto($_GET['q'] ?? '', 100);
    if (function_exists('mb_strlen') ? mb_strlen($busca, 'UTF-8') < 2 : strlen($busca) < 2) au_api_responder(true, 'Informe ao menos dois caracteres.', ['itens'=>[]]);
    $like = '%' . $busca . '%';
    $docLike = '%' . alertas_unidade_normalizar_documento($busca, 'OUTRO') . '%';
    $stmt = au_query($conexao, "SELECT id,nome_completo,documento,tipo_documento FROM visitantes WHERE tenant_id=? AND ativo=1 AND (nome_completo LIKE ? OR documento LIKE ? OR REPLACE(REPLACE(REPLACE(UPPER(documento),'.',''),'-',''),'/','') LIKE ?) ORDER BY nome_completo LIMIT 25", 'isss', [$tenantId,$like,$like,$docLike]);
    $itens = [];
    foreach (au_rows($stmt) as $visitante) $itens[] = ['tipo_alvo'=>'VISITANTE','visitante_id'=>(int)$visitante['id'],'nome_referencia'=>$visitante['nome_completo'],'tipo_documento'=>$visitante['tipo_documento'],'documento_mascarado'=>alertas_unidade_mascarar_documento($visitante['documento'],$visitante['tipo_documento'])];
    $stmt->close();
    $placa = alertas_unidade_normalizar_placa($busca);
    if ($placa !== '') {
        $stmt = au_query($conexao, 'SELECT id,placa,modelo FROM veiculos WHERE tenant_id=? AND ativo=1 AND REPLACE(REPLACE(UPPER(placa),"-","")," ","") LIKE ? ORDER BY placa LIMIT 25', 'is', [$tenantId, '%' . $placa . '%']);
        foreach (au_rows($stmt) as $veiculo) $itens[] = ['tipo_alvo'=>'PLACA','placa'=>$veiculo['placa'],'nome_referencia'=>trim((string)$veiculo['modelo']) !== '' ? $veiculo['modelo'] : null];
        $stmt->close();
    }
    au_api_responder(true, 'Alvos encontrados.', ['itens'=>$itens]);
}

if ($acao === 'detalhe') {
    au_api_rbac($conexao, $tenantId, 'visualizar');
    $modoEdicao = (string)($_GET['modo'] ?? '') === 'edicao';
    if ($modoEdicao) au_api_rbac($conexao, $tenantId, 'editar');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) au_api_erro('Alerta inválido.', 422, 'ID_INVALIDO');
    $stmt = au_query($conexao, 'SELECT a.*,m.nome morador_nome,d.nome_completo dependente_nome FROM alertas_unidade a LEFT JOIN moradores m ON m.tenant_id=a.tenant_id AND m.id=a.morador_id LEFT JOIN dependentes d ON d.tenant_id=a.tenant_id AND d.id=a.dependente_id WHERE a.tenant_id=? AND a.id=? LIMIT 1', 'ii', [$tenantId,$id]);
    $alerta = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$alerta) au_api_erro('Alerta não encontrado neste condomínio.', 404, 'NOT_FOUND');
    $alerta['status'] = au_api_status_exibicao($alerta);
    $alerta['dias_semana'] = au_dias_validos($alerta['dias_semana'] ?? '');
    $stmt = au_query($conexao, 'SELECT * FROM alertas_unidade_alvos WHERE tenant_id=? AND alerta_id=? ORDER BY id', 'ii', [$tenantId,$id]);
    $alvos = []; foreach (au_rows($stmt) as $item) $alvos[] = au_api_alvo_saida($item, $modoEdicao); $stmt->close();
    $stmt = au_query($conexao, 'SELECT id,alerta_id,registro_acesso_id,contexto,decisao,usuario_id,usuario_nome,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,divergencia_unidade,criado_em FROM alertas_unidade_acionamentos WHERE tenant_id=? AND alerta_id=? ORDER BY criado_em DESC,id DESC LIMIT 200', 'ii', [$tenantId,$id]);
    $acionamentos = au_rows($stmt); $stmt->close();
    $stmt = au_query($conexao, 'SELECT id,alerta_id,registro_acesso_id,contexto,decisao,justificativa,usuario_id,usuario_nome,supervisor_usuario_id,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,divergencia_unidade,criado_em FROM alertas_unidade_ciencias WHERE tenant_id=? AND alerta_id=? ORDER BY criado_em DESC,id DESC LIMIT 200', 'ii', [$tenantId,$id]);
    $ciencias = au_rows($stmt); $stmt->close();
    $stmt = au_query($conexao, 'SELECT id,alerta_id,operacao,usuario_id,usuario_nome,origem,dados_antes_json,dados_depois_json,motivo,criado_em FROM alertas_unidade_historico WHERE tenant_id=? AND alerta_id=? ORDER BY criado_em DESC,id DESC LIMIT 200', 'ii', [$tenantId,$id]);
    $historico = [];
    foreach (au_rows($stmt) as $item) {
        $item['dados_antes'] = json_decode($item['dados_antes_json'] ?? 'null', true);
        $item['dados_depois'] = json_decode($item['dados_depois_json'] ?? 'null', true);
        unset($item['dados_antes_json'], $item['dados_depois_json']);
        $historico[] = $item;
    }
    $stmt->close();
    au_api_responder(true, 'Detalhe carregado.', ['alerta'=>$alerta,'alvos'=>$alvos,'acionamentos'=>$acionamentos,'ciencias'=>$ciencias,'historico'=>$historico]);
}

if ($acao === 'salvar' && $metodo === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $operacaoRbac = $id > 0 ? 'editar' : 'criar';
    au_api_rbac($conexao, $tenantId, $operacaoRbac);
    $entrada = au_api_normalizar_entrada($conexao, $tenantId, $body, 'SISTEMA');
    $payloadHash = hash('sha256', au_api_json($entrada));
    $conexao->begin_transaction();
    try {
        if ($entrada['idempotency_key'] !== null) {
            $stmt = au_query($conexao, 'SELECT id,idempotency_payload_hash FROM alertas_unidade WHERE tenant_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE', 'is', [$tenantId,$entrada['idempotency_key']]);
            $existente = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($existente) {
                if (!hash_equals((string)$existente['idempotency_payload_hash'], $payloadHash)) {
                    $conexao->rollback();
                    au_api_erro('A chave de idempotência já foi usada para outro alerta.', 409, 'IDEMPOTENCY_CONFLICT');
                }
                $conexao->commit();
                au_api_responder(true, 'Alerta já havia sido salvo.', ['id'=>(int)$existente['id'],'idempotente'=>true]);
            }
        }
        if ($id <= 0 && alertas_unidade_contar_ativos_unidade($conexao, (int)$tenantId, (string)$entrada['unidade']) >= 30) {
            $conexao->rollback();
            au_api_erro('Esta unidade já possui o limite de 30 alertas vigentes.', 422, 'LIMITE_ALERTAS_UNIDADE');
        }
        $antes = null;
        if ($id > 0) {
            $stmt = au_query($conexao, 'SELECT * FROM alertas_unidade WHERE tenant_id=? AND id=? LIMIT 1 FOR UPDATE', 'ii', [$tenantId,$id]);
            $atual = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$atual) { $conexao->rollback(); au_api_erro('Alerta não encontrado neste condomínio.', 404, 'NOT_FOUND'); }
            $antes = au_api_snapshot($atual);
            $sql = 'UPDATE alertas_unidade SET unidade=?,titular_tipo=?,morador_id=?,dependente_id=?,titulo=?,mensagem=?,nivel=?,acao=?,publico=?,abrangencia=?,vigencia_inicio=?,vigencia_fim=?,dias_semana=?,hora_inicio=?,hora_fim=?,instrucao_operador=?,telefone_contato=?,idempotency_payload_hash=? WHERE tenant_id=? AND id=?';
            $params = [$entrada['unidade'],$entrada['titular_tipo'],$entrada['morador_id'],$entrada['dependente_id'],$entrada['titulo'],$entrada['mensagem'],$entrada['nivel'],$entrada['acao'],$entrada['publico'],$entrada['abrangencia'],$entrada['vigencia_inicio'],$entrada['vigencia_fim'],$entrada['dias_semana'],$entrada['hora_inicio'],$entrada['hora_fim'],$entrada['instrucao_operador'],$entrada['telefone_contato'],$payloadHash,$tenantId,$id];
            $stmt = au_query($conexao, $sql, 'ssiissssssssssssssii', $params); $stmt->close();
            $operacao = 'EDITAR';
        } else {
            $sql = 'INSERT INTO alertas_unidade (tenant_id,unidade,titular_tipo,morador_id,dependente_id,titulo,mensagem,nivel,acao,publico,abrangencia,vigencia_inicio,vigencia_fim,dias_semana,hora_inicio,hora_fim,instrucao_operador,telefone_contato,status,origem,criado_por_tipo,criado_por_usuario_id,idempotency_key,idempotency_payload_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $params = [$tenantId,$entrada['unidade'],$entrada['titular_tipo'],$entrada['morador_id'],$entrada['dependente_id'],$entrada['titulo'],$entrada['mensagem'],$entrada['nivel'],$entrada['acao'],$entrada['publico'],$entrada['abrangencia'],$entrada['vigencia_inicio'],$entrada['vigencia_fim'],$entrada['dias_semana'],$entrada['hora_inicio'],$entrada['hora_fim'],$entrada['instrucao_operador'],$entrada['telefone_contato'],'ATIVO','SISTEMA','USUARIO',$uid,$entrada['idempotency_key'],$payloadHash];
            $stmt = au_query($conexao, $sql, 'issiissssssssssssssssiss', $params);
            $id = (int)$conexao->insert_id; $stmt->close();
            $operacao = 'CRIAR';
        }
        $stmt = au_query($conexao, 'DELETE FROM alertas_unidade_alvos WHERE tenant_id=? AND alerta_id=?', 'ii', [$tenantId,$id]); $stmt->close();
        foreach ($entrada['alvos'] as $alvo) {
            $stmt = au_query($conexao, 'INSERT INTO alertas_unidade_alvos (tenant_id,alerta_id,tipo_alvo,visitante_id,tipo_documento,documento_normalizado,placa_normalizada,nome_referencia) VALUES (?,?,?,NULLIF(?,0),?,?,?,?)', 'iisissss', [$tenantId,$id,$alvo['tipo_alvo'],$alvo['visitante_id'] ?: 0,$alvo['tipo_documento'],$alvo['documento_normalizado'],$alvo['placa_normalizada'],$alvo['nome_referencia']]);
            $stmt->close();
        }
        $stmt = au_query($conexao, 'SELECT * FROM alertas_unidade WHERE tenant_id=? AND id=?', 'ii', [$tenantId,$id]);
        $depois = au_api_snapshot($stmt->get_result()->fetch_assoc() ?: []); $stmt->close();
        $stmt = au_query($conexao, 'INSERT INTO alertas_unidade_historico (tenant_id,alerta_id,operacao,usuario_id,usuario_nome,origem,dados_antes_json,dados_depois_json) VALUES (?,?,?,?,?,?,?,?)', 'iisissss', [$tenantId,$id,$operacao,$uid,$uname,'SISTEMA',$antes ? au_api_json($antes) : null,au_api_json($depois)]);
        $stmt->close();
        alertas_unidade_invalidar_cache($conexao, (int)$tenantId);
        $conexao->commit();
        if (function_exists('rbacAuditar')) rbacAuditar($conexao, ['tenant_id'=>$tenantId,'usuario_id'=>$uid,'modulo_chave'=>'alertas_unidade','acao'=>$operacao,'registro_tipo'=>'alerta_unidade','registro_id'=>$id,'dados_antes'=>$antes,'dados_depois'=>$depois,'resultado'=>'SUCESSO','status_http'=>$operacao==='CRIAR'?201:200]);
        au_api_responder(true, $operacao === 'CRIAR' ? 'Alerta criado com sucesso.' : 'Alerta atualizado com sucesso.', ['id'=>$id], $operacao === 'CRIAR' ? 201 : 200);
    } catch (Throwable $erro) {
        $conexao->rollback();
        if ((int)$erro->getCode() === 1062) au_api_erro('Este alerta já foi salvo. Verifique a lista antes de tentar novamente.', 409, 'DUPLICIDADE');
        throw $erro;
    }
}

if ($acao === 'alterar_status' && $metodo === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $operacao = strtoupper(trim((string)($body['operacao'] ?? '')));
    if ($id <= 0 || !in_array($operacao, ['PAUSAR','RETOMAR','CANCELAR'], true)) au_api_erro('Alerta ou operação inválida.', 422, 'OPERACAO_INVALIDA');
    au_api_rbac($conexao, $tenantId, $operacao === 'CANCELAR' ? 'excluir' : 'editar');
    $motivo = au_api_texto($body['motivo'] ?? '', 500);
    if ($operacao === 'CANCELAR' && $motivo === '') au_api_erro('Informe o motivo do cancelamento.', 422, 'MOTIVO_OBRIGATORIO');
    $novoStatus = $operacao === 'PAUSAR' ? 'PAUSADO' : ($operacao === 'RETOMAR' ? 'ATIVO' : 'CANCELADO');
    $stmt = au_query($conexao, 'SELECT * FROM alertas_unidade WHERE tenant_id=? AND id=? LIMIT 1', 'ii', [$tenantId,$id]);
    $antes = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$antes) au_api_erro('Alerta não encontrado neste condomínio.', 404, 'NOT_FOUND');
    if (in_array($antes['status'], ['CANCELADO','EXPIRADO'], true) && $operacao !== 'CANCELAR') au_api_erro('O status atual não permite esta operação.', 409, 'STATUS_INVALIDO');
    if ($operacao === 'RETOMAR' && alertas_unidade_contar_ativos_unidade($conexao, (int)$tenantId, (string)$antes['unidade'], $id) >= 30) au_api_erro('Esta unidade já possui o limite de 30 alertas vigentes.', 422, 'LIMITE_ALERTAS_UNIDADE');
    $stmt = au_query($conexao, "UPDATE alertas_unidade SET status=?,cancelado_por_tipo=CASE WHEN ?='CANCELADO' THEN 'USUARIO' ELSE cancelado_por_tipo END,cancelado_por_id=CASE WHEN ?='CANCELADO' THEN ? ELSE cancelado_por_id END,cancelado_em=CASE WHEN ?='CANCELADO' THEN NOW() ELSE cancelado_em END,motivo_cancelamento=CASE WHEN ?='CANCELADO' THEN ? ELSE motivo_cancelamento END WHERE tenant_id=? AND id=?", 'sssisssii', [$novoStatus,$novoStatus,$novoStatus,$uid,$novoStatus,$novoStatus,$motivo,$tenantId,$id]);
    $stmt->close();
    $stmt = au_query($conexao, 'INSERT INTO alertas_unidade_historico (tenant_id,alerta_id,operacao,usuario_id,usuario_nome,origem,dados_antes_json,dados_depois_json,motivo) VALUES (?,?,?,?,?,?,?,?,?)', 'iisisssss', [$tenantId,$id,$operacao,$uid,$uname,'SISTEMA',au_api_json(au_api_snapshot($antes)),au_api_json(array_merge(au_api_snapshot($antes),['status'=>$novoStatus])),$motivo ?: null]);
    $stmt->close();
    alertas_unidade_invalidar_cache($conexao, (int)$tenantId);
    if (function_exists('rbacAuditar')) rbacAuditar($conexao, ['tenant_id'=>$tenantId,'usuario_id'=>$uid,'modulo_chave'=>'alertas_unidade','acao'=>$operacao,'registro_tipo'=>'alerta_unidade','registro_id'=>$id,'motivo'=>$motivo,'resultado'=>'SUCESSO','status_http'=>200]);
    au_api_responder(true, 'Status do alerta atualizado.', ['id'=>$id,'status'=>$novoStatus]);
}

if ($acao === 'verificar' && $metodo === 'POST') {
    au_api_rbac($conexao, $tenantId, 'verificar');
    $ctx = au_api_contexto($conexao, $tenantId, $body);
    if (($body['parcial'] ?? false) === true) {
        $etapa = strtoupper(trim((string)($body['etapa'] ?? '')));
        if (!in_array($etapa, ['DESTINO', 'IDENTIFICACAO', 'VEICULO'], true)) au_api_erro('Informe uma etapa parcial válida.', 422, 'ETAPA_PARCIAL_INVALIDA');
        if ($ctx['tipo_acesso'] === 'Saída') {
            au_api_responder(true, 'Saída não requer pré-validação de alertas.', ['parcial'=>true,'etapa'=>$etapa,'alertas'=>[],'precisa_ciencia'=>false,'bloqueado'=>false,'pode_override'=>false,'alertas_especificos_pendentes'=>0]);
        }
        au_api_rate_limit($conexao, $tenantId, $uid, $ctx['unidade']);
        if ($ctx['tipo_acesso'] !== 'Saída' && ((int)$ctx['morador_id'] <= 0 || trim((string)$ctx['unidade']) === '')) {
            au_api_erro('Selecione a unidade e o morador de destino antes da pré-validação.', 422, 'DESTINO_INCOMPLETO');
        }
        if ($ctx['tipo_acesso'] !== 'Saída' && $etapa !== 'DESTINO'
            && in_array((string)$ctx['tipo_pessoa'], ['VISITANTE', 'PRESTADOR', 'ENTREGADOR_AUTORIZADO', 'FAMILIAR'], true)) {
            au_api_validar_visitante_contexto($conexao, (int)$tenantId, $ctx);
        }
        if ($ctx['tipo_acesso'] !== 'Saída' && $etapa === 'VEICULO'
            && in_array((string)$ctx['tipo_pessoa'], ['VISITANTE', 'PRESTADOR', 'ENTREGADOR_AUTORIZADO', 'FAMILIAR'], true)
            && (int)($ctx['visitante_id'] ?? 0) <= 0) {
            au_api_erro('Identifique o visitante antes da verificação final do veículo.', 422, 'VISITANTE_OBRIGATORIO');
        }
        if ($etapa === 'VEICULO' && strtoupper(trim((string)($body['modo_registro'] ?? 'VEICULO'))) !== 'PEDESTRE' && trim((string)$ctx['placa']) === '') {
            au_api_erro('Informe a placa ou selecione o modo Pedestre antes da verificação final.', 422, 'PLACA_OBRIGATORIA');
        }
        $alertas = $ctx['tipo_acesso'] === 'Saída' ? [] : alertas_unidade_avaliar($conexao, $tenantId, $ctx);
        if ($etapa === 'DESTINO') {
            $alertas = array_values(array_filter($alertas, static function ($alerta) {
                return (string)($alerta['abrangencia'] ?? '') === 'TODOS_DO_TIPO';
            }));
        }
        $precisaCiencia = false; $bloqueado = false; $saida = [];
        foreach ($alertas as $alerta) {
            if ($alerta['acao'] !== 'APENAS_AVISAR') $precisaCiencia = true;
            if ($alerta['acao'] === 'BLOQUEAR') $bloqueado = true;
            $saida[] = ['id'=>(int)$alerta['id'],'titulo'=>$alerta['titulo'],'mensagem'=>$alerta['mensagem'],'nivel'=>$alerta['nivel'],'acao'=>$alerta['acao'],'unidade'=>$alerta['unidade'],'abrangencia'=>$alerta['abrangencia'],'publico'=>$alerta['publico'],'tipo_alvo_correspondente'=>$alerta['tipo_alvo_correspondente'] ?? null,'instrucao_operador'=>$alerta['instrucao_operador'],'telefone_contato'=>$alerta['telefone_contato'],'divergencia_unidade'=>!empty($alerta['divergencia_unidade'])];
        }
        $podeOverride = alertas_unidade_usuario_pode_override($conexao, $uid, (int)$tenantId);
        $especificosPendentes = $ctx['tipo_acesso'] === 'Saída' ? 0 : au_api_contar_especificos_pendentes($conexao, (int)$tenantId, $ctx, $etapa);
        au_api_responder(true, $alertas ? 'Pré-validação parcial concluída.' : 'Pré-validação parcial concluída sem alertas identificados nesta etapa.', [
            'parcial'=>true,'etapa'=>$etapa,'alertas'=>$saida,'precisa_ciencia'=>$precisaCiencia,
            'bloqueado'=>$bloqueado && !$podeOverride,'pode_override'=>$podeOverride,
            'alertas_especificos_pendentes'=>$especificosPendentes,
        ]);
    }
    au_api_rate_limit($conexao, $tenantId, $uid, $ctx['unidade']);
    $alertas = alertas_unidade_avaliar($conexao, $tenantId, $ctx);
    $precisaCiencia = false; $bloqueado = false;
    foreach ($alertas as $alerta) { if ($alerta['acao'] !== 'APENAS_AVISAR') $precisaCiencia = true; if ($alerta['acao'] === 'BLOQUEAR') $bloqueado = true; }
    $podeOverride = alertas_unidade_usuario_pode_override($conexao, $uid, (int)$tenantId);
    $saida = [];
    foreach ($alertas as $alerta) $saida[] = ['id'=>(int)$alerta['id'],'titulo'=>$alerta['titulo'],'mensagem'=>$alerta['mensagem'],'nivel'=>$alerta['nivel'],'acao'=>$alerta['acao'],'unidade'=>$alerta['unidade'],'abrangencia'=>$alerta['abrangencia'],'publico'=>$alerta['publico'],'tipo_alvo_correspondente'=>$alerta['tipo_alvo_correspondente'] ?? null,'instrucao_operador'=>$alerta['instrucao_operador'],'telefone_contato'=>$alerta['telefone_contato'],'divergencia_unidade'=>!empty($alerta['divergencia_unidade'])];
    au_api_responder(true, $alertas ? 'Alertas aplicáveis encontrados.' : 'Nenhum alerta aplicável encontrado.', ['alertas'=>$saida,'precisa_ciencia'=>$precisaCiencia,'bloqueado'=>$bloqueado && !$podeOverride,'pode_override'=>$podeOverride]);
}

if ($acao === 'registrar_ciencia' && $metodo === 'POST') {
    au_api_rbac($conexao, $tenantId, 'registrar_ciencia');
    $ctx = au_api_contexto($conexao, $tenantId, $body);
    $ctx['_usuario_id'] = $uid; $ctx['_usuario_nome'] = $uname;
    $alertas = alertas_unidade_avaliar($conexao, $tenantId, $ctx);
    $mapa = [];
    foreach ($alertas as $alerta) $mapa[(int)$alerta['id']] = $alerta;
    $decisoes = au_api_array($body['decisoes'] ?? []);
    if (!$alertas || !$decisoes) au_api_erro('Não há alertas que exijam ciência.', 409, 'ALERTA_PENDENTE');
    $normalizadas = []; $negacao = false; $override = false; $decididas = [];
    $podeOverride = alertas_unidade_usuario_pode_override($conexao, $uid, (int)$tenantId);
    foreach ($decisoes as $decisao) {
        $alertaId = (int)($decisao['alerta_id'] ?? 0);
        $tipoDecisao = strtoupper(trim((string)($decisao['decisao'] ?? '')));
        $justificativa = au_api_texto($decisao['justificativa'] ?? '', 500);
        if (!isset($mapa[$alertaId]) || isset($decididas[$alertaId]) || !in_array($tipoDecisao, ['CIENTE_LIBERADO','CIENTE_NAO_LIBERADO','CONTATO_MORADOR_AUTORIZOU','CONTATO_MORADOR_NEGOU','OVERRIDE_SUPERVISOR'], true)) au_api_erro('A decisão não corresponde ao alerta atual.', 409, 'DECISAO_INVALIDA');
        $decididas[$alertaId] = true;
        $acaoAlerta = (string)$mapa[$alertaId]['acao'];
        $decisoesPorAcao = [
            'EXIGIR_CIENTE' => ['CIENTE_LIBERADO','CIENTE_NAO_LIBERADO'],
            'CONTATAR_MORADOR' => ['CONTATO_MORADOR_AUTORIZOU','CONTATO_MORADOR_NEGOU'],
            'BLOQUEAR' => ['OVERRIDE_SUPERVISOR'],
        ];
        if (isset($decisoesPorAcao[$acaoAlerta]) && !in_array($tipoDecisao, $decisoesPorAcao[$acaoAlerta], true)) au_api_erro('A decisão não é compatível com a ação definida no alerta.', 409, 'DECISAO_INCOMPATIVEL');
        if (!isset($decisoesPorAcao[$acaoAlerta]) && $tipoDecisao === 'OVERRIDE_SUPERVISOR') au_api_erro('Override só pode ser usado para um bloqueio de acesso.', 409, 'DECISAO_INCOMPATIVEL');
        if ($tipoDecisao === 'OVERRIDE_SUPERVISOR' && (!$podeOverride || strlen($justificativa) < 10)) au_api_erro('Override exige permissão de supervisor e justificativa de pelo menos 10 caracteres.', 403, 'OVERRIDE_NAO_AUTORIZADO');
        if ($mapa[$alertaId]['nivel'] === 'CRITICO' && in_array($tipoDecisao, ['CIENTE_LIBERADO','OVERRIDE_SUPERVISOR'], true) && strlen($justificativa) < 10) au_api_erro('A liberação de alerta crítico exige justificativa de pelo menos 10 caracteres.', 422, 'JUSTIFICATIVA_OBRIGATORIA');
        if (in_array($tipoDecisao, ['CONTATO_MORADOR_NEGOU','CIENTE_NAO_LIBERADO'], true)) $negacao = true;
        if ($tipoDecisao === 'OVERRIDE_SUPERVISOR') $override = true;
        $normalizadas[] = ['alerta_id'=>$alertaId,'decisao'=>$tipoDecisao,'justificativa'=>$justificativa ?: null];
    }
    if ($override) {
        $senhaSupervisor = (string)($body['senha_supervisor'] ?? '');
        if ($senhaSupervisor === '' || strlen($senhaSupervisor) > 1024) au_api_erro('Informe a senha do supervisor para confirmar o override.', 403, 'REAUTENTICACAO_OBRIGATORIA');
        $stmtSenha = au_query($conexao, 'SELECT senha FROM usuarios WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $uid]);
        $credencial = $stmtSenha->get_result()->fetch_assoc();
        $stmtSenha->close();
        if (!$credencial || !password_verify($senhaSupervisor, (string)$credencial['senha'])) au_api_erro('A senha do supervisor está incorreta ou a conta não está ativa neste condomínio.', 403, 'REAUTENTICACAO_INVALIDA');
    }
    if ($negacao) {
        $conexao->begin_transaction();
        try {
            $resultadoNegacao = alertas_unidade_registrar_eventos($conexao, $tenantId, $ctx, 0, $alertas, $normalizadas, ['usuario_id'=>$uid,'usuario_nome'=>$uname,'supervisor_usuario_id'=>$override ? $uid : 0]);
            $conexao->commit();
        } catch (Throwable $erro) { $conexao->rollback(); throw $erro; }
        foreach (($resultadoNegacao['notificacoes'] ?? []) as $notificacao) {
            if (!is_array($notificacao) || empty($notificacao['alerta']) || (int)($notificacao['morador_id'] ?? 0) <= 0) continue;
            alertas_unidade_notificar_morador($conexao, (int)$tenantId, $notificacao['alerta'], (int)$notificacao['morador_id'], null, $ctx, (string)($notificacao['decisao'] ?? 'CIENTE_NAO_LIBERADO'));
        }
        au_api_responder(false, 'A entrada foi negada pelo alerta da unidade.', [], 409, 'ACESSO_NEGADO_ALERTA');
    }
    foreach ($alertas as $alerta) {
        $exigeDecisao = $alerta['acao'] !== 'APENAS_AVISAR';
        if ($exigeDecisao && !isset($decididas[(int)$alerta['id']])) au_api_erro('Registre uma decisão para cada alerta que exige ciência.', 409, 'DECISAO_OBRIGATORIA');
    }
    $token = alertas_unidade_criar_token_ciencia($conexao, $tenantId, $uid, $ctx, $alertas, $normalizadas);
    au_api_responder(true, 'Ciência registrada para uso único na gravação do acesso.', ['ciencia_token'=>$token]);
}

if ($acao === 'exportar' && $metodo === 'GET') {
    au_api_rbac($conexao, $tenantId, 'exportar');
    $sql = 'SELECT c.alerta_id,c.contexto,c.registro_acesso_id,c.decisao,c.justificativa,c.usuario_nome,c.pessoa_nome,c.pessoa_documento_mascarado,c.tipo_pessoa,c.placa,c.criado_em,a.titulo,a.unidade FROM alertas_unidade_ciencias c INNER JOIN alertas_unidade a ON a.tenant_id=c.tenant_id AND a.id=c.alerta_id WHERE c.tenant_id=?';
    $types = 'i';
    $params = [$tenantId];
    $inicio = trim((string)($_GET['data_inicio'] ?? ''));
    $fim = trim((string)($_GET['data_fim'] ?? ''));
    foreach (['início'=>$inicio,'fim'=>$fim] as $nomeData=>$valorData) {
        if ($valorData === '') continue;
        $dataValida = DateTime::createFromFormat('!Y-m-d', $valorData);
        $errosData = DateTime::getLastErrors();
        if (!$dataValida || ($errosData && ($errosData['warning_count'] || $errosData['error_count'])) || $dataValida->format('Y-m-d') !== $valorData) au_api_erro('Informe uma data válida para o período de exportação.', 422, 'DATA_INVALIDA');
    }
    if (($inicio !== '') !== ($fim !== '')) au_api_erro('Informe as duas datas para filtrar o relatório.', 422, 'PERIODO_INCOMPLETO');
    if ($inicio !== '' && $fim !== '' && $inicio > $fim) au_api_erro('A data final deve ser igual ou posterior à data inicial.', 422, 'PERIODO_INVALIDO');
    if ($inicio !== '' && $fim !== '') {
        $dataInicio = DateTime::createFromFormat('!Y-m-d', $inicio);
        $dataFim = DateTime::createFromFormat('!Y-m-d', $fim);
        if ($dataInicio->diff($dataFim)->days > 89) au_api_erro('O período de exportação não pode ultrapassar 90 dias.', 422, 'PERIODO_EXTENSO');
    }
    if ($inicio !== '') { $sql .= ' AND c.criado_em >= ?'; $types .= 's'; $params[] = $inicio . ' 00:00:00'; }
    if ($fim !== '') { $sql .= ' AND c.criado_em < DATE_ADD(?, INTERVAL 1 DAY)'; $types .= 's'; $params[] = $fim . ' 00:00:00'; }
    $sql .= ' ORDER BY c.criado_em DESC LIMIT 5000';
    $stmt = au_query($conexao, $sql, $types, $params);
    $linhas = au_rows($stmt); $stmt->close();
    if (strtolower((string)($_GET['formato'] ?? 'csv')) === 'json') au_api_responder(true, 'Exportação preparada.', ['linhas'=>$linhas]);
    $csv = "alerta_id,titulo,unidade,contexto,decisao,justificativa,usuario_nome,pessoa_nome,documento_mascarado,tipo_pessoa,placa,criado_em\n";
    foreach ($linhas as $linha) {
        $valores = [$linha['alerta_id'],$linha['titulo'],$linha['unidade'],$linha['contexto'],$linha['decisao'],$linha['justificativa'],$linha['usuario_nome'],$linha['pessoa_nome'],$linha['pessoa_documento_mascarado'],$linha['tipo_pessoa'],$linha['placa'],$linha['criado_em']];
        $csv .= implode(',', array_map(function ($valor) { return '"' . str_replace('"', '""', (string)$valor) . '"'; }, $valores)) . "\n";
    }
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alertas-unidade-ciencias.csv"');
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}
au_api_erro('Ação não reconhecida.', 400, 'ACTION_INVALID');
