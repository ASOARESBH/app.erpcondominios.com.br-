<?php
// =====================================================
// API: RH — ESCALAS DE TRABALHO
// =====================================================
// GET  ?acao=listar&colaborador_id=N
// GET  ?acao=obter&id=N
// POST ?acao=criar   {colaborador_id, nome_escala, tipo, ...}
// POST ?acao=atualizar&id=N
// DELETE ?acao=excluir&id=N

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
require_once 'error_logger.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$origensPermitidas = ['https://app.erpcondominios.com.br','http://app.erpcondominios.com.br','https://erpcondominios.com.br','http://erpcondominios.com.br'];
$origemLocal = preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i', $origin) === 1;
if ($origin !== '' && (in_array($origin, $origensPermitidas, true) || $origemLocal)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Log dedicado ao módulo RH ─────────────────────────────────────────────────
function rh_log(string $nivel, string $msg, array $ctx = []): void {
    $dir  = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/recursoshumanos.txt';
    $ts   = date('Y-m-d H:i:s');
    $ctxStr = $ctx ? ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : '';
    $line = "[{$ts}] [{$nivel}] {$msg}{$ctxStr}" . PHP_EOL;
    // Rotação: se > 2MB, apaga e recria
    if (file_exists($file) && filesize($file) > 2 * 1024 * 1024) {
        @unlink($file);
    }
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try { verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId(); }
catch (Exception $e) {
    rh_log('ERRO', 'Autenticação falhou', ['msg' => $e->getMessage()]);
    retornar_json(false, 'Não autenticado');
}

$metodo = $_SERVER['REQUEST_METHOD'];
$body   = ($metodo !== 'GET') ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$acao   = $_GET['acao'] ?? $body['acao'] ?? '';
$conn   = conectar_banco();
if (!$conn) {
    rh_log('ERRO', 'Falha ao conectar ao banco de dados');
    retornar_json(false, 'Erro ao conectar ao banco');
}

rh_log('INFO', "Requisição recebida", ['metodo' => $metodo, 'acao' => $acao, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);

// DDL e correções de dados são exclusivas das migrations de deploy.
try { _migrar_rh_escala($conn); }
catch (Throwable $e) { error_log('[RH Escala] Schema pendente; tenant='.(int)$tenant_id.'; erro='.$e->getMessage()); fechar_conexao($conn); http_response_code(503); retornar_json(false,'Estrutura de escalas pendente. Aplique as migrations RH antes de continuar.'); }

function _migrar_rh_escala(mysqli $conn): void {
    $res=$conn->query('SHOW COLUMNS FROM rh_escala');
    if(!$res)throw new RuntimeException('Não foi possível verificar rh_escala.');
    $cols=[];$types=[];while($row=$res->fetch_assoc()){$cols[]=$row['Field'];if($row['Field']==='tipo')$types[]=(string)$row['Type'];}$res->free();
    foreach(['tenant_id','colaborador_id','carga_horaria_mensal_min','descanso_interjornada_min','regime_12x36','banco_horas_ativo','limite_saldo_negativo_min','politica_extra_excedente','limite_extra_diario_min','escala_manual_semana'] as $required) if(!in_array($required,$cols,true))throw new RuntimeException('Coluna ausente: '.$required);
    if(!$types||strpos($types[0],"'escala_manual'")===false)throw new RuntimeException('ENUM de jornada pendente.');
}

function rh_escala_exigir_rbac(mysqli $conn,string $acao):void {
    if(function_exists('rbacTabelasDisponiveis')&&rbacTabelasDisponiveis($conn)){rbacExigir($conn,'recursos_humanos',$acao,['registro_tipo'=>'rh_escala']);return;}
    verificarPermissao($acao==='visualizar'?'visualizador':'gerente');
}

// ── LISTAR ────────────────────────────────────────────────────────────────────
if ($acao === 'listar') {
    rh_escala_exigir_rbac($conn,'visualizar');
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    if ($colab_id <= 0) {
        rh_log('AVISO', 'listar: colaborador_id não informado');
        retornar_json(false, 'colaborador_id obrigatório');
    }
    $stColab = $conn->prepare('SELECT id FROM rh_colaboradores WHERE tenant_id=? AND id=? LIMIT 1');
    if (!$stColab) { fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $stColab->bind_param('ii', $tenant_id, $colab_id);
    if (!$stColab->execute()) { $stColab->close(); fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $colabExiste = $stColab->get_result()->num_rows > 0;
    $stColab->close();
    if (!$colabExiste) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Colaborador não encontrado.'); }

    try {
        $stmt = $conn->prepare(
            "SELECT e.*, c.nome as colaborador_nome
             FROM rh_escala e
             JOIN rh_colaboradores c ON c.id = e.colaborador_id AND c.tenant_id = e.tenant_id
             WHERE e.tenant_id = ? AND e.colaborador_id = ? AND e.ativo = 1
             ORDER BY e.nome_escala ASC"
        );
        if (!$stmt) {
            rh_log('ERRO', 'listar: prepare falhou', ['mysql_error' => $conn->error]);
            fechar_conexao($conn); retornar_json(false, 'Não foi possível consultar escalas.');
        }
        $stmt->bind_param('ii', $tenant_id, $colab_id);
        $stmt->execute();
        $list = [];
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $list[] = $r;
        $stmt->close(); fechar_conexao($conn);
        rh_log('INFO', 'listar: OK', ['colaborador_id' => $colab_id, 'total' => count($list)]);
        retornar_json(true, 'OK', $list);
    } catch (Throwable $e) {
        rh_log('ERRO', 'listar: exceção', ['msg' => $e->getMessage()]);
        fechar_conexao($conn); retornar_json(false, 'Não foi possível listar escalas.');
    }
}

// ── OBTER ─────────────────────────────────────────────────────────────────────
if ($acao === 'obter') {
    rh_escala_exigir_rbac($conn,'visualizar');
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        rh_log('AVISO', 'obter: ID inválido', ['id' => $_GET['id'] ?? 'vazio']);
        retornar_json(false, 'ID inválido');
    }

    try {
        $stmt = $conn->prepare(
            "SELECT e.*, c.nome as colaborador_nome
             FROM rh_escala e
             JOIN rh_colaboradores c ON c.id = e.colaborador_id AND c.tenant_id = e.tenant_id
             WHERE e.tenant_id = ? AND e.id = ?"
        );
        if (!$stmt) {
            rh_log('ERRO', 'obter: prepare falhou', ['mysql_error' => $conn->error]);
            fechar_conexao($conn); retornar_json(false, 'Não foi possível consultar a escala.');
        }
        $stmt->bind_param('ii', $tenant_id, $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close(); fechar_conexao($conn);
        if (!$row) {
            rh_log('AVISO', 'obter: Escala não encontrada', ['id' => $id]);
            retornar_json(false, 'Escala não encontrada');
        }
        retornar_json(true, 'OK', $row);
    } catch (Throwable $e) {
        rh_log('ERRO', 'obter: exceção', ['msg' => $e->getMessage()]);
        fechar_conexao($conn); retornar_json(false, 'Não foi possível consultar a escala.');
    }
}

// ── CRIAR ─────────────────────────────────────────────────────────────────────
if ($acao === 'criar' && $metodo === 'POST') {
    rh_escala_exigir_rbac($conn,'criar');
    rh_log('INFO', 'criar: iniciando', ['body_keys' => array_keys($body)]);

    $d = _extrair_escala($body);

    if ($d['colaborador_id'] <= 0) {
        rh_log('AVISO', 'criar: colaborador_id ausente', $d);
        retornar_json(false, 'colaborador_id obrigatório');
    }
    $stColab = $conn->prepare('SELECT id FROM rh_colaboradores WHERE tenant_id=? AND id=? LIMIT 1');
    if (!$stColab) { fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $stColab->bind_param('ii', $tenant_id, $d['colaborador_id']);
    if (!$stColab->execute()) { $stColab->close(); fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $colabExiste = $stColab->get_result()->num_rows > 0;
    $stColab->close();
    if (!$colabExiste) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Colaborador não encontrado.'); }

    // Validações por tipo de jornada
    $validacao = _validar_regras_jornada($d);
    if ($validacao !== true) {
        rh_log('AVISO', 'criar: validação falhou', ['erro' => $validacao, 'tipo' => $d['tipo']]);
        retornar_json(false, $validacao);
    }

    // Impede escala duplicada: colaborador já tem escala ativa
    $stmtChk = $conn->prepare("SELECT COUNT(*) FROM rh_escala WHERE tenant_id = $tenant_id AND colaborador_id = ? AND ativo = 1");
    if (!$stmtChk) { rh_log('ERRO', 'criar: verificação de escala falhou', ['tenant_id' => $tenant_id, 'errno' => $conn->errno]); fechar_conexao($conn); retornar_json(false, 'Não foi possível validar a escala.'); }
    $stmtChk->bind_param('i', $d['colaborador_id']);
    $stmtChk->execute();
    $stmtChk->bind_result($qtdExistente);
    $stmtChk->fetch();
    $stmtChk->close();
    if ($qtdExistente > 0) {
        rh_log('AVISO', 'criar: colaborador já possui escala ativa', ['colaborador_id' => $d['colaborador_id']]);
        retornar_json(false, 'Este colaborador já possui uma escala cadastrada. Edite a escala existente.');
    }

    $dias_json = json_encode($d['dias_trabalho'] ?? ['seg','ter','qua','qui','sex']);
    $semA_json = $d['alternada_semana_a'] ? json_encode($d['alternada_semana_a']) : null;
    $semB_json = $d['alternada_semana_b'] ? json_encode($d['alternada_semana_b']) : null;

    // Verificar se as colunas extras existem (compatibilidade)
    $temColunasExtras = _verificar_colunas_extras($conn);

    if ($temColunasExtras) {
        $stmt = $conn->prepare(
            "INSERT INTO rh_escala
             (tenant_id, colaborador_id, nome_escala, tipo, carga_horaria_diaria_min, dias_trabalho,
              hora_entrada, hora_almoco_saida, hora_almoco_retorno, hora_saida,
              tolerancia_minutos, intervalo_almoco_min,
              alternada_ativa, alternada_dia_inicio, alternada_semana_a, alternada_semana_b, alternada_tipo_folga,
              carga_horaria_mensal_min, descanso_interjornada_min, regime_12x36, banco_horas_ativo,
              limite_saldo_negativo_min,politica_extra_excedente,limite_extra_diario_min)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        if (!$stmt) {
            rh_log('ERRO', 'criar: prepare falhou', ['mysql_error' => $conn->error]);
            retornar_json(false, 'Não foi possível preparar o cadastro da escala.');
        }
        // 22 params: tenant, colaborador e campos da escala
        $stmt->bind_param('iississsssiiissssiiiiisi',
            $tenant_id,
            $d['colaborador_id'],
            $d['nome_escala'],
            $d['tipo'],
            $d['carga_horaria_diaria_min'],
            $dias_json,
            $d['hora_entrada'],
            $d['hora_almoco_saida'],
            $d['hora_almoco_retorno'],
            $d['hora_saida'],
            $d['tolerancia_minutos'],
            $d['intervalo_almoco_min'],
            $d['alternada_ativa'],
            $d['alternada_dia_inicio'],
            $semA_json,
            $semB_json,
            $d['alternada_tipo_folga'],
            $d['carga_horaria_mensal_min'],
            $d['descanso_interjornada_min'],
            $d['regime_12x36'],
            $d['banco_horas_ativo'],
            $d['limite_saldo_negativo_min'],
            $d['politica_extra_excedente'],
            $d['limite_extra_diario_min']
        );
    } else {
        // Fallback: colunas antigas apenas (sem as novas)
        $stmt = $conn->prepare(
            "INSERT INTO rh_escala
             (tenant_id, colaborador_id, nome_escala, tipo, carga_horaria_diaria_min, dias_trabalho,
              hora_entrada, hora_almoco_saida, hora_almoco_retorno, hora_saida,
              tolerancia_minutos, intervalo_almoco_min,
              alternada_ativa, alternada_dia_inicio, alternada_semana_a, alternada_semana_b, alternada_tipo_folga)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        if (!$stmt) {
            rh_log('ERRO', 'criar: prepare (fallback) falhou', ['mysql_error' => $conn->error]);
            fechar_conexao($conn); retornar_json(false, 'Não foi possível preparar o cadastro da escala.');
        }
        // 17 parâmetros incluindo tenant autenticado
        $stmt->bind_param('iississsssiiissss',
            $tenant_id,
            $d['colaborador_id'],
            $d['nome_escala'],
            $d['tipo'],
            $d['carga_horaria_diaria_min'],
            $dias_json,
            $d['hora_entrada'],
            $d['hora_almoco_saida'],
            $d['hora_almoco_retorno'],
            $d['hora_saida'],
            $d['tolerancia_minutos'],
            $d['intervalo_almoco_min'],
            $d['alternada_ativa'],
            $d['alternada_dia_inicio'],
            $semA_json,
            $semB_json,
            $d['alternada_tipo_folga']
        );
    }

    if (!$stmt->execute()) {
        $erro = $conn->error;
        $stmt->close(); fechar_conexao($conn);
        rh_log('ERRO', 'criar: execute falhou', ['mysql_error' => $erro, 'tipo' => $d['tipo']]);
        retornar_json(false, 'Não foi possível criar a escala.');
    }
    $novo_id = $conn->insert_id;
    $stmt->close();
    // Para escala_manual: salva o horário por dia (coluna separada, sem alterar bind_param acima)
    if ($d['tipo'] === 'escala_manual' && !empty($d['escala_manual_semana'])) {
        try {
            $msJson = $conn->real_escape_string($d['escala_manual_semana']);
            $conn->query("UPDATE rh_escala SET escala_manual_semana = '$msJson' WHERE tenant_id = $tenant_id AND id = $novo_id");
        } catch (Throwable $e) { }
    }
    fechar_conexao($conn);
    rh_log('INFO', 'criar: escala criada', ['id' => $novo_id, 'tipo' => $d['tipo'], 'colaborador_id' => $d['colaborador_id']]);
    retornar_json(true, 'Escala criada com sucesso', ['id' => $novo_id]);
}

// ── ATUALIZAR ─────────────────────────────────────────────────────────────────
if ($acao === 'atualizar' && $metodo === 'POST') {
    rh_escala_exigir_rbac($conn,'editar');
    $id = intval($_GET['id'] ?? $body['id'] ?? 0);
    if ($id <= 0) {
        rh_log('AVISO', 'atualizar: ID inválido', ['id' => $_GET['id'] ?? $body['id'] ?? 'vazio']);
        retornar_json(false, 'ID inválido');
    }

    $d = _extrair_escala($body);

    if ($d['colaborador_id'] <= 0) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'colaborador_id obrigatório'); }
    $stColab = $conn->prepare('SELECT id FROM rh_colaboradores WHERE tenant_id=? AND id=? LIMIT 1');
    if (!$stColab) { fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $stColab->bind_param('ii', $tenant_id, $d['colaborador_id']);
    if (!$stColab->execute()) { $stColab->close(); fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $colabExiste = $stColab->get_result()->num_rows > 0;
    $stColab->close();
    if (!$colabExiste) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Colaborador não encontrado.'); }

    $validacao = _validar_regras_jornada($d);
    if ($validacao !== true) {
        rh_log('AVISO', 'atualizar: validação falhou', ['erro' => $validacao, 'tipo' => $d['tipo']]);
        retornar_json(false, $validacao);
    }

    $dias_json2 = json_encode($d['dias_trabalho'] ?? ['seg','ter','qua','qui','sex']);
    $semA_json2 = $d['alternada_semana_a'] ? json_encode($d['alternada_semana_a']) : null;
    $semB_json2 = $d['alternada_semana_b'] ? json_encode($d['alternada_semana_b']) : null;

    $temColunasExtras2 = _verificar_colunas_extras($conn);

    if ($temColunasExtras2) {
        $stmt = $conn->prepare(
            "UPDATE rh_escala SET
             nome_escala=?, tipo=?, carga_horaria_diaria_min=?, dias_trabalho=?,
             hora_entrada=?, hora_almoco_saida=?, hora_almoco_retorno=?, hora_saida=?,
             tolerancia_minutos=?, intervalo_almoco_min=?,
             alternada_ativa=?, alternada_dia_inicio=?, alternada_semana_a=?, alternada_semana_b=?, alternada_tipo_folga=?,
             carga_horaria_mensal_min=?, descanso_interjornada_min=?, regime_12x36=?, banco_horas_ativo=?,
             limite_saldo_negativo_min=?,politica_extra_excedente=?,limite_extra_diario_min=? WHERE tenant_id = $tenant_id AND id=?"
        );
        if (!$stmt) {
            rh_log('ERRO', 'atualizar: prepare falhou', ['mysql_error' => $conn->error]);
            retornar_json(false, 'Não foi possível preparar a atualização da escala.');
        }
        // 21 params: campos escala + limite negativo opcional + id
        $stmt->bind_param('ssisssssiiissssiiiiisii',
            $d['nome_escala'],
            $d['tipo'],
            $d['carga_horaria_diaria_min'],
            $dias_json2,
            $d['hora_entrada'],
            $d['hora_almoco_saida'],
            $d['hora_almoco_retorno'],
            $d['hora_saida'],
            $d['tolerancia_minutos'],
            $d['intervalo_almoco_min'],
            $d['alternada_ativa'],
            $d['alternada_dia_inicio'],
            $semA_json2,
            $semB_json2,
            $d['alternada_tipo_folga'],
            $d['carga_horaria_mensal_min'],
            $d['descanso_interjornada_min'],
            $d['regime_12x36'],
            $d['banco_horas_ativo'],
            $d['limite_saldo_negativo_min'],
            $d['politica_extra_excedente'],
            $d['limite_extra_diario_min'],
            $id
        );
    } else {
        $stmt = $conn->prepare(
            "UPDATE rh_escala SET
             nome_escala=?, tipo=?, carga_horaria_diaria_min=?, dias_trabalho=?,
             hora_entrada=?, hora_almoco_saida=?, hora_almoco_retorno=?, hora_saida=?,
             tolerancia_minutos=?, intervalo_almoco_min=?,
             alternada_ativa=?, alternada_dia_inicio=?, alternada_semana_a=?, alternada_semana_b=?, alternada_tipo_folga=? WHERE tenant_id=? AND id=?"
        );
        if (!$stmt) {
            rh_log('ERRO', 'atualizar: prepare (fallback) falhou', ['mysql_error' => $conn->error]);
            retornar_json(false, 'Não foi possível preparar a atualização da escala.');
        }
        // 17 parâmetros: s s i s  s s s s  i i  i s s s s  i i
        $stmt->bind_param('ssisssssiiissssii',
            $d['nome_escala'],
            $d['tipo'],
            $d['carga_horaria_diaria_min'],
            $dias_json2,
            $d['hora_entrada'],
            $d['hora_almoco_saida'],
            $d['hora_almoco_retorno'],
            $d['hora_saida'],
            $d['tolerancia_minutos'],
            $d['intervalo_almoco_min'],
            $d['alternada_ativa'],
            $d['alternada_dia_inicio'],
            $semA_json2,
            $semB_json2,
            $d['alternada_tipo_folga'],
            $tenant_id,
            $id
        );
    }

    if (!$stmt->execute()) {
        $erro = $conn->error;
        $stmt->close(); fechar_conexao($conn);
        rh_log('ERRO', 'atualizar: execute falhou', ['mysql_error' => $erro, 'id' => $id]);
        retornar_json(false, 'Não foi possível atualizar a escala.');
    }
    $stmt->close();
    // Para escala_manual: atualiza o horário por dia (coluna separada)
    if ($d['tipo'] === 'escala_manual' && !empty($d['escala_manual_semana'])) {
        try {
            $msJson = $conn->real_escape_string($d['escala_manual_semana']);
            $conn->query("UPDATE rh_escala SET escala_manual_semana = '$msJson' WHERE tenant_id = $tenant_id AND id = $id");
        } catch (Throwable $e) { }
    }
    fechar_conexao($conn);
    rh_log('INFO', 'atualizar: escala atualizada', ['id' => $id, 'tipo' => $d['tipo']]);
    retornar_json(true, 'Escala atualizada com sucesso');
}

// ── EXCLUIR (soft delete) ─────────────────────────────────────────────────────
if ($metodo === 'DELETE') {
    rh_escala_exigir_rbac($conn,'excluir');
    $body2 = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = intval($body2['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        rh_log('AVISO', 'excluir: ID inválido');
        retornar_json(false, 'ID inválido');
    }

    $stmt = $conn->prepare("UPDATE rh_escala SET ativo=0 WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute(); $stmt->close(); fechar_conexao($conn);
    rh_log($ok ? 'INFO' : 'ERRO', 'excluir: ' . ($ok ? 'OK' : 'falhou'), ['id' => $id]);
    retornar_json($ok, $ok ? 'Escala removida' : 'Erro ao remover');
}

fechar_conexao($conn);
rh_log('AVISO', 'Ação não reconhecida', ['acao' => $acao, 'metodo' => $metodo]);
retornar_json(false, 'Ação não reconhecida');

// ── HELPERS ───────────────────────────────────────────────────────────────────

/**
 * Verifica se as colunas extras (v2) existem na tabela rh_escala.
 */
function _verificar_colunas_extras(mysqli $conn): bool {
    $res = $conn->query("SHOW COLUMNS FROM rh_escala LIKE 'banco_horas_ativo'");
    return $res && $res->num_rows > 0;
}

/**
 * Valida as regras de negócio por tipo de jornada.
 * Retorna true se válido, ou string com mensagem de erro.
 */
function _validar_regras_jornada(array $d) {
    $tipo = $d['tipo'];

    if (!in_array($d['politica_extra_excedente'], ['CREDITAR_TUDO', 'LIMITAR_E_SEPARAR'], true)) {
        return 'Política de excedente inválida.';
    }
    if ($d['limite_extra_diario_min'] < 1 || $d['limite_extra_diario_min'] > 2147483647) {
        return 'O limite diário deve ser um inteiro de minutos entre 1 e 2.147.483.647.';
    }

    if ($d['limite_saldo_negativo_min'] !== null && ($d['limite_saldo_negativo_min'] < 0 || $d['limite_saldo_negativo_min'] > 2147483647)) {
        return 'O limite de saldo negativo deve ser um número inteiro de minutos entre 0 e 2.147.483.647, ou ficar vazio para sem limite.';
    }

    // ── Escala Alternada ──────────────────────────────────────────────────────
    // Suporta qualquer carga diária — não restrita a 12h.
    // O sistema calcula a carga mensal com base nos dias reais de Semana A + B.
    if ($tipo === 'alternada') {
        if (empty($d['alternada_dia_inicio'])) {
            return 'Escala alternada: informe a data de início da Semana A.';
        }
        if (empty($d['alternada_semana_a']) || empty($d['alternada_semana_b'])) {
            return 'Escala alternada: selecione pelo menos um dia em cada semana (A e B).';
        }
        if ($d['carga_horaria_diaria_min'] <= 0) {
            return 'Escala alternada: informe a carga horária diária.';
        }
        // Regime 12x36: descanso mínimo de 36h entre jornadas
        if ($d['regime_12x36'] && $d['descanso_interjornada_min'] > 0 && $d['descanso_interjornada_min'] < 2160) {
            return 'Regime 12x36: o descanso entre jornadas deve ser de no mínimo 36 horas.';
        }
    }

    // ── Controle de Jornada ───────────────────────────────────────────────────
    if ($tipo === 'controle_jornada') {
        if (empty($d['hora_entrada']) || empty($d['hora_saida'])) {
            return 'Controle de jornada: hora de entrada e saída são obrigatórias.';
        }
        if (empty($d['dias_trabalho']) || count($d['dias_trabalho']) === 0) {
            return 'Controle de jornada: selecione pelo menos um dia de trabalho.';
        }
        if ($d['descanso_interjornada_min'] > 0 && $d['descanso_interjornada_min'] < 660) {
            return 'Controle de jornada: o descanso entre jornadas deve ser de no mínimo 11 horas (CLT).';
        }
    }

    // ── Escala Manual ─────────────────────────────────────────────────────────
    if ($tipo === 'escala_manual') {
        $ms = $d['escala_manual_semana'] ?? null;
        if (empty($ms)) return 'Escala manual: configure pelo menos um dia de trabalho.';
        $decoded = is_string($ms) ? json_decode($ms, true) : (array)$ms;
        $temAtivo = false;
        foreach ((array)$decoded as $cfg) { if (!empty($cfg['ativo'])) { $temAtivo = true; break; } }
        if (!$temAtivo) return 'Escala manual: ative pelo menos um dia de trabalho.';
    }

    // ── Jornadas CLT padrão (44h/40h/36h) ────────────────────────────────────
    if (in_array($tipo, ['jornada_44h', 'jornada_40h', 'jornada_36h'])) {
        if (empty($d['hora_entrada']) || empty($d['hora_saida'])) {
            return 'Jornada: hora de entrada e saída são obrigatórias.';
        }
        if ($d['descanso_interjornada_min'] > 0 && $d['descanso_interjornada_min'] < 660) {
            return 'Descanso entre jornadas deve ser de no mínimo 11 horas (CLT Art. 66).';
        }
    }

    return true;
}

/**
 * Extrai e normaliza os dados da escala do body da requisição.
 */
// Tabela de jornadas CLT padrão — valores autoritativos do servidor
function _jornadas_clt(): array {
    return [
        'jornada_44h' => ['carga_min' => 440,   'mensal_min' => 13200, 'dias' => ['seg','ter','qua','qui','sex','sab']],
        'jornada_40h' => ['carga_min' => 480,   'mensal_min' => 12000, 'dias' => ['seg','ter','qua','qui','sex']],
        'jornada_36h' => ['carga_min' => 360,   'mensal_min' => 10800, 'dias' => ['seg','ter','qua','qui','sex','sab']],
    ];
}

function _extrair_escala(array $b): array {
    $n = fn($k, $def=null) => isset($b[$k]) && $b[$k] !== '' ? $b[$k] : $def;
    $limiteNegativoRaw = $n('limite_saldo_negativo_min', null);
    $limiteNegativo = $limiteNegativoRaw === null
        ? null
        : (is_scalar($limiteNegativoRaw) && preg_match('/^\d+$/', (string)$limiteNegativoRaw) ? (int)$limiteNegativoRaw : -1);
    $politicaExtra = (string)$n('politica_extra_excedente', 'CREDITAR_TUDO');
    $limiteExtraRaw = $n('limite_extra_diario_min', 120);
    $limiteExtra = is_scalar($limiteExtraRaw) && preg_match('/^\d+$/', (string)$limiteExtraRaw)
        ? (int)$limiteExtraRaw : -1;

    $tipo        = $n('tipo', 'livre');
    $isAlternada = ($tipo === 'alternada');
    $isControle  = ($tipo === 'controle_jornada');
    $is12x36     = (bool)$n('regime_12x36', false);
    $jornPreset  = _jornadas_clt()[$tipo] ?? null;
    $isJornada   = $jornPreset !== null;

    // Semana A e B podem vir como array ou JSON string
    $semA = $n('alternada_semana_a', null);
    $semB = $n('alternada_semana_b', null);
    if (is_string($semA)) $semA = json_decode($semA, true);
    if (is_string($semB)) $semB = json_decode($semB, true);

    // Carga diária em minutos
    // Para jornadas CLT: calcula a partir do horário programado real (threshold de extras/atrasos).
    // Exemplo: 07:00 → 16:00 − 60min almoço = 480 min (8h). NÃO usa o divisor 44÷6 = 7h20min,
    // porque o divisor oficial 220h/mês é para fins de folha, não para controle de ponto diário.
    if ($isJornada) {
        $hEnt   = (string)($n('hora_entrada', '') ?? '');
        $hSai   = (string)($n('hora_saida',   '') ?? '');
        $intMin = intval($n('intervalo_almoco_min', 60));
        $cargaDiaria = $jornPreset['carga_min']; // fallback (ex: 440 para 44h)
        if ($hEnt !== '' && $hSai !== '') {
            $partsE = explode(':', $hEnt);
            $partsS = explode(':', $hSai);
            $entMin = intval($partsE[0]) * 60 + intval($partsE[1] ?? 0);
            $saiMin = intval($partsS[0]) * 60 + intval($partsS[1] ?? 0);
            $calc   = $saiMin - $entMin - $intMin;
            if ($calc > 60) $cargaDiaria = $calc; // só aceita se > 1h (sanidade)
        }
    } else {
        $cargaDiaria = intval($n('carga_horaria_diaria_min', 480));
    }

    // Carga mensal
    if ($isJornada) {
        // Jornadas CLT: divisor oficial fixo (MTE)
        $cargaMensal = $jornPreset['mensal_min'];
    } else {
        $cargaMensal = intval($n('carga_horaria_mensal_min', 0));
        if ($cargaMensal <= 0 && $isAlternada) {
            $diasA = is_array($semA) ? count($semA) : 0;
            $diasB = is_array($semB) ? count($semB) : 0;
            $diasPorCiclo = $diasA + $diasB;
            if ($diasPorCiclo > 0) {
                $cargaMensal = intval(round($diasPorCiclo * 2.143 * $cargaDiaria));
            } else {
                $cargaMensal = $cargaDiaria * 15;
            }
        }
    }

    // Dias de trabalho
    // Para jornadas CLT: usa os dias do preset como padrão se o front não enviou nada válido
    $diasDefault = $isJornada ? $jornPreset['dias'] : ['seg','ter','qua','qui','sex'];
    $diasTrabalho = $n('dias_trabalho', null);
    if (empty($diasTrabalho)) $diasTrabalho = $diasDefault;

    // Descanso interjornada padrão por tipo
    $descanso = intval($n('descanso_interjornada_min', 0));
    if ($descanso <= 0) {
        if ($isAlternada && $is12x36) $descanso = 2160; // 36h
        elseif ($isControle || $isJornada) $descanso = 660; // 11h (CLT Art. 66)
    }

    // Escala manual: JSON com horário esperado por dia da semana
    $isManual = ($tipo === 'escala_manual');
    $manualSemana = null;
    if ($isManual) {
        $ms = $n('escala_manual_semana', null);
        if (is_array($ms)) $ms = json_encode($ms, JSON_UNESCAPED_UNICODE);
        $manualSemana = $ms;
        // Para escala_manual: carga_horaria_diaria_min e banco_horas são obrigatoriamente do body
        $cargaDiaria = intval($n('carga_horaria_diaria_min', 480)); // valor genérico (não usado no cálculo)
    }

    return [
        'colaborador_id'           => intval($n('colaborador_id', 0)),
        'nome_escala'              => trim($n('nome_escala', 'Principal')),
        'tipo'                     => $tipo,
        'carga_horaria_diaria_min' => $cargaDiaria,
        'dias_trabalho'            => $diasTrabalho,
        'hora_entrada'             => $n('hora_entrada', '08:00:00'),
        'hora_almoco_saida'        => $n('hora_almoco_saida', '12:00:00'),
        'hora_almoco_retorno'      => $n('hora_almoco_retorno', '13:00:00'),
        'hora_saida'               => $n('hora_saida', '17:00:00'),
        'tolerancia_minutos'       => intval($n('tolerancia_minutos', 10)),
        'intervalo_almoco_min'     => intval($n('intervalo_almoco_min', 60)),
        // Escala alternada
        'alternada_ativa'          => $isAlternada ? 1 : 0,
        'alternada_dia_inicio'     => $isAlternada ? $n('alternada_dia_inicio', null) : null,
        'alternada_semana_a'       => $isAlternada ? $semA : null,
        'alternada_semana_b'       => $isAlternada ? $semB : null,
        'alternada_tipo_folga'     => $isAlternada ? $n('alternada_tipo_folga', 'folga') : 'folga',
        // Campos v2 (novas colunas)
        'carga_horaria_mensal_min' => $cargaMensal,
        'descanso_interjornada_min'=> $descanso,
        'regime_12x36'             => $is12x36 ? 1 : 0,
        'banco_horas_ativo'        => (($isControle || $isJornada || $isManual) && !empty($n('banco_horas_ativo'))) ? 1 : 0,
        'limite_saldo_negativo_min' => $limiteNegativo,
        'politica_extra_excedente' => $politicaExtra,
        'limite_extra_diario_min' => $limiteExtra,
        // Escala manual
        'escala_manual_semana'     => $manualSemana,
    ];
}
?>
