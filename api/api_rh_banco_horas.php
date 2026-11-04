<?php
// =====================================================
// API: RH — Banco de Horas
// GET  ?acao=extrato|saldo&colaborador_id=N[&data_ini=Y-m-d&data_fim=Y-m-d]
// POST ?acao=registrar {colaborador_id,tipo,minutos|duracao,descricao,data,idempotency_key}
// DELETE ?acao=excluir {id,motivo}
// =====================================================

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/rh_banco_horas_helper.php';
require_once __DIR__ . '/rh_ponto_core.php';
require_once __DIR__ . '/helpers/rh_banco_horas_reprocessamento.php';
if (is_file(__DIR__ . '/rbac_helper.php')) require_once __DIR__ . '/rbac_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$origensPermitidas = [
    'https://app.erpcondominios.com.br', 'http://app.erpcondominios.com.br',
    'https://erpcondominios.com.br', 'http://erpcondominios.com.br',
];
$origemLocal = preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i', $origin) === 1;
if ($origin !== '' && (in_array($origin, $origensPermitidas, true) || $origemLocal)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

if (!function_exists('bh_responder')) {
    function bh_responder(bool $sucesso, string $mensagem, $dados = null, ?string $codigo = null): void {
        if (ob_get_level() > 0) ob_end_clean();
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        if ($codigo !== null) $resposta['codigo'] = $codigo;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

try {
    verificarAutenticacao(true, 'operador');
    $tenant_id = exigirTenantId();
} catch (Throwable $e) {
    error_log('[RH Banco de Horas] Falha de autenticação: ' . $e->getMessage());
    http_response_code(401);
    bh_responder(false, 'Não autenticado.');
}

$conn = conectar_banco();
if (!$conn) {
    error_log('[RH Banco de Horas] Falha ao conectar ao banco.');
    http_response_code(503);
    bh_responder(false, 'Serviço temporariamente indisponível.');
}

try {
    bh_garantir_tabela($conn);
} catch (Throwable $e) {
    error_log('[RH Banco de Horas] Falha de estrutura: ' . $e->getMessage());
    fechar_conexao($conn);
    http_response_code(503);
    bh_responder(false, 'Banco de Horas temporariamente indisponível.');
}

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$acao = trim((string)($_GET['acao'] ?? ''));

function bh_validar_data(string $data): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) return false;
    $dt = DateTime::createFromFormat('!Y-m-d', $data);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $data;
}

function bh_validar_colaborador_ou_404(mysqli $conn, int $tenantId, int $colaboradorId): void {
    try {
        $pertence = bh_colaborador_pertence_tenant($conn, $tenantId, $colaboradorId);
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Falha ao validar colaborador; tenant=' . $tenantId . '; colaborador=' . $colaboradorId . '; erro=' . $e->getMessage());
        fechar_conexao($conn);
        http_response_code(500);
        bh_responder(false, 'Não foi possível validar o colaborador.');
    }
    if (!$pertence) {
        fechar_conexao($conn);
        http_response_code(404);
        bh_responder(false, 'Colaborador não encontrado.');
    }
}

if ($metodo === 'GET' && $acao === 'mapear') {
    try {
        bh_reprocess_exigir_permissao($conn, 'visualizar');
        $filtro = bh_reprocess_validar_periodo(bh_reprocess_normalizar_periodo_input($_GET));
        if ($filtro['incluir_fechados'] && !bh_reprocess_administrador($conn, (int)$tenant_id)) {
            fechar_conexao($conn); http_response_code(403); bh_responder(false, 'Somente administrador pode incluir períodos fechados.');
        }
        $dados = bh_reprocess_mapear($conn, (int)$tenant_id, $filtro, false);
        fechar_conexao($conn); bh_responder(true, 'Mapeamento calculado.', $dados);
    } catch (InvalidArgumentException $e) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, $e->getMessage());
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Falha ao mapear ledger; tenant=' . (int)$tenant_id . '; erro=' . $e->getMessage());
        fechar_conexao($conn); http_response_code(500); bh_responder(false, 'Não foi possível mapear o Banco de Horas.');
    }
}

if ($metodo === 'GET' && $acao === 'historico') {
    try {
        bh_reprocess_exigir_permissao($conn, 'visualizar');
        bh_reprocess_historia_ensure($conn);
        $rawColab = $_GET['colaborador_id'] ?? null;
        $rawDepartamento = $_GET['departamento'] ?? '';
        if (($rawColab !== null && !is_string($rawColab)) || !is_string($rawDepartamento)) { fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Filtro de histórico inválido.'); }
        $colab = $rawColab === null || $rawColab === '' ? null : filter_var($rawColab, FILTER_VALIDATE_INT);
        if ($colab === false || ($colab !== null && (int)$colab <= 0)) { fechar_conexao($conn); http_response_code(422); bh_responder(false, 'colaborador_id inválido.'); }
        $departamento = trim($rawDepartamento);
        if (bh_tamanho_texto($departamento) > 100) { fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Departamento inválido.'); }
        $rawLimit = $_GET['limite'] ?? '50'; $rawOffset = $_GET['offset'] ?? '0';
        if (!is_string($rawLimit) || !is_string($rawOffset) || filter_var($rawLimit,FILTER_VALIDATE_INT) === false || filter_var($rawOffset,FILTER_VALIDATE_INT) === false) { fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Paginação inválida.'); }
        $limit = min(100, max(1, (int)$rawLimit));
        $offset = max(0, (int)$rawOffset);
        $sql = 'SELECT id,usuario_id,usuario_nome,escopo,colaborador_id,departamento_id,data_inicio,data_fim,regra,versao_calculo,motivo,totais_antes_json,totais_depois_json,dias_alterados,incluir_fechados,ignorados_json,status,iniciado_em,concluido_em FROM rh_banco_horas_reprocessamentos WHERE tenant_id=?';
        if ($colab && $colab > 0) $sql .= ' AND colaborador_id=?';
        elseif ($departamento !== '') $sql .= ' AND departamento_id=?';
        $sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';
        $st = $conn->prepare($sql);
        if (!$st) throw new RuntimeException('Falha ao preparar histórico.');
        if ($colab && $colab > 0) $st->bind_param('iiii', $tenant_id, $colab, $limit, $offset);
        elseif ($departamento !== '') $st->bind_param('isii', $tenant_id, $departamento, $limit, $offset);
        else $st->bind_param('iii', $tenant_id, $limit, $offset);
        if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao consultar histórico.'); }
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
        foreach ($rows as &$row) {
            foreach (['totais_antes_json','totais_depois_json','ignorados_json'] as $key) {
                $outKey = str_replace('_json','',$key);
                $row[$outKey] = json_decode((string)($row[$key] ?? ''), true) ?: [];
                unset($row[$key]);
            }
        }
        unset($row);
        fechar_conexao($conn); bh_responder(true, 'Histórico carregado.', $rows);
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Falha ao consultar histórico; tenant=' . (int)$tenant_id . '; erro=' . $e->getMessage());
        fechar_conexao($conn); http_response_code(500); bh_responder(false, 'Não foi possível consultar o histórico.');
    }
}

if ($metodo === 'POST' && $acao === 'reprocessar') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { fechar_conexao($conn); http_response_code(400); bh_responder(false, 'Corpo JSON inválido.'); }
    $modo = (string)($body['modo'] ?? '');
    try {
        bh_reprocess_historia_ensure($conn);
        $body = bh_reprocess_normalizar_periodo_input($body);
        if ($modo === 'simular') {
            $dados = bh_reprocess_simular($conn, (int)$tenant_id, $body);
            fechar_conexao($conn); bh_responder(true, 'Prévia calculada; nenhuma alteração foi aplicada.', $dados);
        }
        if ($modo === 'aplicar') {
            $dados = bh_reprocess_aplicar($conn, (int)$tenant_id, $body);
            fechar_conexao($conn); bh_responder(true, 'Reprocessamento concluído.', $dados);
        }
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'modo deve ser simular ou aplicar.');
    } catch (InvalidArgumentException $e) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, $e->getMessage());
    } catch (DomainException $e) {
        fechar_conexao($conn); http_response_code(409); bh_responder(false, 'Período fechado não pode ser reprocessado sem autorização.');
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Falha ao reprocessar; tenant=' . (int)$tenant_id . '; modo=' . $modo . '; erro=' . $e->getMessage());
        fechar_conexao($conn); http_response_code(500); bh_responder(false, 'Não foi possível reprocessar o Banco de Horas.');
    }
}

function bh_exigir_permissao_excluir(mysqli $conn): void {
    if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conn)) {
        rbacExigir($conn, 'recursos_humanos', 'excluir', [
            'submodulo_chave' => 'banco_horas',
            'registro_tipo' => 'rh_banco_horas',
        ]);
        return;
    }
    verificarPermissao('gerente');
}

function bh_auditar_exclusao(mysqli $conn, int $tenantId, int $usuarioId, int $registroId, array $antes, string $motivo): bool {
    $evento = [
        'tenant_id' => $tenantId,
        'usuario_id' => $usuarioId,
        'usuario_nome' => $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? 'Sistema',
        'modulo_chave' => 'recursos_humanos',
        'submodulo_chave' => 'banco_horas',
        'acao' => 'EXCLUIR_LANCAMENTO',
        'origem' => 'ERP_WEB',
        'metodo_http' => 'DELETE',
        'endpoint' => 'api_rh_banco_horas.php',
        'registro_tipo' => 'rh_banco_horas',
        'registro_id' => (string)$registroId,
        'resultado' => 'SUCESSO',
        'status_http' => 200,
        'dados_antes' => $antes,
        'dados_depois' => null,
        'motivo' => $motivo,
    ];
    if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conn)) {
        return function_exists('rbacAuditar') && rbacAuditar($conn, $evento);
    }
    error_log('[RH Banco de Horas][AUDITORIA LEGADA] ' . json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    return true;
}

if ($metodo === 'GET' && $acao === 'permissao_reprocessar') {
    $uid=(int)($_SESSION['usuario_id']??0);$admin=bh_reprocess_administrador($conn,(int)$tenant_id);
    $podeEditar=$admin;
    if(!$podeEditar&&function_exists('rbacTabelasDisponiveis')&&rbacTabelasDisponiveis($conn))$podeEditar=rbacPode($conn,'recursos_humanos','editar',$uid,(int)$tenant_id);
    fechar_conexao($conn);bh_responder(true,'OK',['pode_editar'=>(bool)$podeEditar,'pode_admin'=>(bool)$admin]);
}

if ($metodo === 'GET' && in_array($acao, ['extrato', 'saldo'], true)) {
    bh_reprocess_exigir_permissao($conn, 'visualizar');
    $colaborador_id = filter_var($_GET['colaborador_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$colaborador_id || $colaborador_id <= 0) {
        fechar_conexao($conn);
        http_response_code(422);
        bh_responder(false, 'colaborador_id obrigatório e deve ser um inteiro positivo.');
    }
    bh_validar_colaborador_ou_404($conn, $tenant_id, (int)$colaborador_id);

    try {
        if ($acao === 'saldo') {
            $saldo = bh_obter_saldo($conn, $tenant_id, (int)$colaborador_id);
            fechar_conexao($conn);
            bh_responder(true, 'OK', [
                'credito' => $saldo['credito'],
                'debito' => $saldo['debito'],
                'saldo' => $saldo['saldo'],
                'credito_fmt' => bh_fmt($saldo['credito']),
                'debito_fmt' => bh_fmt($saldo['debito']),
                'saldo_fmt' => bh_fmt($saldo['saldo']),
                'positivo' => $saldo['saldo'] >= 0,
            ]);
        }

        $data_ini = trim((string)($_GET['data_ini'] ?? ''));
        $data_fim = trim((string)($_GET['data_fim'] ?? ''));
        if (($data_ini !== '' && !bh_validar_data($data_ini)) || ($data_fim !== '' && !bh_validar_data($data_fim)) ||
            ($data_ini !== '' && $data_fim !== '' && $data_ini > $data_fim)) {
            fechar_conexao($conn);
            http_response_code(422);
            bh_responder(false, 'Período de datas inválido.');
        }

        $sql = "SELECT b.id, DATE_FORMAT(b.data,'%d/%m/%Y') AS data_fmt, b.data,
                       b.tipo, b.minutos, b.descricao, b.usuario, b.usuario_id,
                       b.saldo_anterior_min, b.saldo_posterior_min,
                       b.saldo_negativo_confirmado, b.justificativa_saldo_negativo,
                       DATE_FORMAT(b.criado_em,'%d/%m/%Y %H:%i') AS criado_em_fmt,
                       b.lancamento_id
                FROM rh_banco_horas b
                WHERE b.tenant_id = ? AND b.colaborador_id = ?";
        if ($data_ini !== '') $sql .= ' AND b.data >= ?';
        if ($data_fim !== '') $sql .= ' AND b.data <= ?';
        $sql .= ' ORDER BY b.data ASC, b.id ASC';
        $st = $conn->prepare($sql);
        if (!$st) throw new RuntimeException('Falha ao preparar extrato.');
        if ($data_ini !== '' && $data_fim !== '') $st->bind_param('iiss', $tenant_id, $colaborador_id, $data_ini, $data_fim);
        elseif ($data_ini !== '') $st->bind_param('iis', $tenant_id, $colaborador_id, $data_ini);
        elseif ($data_fim !== '') $st->bind_param('iis', $tenant_id, $colaborador_id, $data_fim);
        else $st->bind_param('ii', $tenant_id, $colaborador_id);
        if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao consultar extrato.'); }
        $res = $st->get_result();
        $linhas = [];
        $saldoPeriodo = 0;
        while ($linha = $res->fetch_assoc()) {
            $tipo = (string)($linha['tipo'] ?? '');
            $sinal = bh_sinal($tipo);
            $minutos = (int)$linha['minutos'];
            if ($sinal !== null) $saldoPeriodo += $sinal * $minutos;
            $linha['tipo_conhecido'] = $sinal !== null;
            $linha['sinal'] = $sinal === null ? '' : ($sinal > 0 ? '+' : '-');
            $linha['saldo_corrente'] = $saldoPeriodo;
            $linha['saldo_corrente_fmt'] = bh_fmt($saldoPeriodo);
            $linha['minutos_fmt'] = bh_fmt($minutos);
            $linha['usuario_confirmacao'] = trim((string)($linha['usuario'] ?? '')) ?: 'Usuário não identificado';
            $linhas[] = $linha;
        }
        $st->close();
        $saldoGlobal = bh_obter_saldo($conn, $tenant_id, (int)$colaborador_id)['saldo'];
        fechar_conexao($conn);
        bh_responder(true, 'OK', [
            'linhas' => $linhas,
            'saldo_final' => $saldoPeriodo,
            'saldo_final_fmt' => bh_fmt($saldoPeriodo),
            'saldo_global' => $saldoGlobal,
            'saldo_global_fmt' => bh_fmt($saldoGlobal),
        ]);
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Erro ao consultar ' . $acao . '; tenant=' . (int)$tenant_id . '; colaborador=' . (int)$colaborador_id . '; erro=' . $e->getMessage());
        fechar_conexao($conn);
        http_response_code(500);
        bh_responder(false, 'Não foi possível consultar o Banco de Horas.');
    }
}

if ($metodo === 'POST' && $acao === 'registrar') {
    if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conn)) rbacExigir($conn, 'recursos_humanos', 'editar', ['submodulo_chave'=>'banco_horas']);
    else verificarPermissao('gerente');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fechar_conexao($conn);
        http_response_code(400);
        bh_responder(false, 'Corpo JSON inválido.');
    }

    $colaborador_id = filter_var($body['colaborador_id'] ?? null, FILTER_VALIDATE_INT);
    $tipo = is_string($body['tipo'] ?? null) ? trim($body['tipo']) : '';
    $minutosRaw = $body['minutos'] ?? null;
    $minutosValidos = is_int($minutosRaw) || (is_string($minutosRaw) && preg_match('/^\d+$/', $minutosRaw));
    $minutos = $minutosValidos ? (int)$minutosRaw : 0;
    $descricao = is_string($body['descricao'] ?? null) ? trim($body['descricao']) : '';
    $data = is_string($body['data'] ?? null) ? trim($body['data']) : date('Y-m-d');
    $confirmar = ($body['confirmar_saldo_negativo'] ?? false) === true || ($body['confirmar_saldo_negativo'] ?? null) === 1;
    $justificativa = is_string($body['justificativa_saldo_negativo'] ?? null)
        ? trim($body['justificativa_saldo_negativo']) : '';
    $idempotencyKey = $body['idempotency_key'] ?? null;

    if (!$colaborador_id || $colaborador_id <= 0) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'colaborador_id obrigatório e deve ser um inteiro positivo.');
    }
    if (!bh_tipo_valido($tipo) || !in_array($tipo, ['abatimento', 'pagamento'], true)) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Tipo inválido. Use abatimento ou pagamento.');
    }
    if (!$minutosValidos || $minutos <= 0 || $minutos > 2147483647) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Informe uma quantidade inteira de minutos entre 1 e 2.147.483.647.');
    }
    if ($descricao === '' || bh_tamanho_texto($descricao) > 255) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Descrição obrigatória (máximo de 255 caracteres).');
    }
    if (!bh_validar_data($data)) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Data inválida. Use YYYY-MM-DD.');
    }
    if ($idempotencyKey !== null && (!is_string($idempotencyKey) || !preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $idempotencyKey))) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'idempotency_key inválida.');
    }
    if (bh_tamanho_texto($justificativa) > 500) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'A justificativa deve ter no máximo 500 caracteres.');
    }

    bh_validar_colaborador_ou_404($conn, $tenant_id, (int)$colaborador_id);
    $usuarioNome = trim((string)($_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? 'Sistema'));
    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
    $usuarioIdDb = $usuarioId > 0 ? $usuarioId : null;
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $ip = $ip !== '' ? substr($ip, 0, 45) : null;

    $lockAdquirido = false;
    $transacaoAtiva = false;
    try {
        $lockAdquirido = bh_obter_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id);
        if (!$lockAdquirido) {
            fechar_conexao($conn); http_response_code(503); bh_responder(false, 'O Banco de Horas está ocupado para este colaborador. Tente novamente.');
        }
        if (!$conn->begin_transaction()) throw new RuntimeException('Falha ao iniciar transação.');
        $transacaoAtiva = true;

        $stColab = $conn->prepare('SELECT id FROM rh_colaboradores WHERE tenant_id = ? AND id = ? FOR UPDATE');
        if (!$stColab) throw new RuntimeException('Falha ao bloquear colaborador.');
        $stColab->bind_param('ii', $tenant_id, $colaborador_id);
        if (!$stColab->execute()) { $stColab->close(); throw new RuntimeException('Falha ao bloquear colaborador.'); }
        $existeColab = $stColab->get_result()->num_rows > 0;
        $stColab->close();
        if (!$existeColab) {
            $conn->rollback(); $transacaoAtiva = false;
            bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id); $lockAdquirido = false;
            fechar_conexao($conn); http_response_code(404); bh_responder(false, 'Colaborador não encontrado.');
        }

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $stIdem = $conn->prepare(
                'SELECT id, colaborador_id, data, tipo, minutos, descricao, saldo_anterior_min, saldo_posterior_min,
                        saldo_negativo_confirmado, justificativa_saldo_negativo
                 FROM rh_banco_horas WHERE tenant_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE'
            );
            if (!$stIdem) throw new RuntimeException('Falha ao verificar idempotência.');
            $stIdem->bind_param('is', $tenant_id, $idempotencyKey);
            if (!$stIdem->execute()) { $stIdem->close(); throw new RuntimeException('Falha ao verificar idempotência.'); }
            $existente = $stIdem->get_result()->fetch_assoc();
            $stIdem->close();
            if ($existente) {
            $mesmoPayload = (int)$existente['colaborador_id'] === (int)$colaborador_id &&
                    $existente['data'] === $data && $existente['tipo'] === $tipo &&
                    (int)$existente['minutos'] === $minutos && $existente['descricao'] === $descricao &&
                    ((int)$existente['saldo_negativo_confirmado'] === 0 ||
                        (!$confirmar && $justificativa === '') ||
                        (string)($existente['justificativa_saldo_negativo'] ?? '') === $justificativa);
                if (!$mesmoPayload) {
                    $conn->rollback(); $transacaoAtiva = false;
                    bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id); $lockAdquirido = false;
                    fechar_conexao($conn); http_response_code(409);
                    bh_responder(false, 'A chave de idempotência já foi usada em outro lançamento.', null, 'IDEMPOTENCY_CONFLICT');
                }
                $conn->commit(); $transacaoAtiva = false;
                bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id); $lockAdquirido = false;
                fechar_conexao($conn);
                bh_responder(true, 'Lançamento já registrado anteriormente.', [
                    'id' => (int)$existente['id'],
                    'saldo_anterior' => (int)$existente['saldo_anterior_min'],
                    'saldo_posterior' => (int)$existente['saldo_posterior_min'],
                    'saldo_anterior_fmt' => bh_fmt((int)$existente['saldo_anterior_min']),
                    'saldo_posterior_fmt' => bh_fmt((int)$existente['saldo_posterior_min']),
                    'idempotente' => true,
                ]);
            }
        }

        $saldos = bh_obter_saldo($conn, (int)$tenant_id, (int)$colaborador_id);
        $saldoAtual = (int)$saldos['saldo'];
        $limiteNegativo = bh_obter_limite_negativo($conn, (int)$tenant_id, (int)$colaborador_id);
        $avaliacao = bh_avaliar_registro_manual($tipo, $minutos, $saldoAtual, $confirmar, $justificativa, $limiteNegativo);
        $saldoProjetado = (int)$avaliacao['dados']['saldo_projetado'];
        if (!$avaliacao['permitido']) {
            $conn->rollback(); $transacaoAtiva = false;
            bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id); $lockAdquirido = false;
            fechar_conexao($conn);
            http_response_code((int)$avaliacao['http']);
            bh_responder(false, (string)$avaliacao['mensagem'], $avaliacao['dados'], $avaliacao['codigo']);
        }

        $negativoConfirmado = !empty($avaliacao['saldo_negativo_confirmado']) ? 1 : 0;
        $justificativaDb = $negativoConfirmado ? $justificativa : null;
        $stInsert = $conn->prepare(
            'INSERT INTO rh_banco_horas
             (tenant_id, colaborador_id, lancamento_id, data, tipo, minutos, descricao, usuario,
              saldo_anterior_min, saldo_posterior_min, saldo_negativo_confirmado,
              justificativa_saldo_negativo, usuario_id, ip, idempotency_key)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stInsert) throw new RuntimeException('Falha ao preparar lançamento.');
        $stInsert->bind_param(
            'iississiiisiss',
            $tenant_id, $colaborador_id, $data, $tipo, $minutos, $descricao, $usuarioNome,
            $saldoAtual, $saldoProjetado, $negativoConfirmado, $justificativaDb, $usuarioIdDb, $ip, $idempotencyKey
        );
        if (!$stInsert->execute()) {
            $errno = (int)$stInsert->errno;
            error_log('[RH Banco de Horas] Falha no INSERT; errno=' . $errno);
            $stInsert->close();
            if ($errno === 1062 && is_string($idempotencyKey) && $idempotencyKey !== '') {
                throw new DomainException('IDEMPOTENCY_CONFLICT');
            }
            throw new RuntimeException('Falha ao gravar lançamento.');
        }
        $novoId = (int)$conn->insert_id;
        $stInsert->close();
        if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar transação.');
        $transacaoAtiva = false;
        bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id);
        $lockAdquirido = false;
        fechar_conexao($conn);
        bh_responder(true, ucfirst($tipo) . ' registrado com sucesso.', [
            'id' => $novoId,
            'saldo_anterior' => $saldoAtual,
            'saldo_posterior' => $saldoProjetado,
            'saldo_anterior_fmt' => bh_fmt($saldoAtual),
            'saldo_posterior_fmt' => bh_fmt($saldoProjetado),
            'saldo_negativo_confirmado' => (bool)$negativoConfirmado,
        ]);
    } catch (DomainException $e) {
        if ($transacaoAtiva) $conn->rollback();
        if ($lockAdquirido) bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id);
        fechar_conexao($conn);
        http_response_code(409);
        bh_responder(false, 'A chave de idempotência já foi usada em outro lançamento.', null, 'IDEMPOTENCY_CONFLICT');
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Erro ao registrar; tenant=' . (int)$tenant_id . '; colaborador=' . (int)$colaborador_id . '; erro=' . $e->getMessage());
        if ($transacaoAtiva) $conn->rollback();
        if ($lockAdquirido) bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colaborador_id);
        fechar_conexao($conn);
        http_response_code(500);
        bh_responder(false, 'Não foi possível registrar o lançamento.');
    }
}

if ($metodo === 'DELETE' && ($acao === '' || $acao === 'excluir')) {
    $body = json_decode(file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];
    $id = filter_var($body['id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $motivo = is_string($body['motivo'] ?? null) ? trim($body['motivo']) : '';
    if (!$id || $id <= 0) { fechar_conexao($conn); http_response_code(422); bh_responder(false, 'ID inválido.'); }
    if ($motivo === '' || bh_tamanho_texto($motivo) > 500) {
        fechar_conexao($conn); http_response_code(422); bh_responder(false, 'Motivo da exclusão obrigatório (máximo de 500 caracteres).');
    }

    bh_exigir_permissao_excluir($conn);
    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
    $transacaoAtiva = false;
    $lockAdquirido = false;
    $colabLock = 0;
    try {
        $lookup=$conn->prepare('SELECT colaborador_id FROM rh_banco_horas WHERE tenant_id=? AND id=? AND lancamento_id IS NULL LIMIT 1');
        if(!$lookup)throw new RuntimeException('Falha ao localizar lançamento.');$lookup->bind_param('ii',$tenant_id,$id);if(!$lookup->execute()){$lookup->close();throw new RuntimeException('Falha ao localizar lançamento.');}$lookupRow=$lookup->get_result()->fetch_assoc();$lookup->close();
        if(!$lookupRow){fechar_conexao($conn);http_response_code(404);bh_responder(false,'Lançamento manual não encontrado. Lançamentos automáticos não podem ser excluídos.');}
        $colabLock=(int)$lookupRow['colaborador_id'];
        if(!bh_colaborador_pertence_tenant($conn,(int)$tenant_id,$colabLock)){fechar_conexao($conn);http_response_code(404);bh_responder(false,'Lançamento não encontrado.');}
        if(!bh_obter_lock_colaborador($conn,(int)$tenant_id,$colabLock,10)){fechar_conexao($conn);http_response_code(409);bh_responder(false,'Colaborador ocupado por outra atualização. Tente novamente.',null,'LOCK_CONFLITO');}
        $lockAdquirido=true;
        if (!$conn->begin_transaction()) throw new RuntimeException('Falha ao iniciar transação.');
        $transacaoAtiva = true;
        $st = $conn->prepare(
            'SELECT id, colaborador_id, tipo, minutos, data, descricao, usuario, saldo_anterior_min,
                    saldo_posterior_min, saldo_negativo_confirmado, justificativa_saldo_negativo
             FROM rh_banco_horas WHERE tenant_id = ? AND id = ? AND lancamento_id IS NULL FOR UPDATE'
        );
        if (!$st) throw new RuntimeException('Falha ao consultar lançamento.');
        $st->bind_param('ii', $tenant_id, $id);
        if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao consultar lançamento.'); }
        $lancamento = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$lancamento) {
            $conn->rollback(); $transacaoAtiva = false; bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabLock);$lockAdquirido=false;fechar_conexao($conn);
            http_response_code(404); bh_responder(false, 'Lançamento manual não encontrado. Lançamentos automáticos não podem ser excluídos.');
        }
        if (!bh_colaborador_pertence_tenant($conn, (int)$tenant_id, (int)$lancamento['colaborador_id'])) {
            $conn->rollback(); $transacaoAtiva = false; bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabLock);$lockAdquirido=false;fechar_conexao($conn);
            http_response_code(404); bh_responder(false, 'Lançamento não encontrado.');
        }
        $stDel = $conn->prepare('DELETE FROM rh_banco_horas WHERE tenant_id = ? AND id = ? AND lancamento_id IS NULL');
        if (!$stDel) throw new RuntimeException('Falha ao preparar exclusão.');
        $stDel->bind_param('ii', $tenant_id, $id);
        if (!$stDel->execute() || $stDel->affected_rows !== 1) { $stDel->close(); throw new RuntimeException('Falha ao excluir lançamento.'); }
        $stDel->close();
        $auditOk = bh_auditar_exclusao($conn, (int)$tenant_id, $usuarioId, (int)$id, $lancamento, $motivo);
        if (!$auditOk) throw new RuntimeException('Auditoria de exclusão indisponível.');
        if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar exclusão.');
        $transacaoAtiva = false;
        bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabLock);$lockAdquirido=false;
        fechar_conexao($conn);
        bh_responder(true, 'Lançamento removido e auditado.');
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Erro ao excluir; tenant=' . (int)$tenant_id . '; registro=' . (int)$id . '; erro=' . $e->getMessage());
        if ($transacaoAtiva) $conn->rollback();
        if ($lockAdquirido) bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabLock);
        fechar_conexao($conn);
        http_response_code(500);
        bh_responder(false, 'Não foi possível excluir o lançamento.');
    }
}

fechar_conexao($conn);
http_response_code(405);
bh_responder(false, 'Ação ou método não permitido.');
