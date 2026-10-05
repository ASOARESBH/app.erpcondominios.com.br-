<?php
// =====================================================
// API PARA REGISTROS DE ACESSO v3
// Novidades: tipo_acesso (Entrada/Saída), dependente_id, modo_registro e vestimenta
// Mantém compatibilidade com colunas opcionais via
// _tem_coluna() + ALTER TABLE automático
// =====================================================

session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/access_control_notification_helper.php';
require_once __DIR__ . '/helpers/alertas_acesso_helper.php';
require_once __DIR__ . '/helpers/visitantes_config_helper.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// ===== LOG DE DEBUG =====
function log_registro($msg, $dados = null) {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($dados !== null) $linha .= ' | ' . json_encode($dados, JSON_UNESCAPED_UNICODE);
    @file_put_contents($dir . '/registro.txt', $linha . PHP_EOL, FILE_APPEND);
}

// ===== RETORNO JSON =====
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$metodo  = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
$tenant_id = exigirTenantId();

// ===== VERIFICAR / CRIAR COLUNAS EXTRAS =====
function _tem_coluna($conexao, $tabela, $coluna) {
    $r = $conexao->query("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
    return $r && $r->num_rows > 0;
}

function _garantir_coluna($conexao, $tabela, $coluna, $definicao) {
    if (_tem_coluna($conexao, $tabela, $coluna)) return;
    $ok = @$conexao->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
    if (!$ok && $conexao->errno !== 1060) {
        log_registro("ERRO ao garantir coluna $coluna em $tabela", ['erro' => $conexao->error, 'errno' => $conexao->errno]);
    } elseif ($ok) {
        log_registro("ALTER TABLE: coluna $coluna adicionada a $tabela");
    }
}

function _tem_indice($conexao, $tabela, $nomeIndice) {
    $stmt = $conexao->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ss', $tabela, $nomeIndice);
    $stmt->execute();
    $existe = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $existe;
}

function _garantir_indice_unico($conexao, $tabela, $nomeIndice, $definicaoColunas) {
    if (_tem_indice($conexao, $tabela, $nomeIndice)) return;
    $ok = @$conexao->query("ALTER TABLE `$tabela` ADD UNIQUE KEY `$nomeIndice` ($definicaoColunas)");
    if (!$ok && $conexao->errno !== 1061) {
        log_registro("ERRO ao garantir índice $nomeIndice em $tabela", ['erro' => $conexao->error, 'errno' => $conexao->errno]);
    } elseif ($ok) {
        log_registro("ALTER TABLE: índice $nomeIndice adicionado a $tabela");
    }
}

// Garantir colunas novas (cria automaticamente se não existirem)
_garantir_coluna($conexao, 'registros_acesso', 'tipo_acesso',    "ENUM('Entrada','Saída') DEFAULT 'Entrada'");
_garantir_coluna($conexao, 'registros_acesso', 'dependente_id',  "INT NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'visitante_id',   "INT NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'documento_visitante', "VARCHAR(30) NULL DEFAULT NULL");
// Ocupantes de veículo: cada ocupante vira seu próprio registro_acesso, marcado
// como OCUPANTE e apontando para o registro TITULAR do mesmo veículo/horário.
// Isso permite que a mesma pessoa seja titular em um veículo e ocupante em outro
// sem que os dois papéis se confundam no histórico.
_garantir_coluna($conexao, 'registros_acesso', 'papel_veiculo',  "ENUM('TITULAR','OCUPANTE') NOT NULL DEFAULT 'TITULAR'");
_garantir_coluna($conexao, 'registros_acesso', 'registro_titular_id', "INT NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'modo_registro', "ENUM('VEICULO','PEDESTRE') NOT NULL DEFAULT 'VEICULO'");
_garantir_coluna($conexao, 'registros_acesso', 'vestimenta',    "VARCHAR(120) NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'usuario_liberou', "VARCHAR(150) NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'idempotency_key', "VARCHAR(36) NULL DEFAULT NULL");
_garantir_indice_unico($conexao, 'registros_acesso', 'uk_registros_acesso_tenant_idempotency', 'tenant_id, idempotency_key');

$tem_tipo_acesso     = true; // acabou de garantir
$tem_dependente_id   = true;
$tem_visitante_id    = true;
$tem_documento_visit = true;

// ===== VALIDAR CADASTRO DE VISITANTE/PRESTADOR (titular ou ocupante) =====
// Confirma que o visitante pertence ao tenant. O documento digitalizado só é
// exigido quando ESTE condomínio marcou "Documento digitalizado" como
// obrigatório em Configurações > Sistema > Visitantes — a mesma regra usada
// no cadastro do visitante (visitantes_obter_config_campos). Antes, o Registro
// Manual exigia o anexo sempre, mesmo para tenants que configuraram o anexo
// como opcional no cadastro — bloqueando o acesso de gente que o próprio
// condomínio decidiu não exigir identificação digitalizada.
function _validar_visitante_para_registro($conexao, $tenant_id, $visitante_id, $anexoObrigatorio) {
    $visitante_id = (int)$visitante_id;
    if ($visitante_id <= 0) {
        return ['ok' => false, 'mensagem' => 'Localize um visitante/prestador cadastrado pelo documento.'];
    }

    $stmtVisitante = $conexao->prepare(
        'SELECT id, nome_completo, documento, tipo_documento, documento_arquivo FROM visitantes WHERE tenant_id = ? AND id = ? LIMIT 1'
    );
    if (!$stmtVisitante) {
        return ['ok' => false, 'mensagem' => 'Erro ao validar o cadastro do visitante.'];
    }
    $stmtVisitante->bind_param('ii', $tenant_id, $visitante_id);
    $stmtVisitante->execute();
    $visitante = $stmtVisitante->get_result()->fetch_assoc();
    $stmtVisitante->close();

    if (!$visitante) {
        return ['ok' => false, 'mensagem' => 'O visitante/prestador selecionado não pertence ao condomínio atual.'];
    }

    if ($anexoObrigatorio) {
        if (empty($visitante['documento_arquivo'])) {
            return ['ok' => false, 'mensagem' => 'Cadastro de ' . $visitante['nome_completo'] . ' encontrado, mas falta o documento digitalizado anexado (exigido pela configuração deste condomínio). Cadastre o documento antes de registrar o acesso.'];
        }

        $caminhoDocumento = ltrim((string)$visitante['documento_arquivo'], './');
        $stmtDocumento = $conexao->prepare(
            'SELECT id FROM tenant_arquivos WHERE tenant_id = ? AND caminho_legado = ? AND ativo = 1 LIMIT 1'
        );
        if (!$stmtDocumento) {
            return ['ok' => false, 'mensagem' => 'Erro ao validar o documento digitalizado do visitante.'];
        }
        $stmtDocumento->bind_param('is', $tenant_id, $caminhoDocumento);
        $stmtDocumento->execute();
        $documentoAtivo = $stmtDocumento->get_result()->fetch_assoc();
        $stmtDocumento->close();

        if (!$documentoAtivo) {
            return ['ok' => false, 'mensagem' => 'Cadastro de ' . $visitante['nome_completo'] . ' encontrado, mas o documento digitalizado não está disponível no sistema. Anexe-o novamente antes de registrar o acesso.'];
        }
    }

    return ['ok' => true, 'visitante' => $visitante];
}

// Normaliza um documento (CPF/RG) para comparação — remove pontuação/espaços.
function _normalizar_documento_comparacao($documento) {
    return preg_replace('/[^A-Za-z0-9]/', '', (string)$documento);
}

// ========== RELATÓRIO DE OCUPANTES ==========
// Cada ocupante é uma linha própria de registros_acesso e aponta para o
// lançamento do titular por registro_titular_id. O tenant vem da sessão e
// nunca do corpo/query enviada pelo navegador.
if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'relatorio_ocupantes') {
    $dataInicio = trim((string)($_GET['data_inicio'] ?? ''));
    $dataFim    = trim((string)($_GET['data_fim'] ?? ''));
    $horaInicio = trim((string)($_GET['hora_inicio'] ?? ''));
    $horaFim    = trim((string)($_GET['hora_fim'] ?? ''));
    $placa      = strtoupper(trim((string)($_GET['placa'] ?? '')));
    $modelo     = trim((string)($_GET['modelo'] ?? ''));
    $unidade    = trim((string)($_GET['unidade'] ?? ''));
    $nome       = trim((string)($_GET['nome'] ?? ''));
    $tipo       = trim((string)($_GET['tipo'] ?? ''));
    $liberados  = ($_GET['apenas_liberados'] ?? '') === '1';

    if ($dataInicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) $dataInicio = '';
    if ($dataFim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) $dataFim = '';
    if ($horaInicio !== '' && !preg_match('/^\d{2}:\d{2}$/', $horaInicio)) $horaInicio = '';
    if ($horaFim !== '' && !preg_match('/^\d{2}:\d{2}$/', $horaFim)) $horaFim = '';
    if ($dataInicio === '') $dataInicio = date('Y-m-d');
    if ($dataFim === '') $dataFim = $dataInicio;
    $tipos = array_values(array_intersect(
        ['Morador', 'Visitante', 'Prestador'],
        array_filter(array_map('trim', explode(',', $tipo)))
    ));

    $grupoExpr = 'COALESCE(r.registro_titular_id, r.id)';
    $where = ['r.tenant_id = ?', "(r.papel_veiculo = 'OCUPANTE' OR r.papel_veiculo = 'TITULAR' OR r.papel_veiculo IS NULL)"];
    $params = [$tenant_id];
    $types = 'i';
    if ($dataInicio !== '') { $where[] = 'r.data_hora >= ?'; $params[] = $dataInicio . ' 00:00:00'; $types .= 's'; }
    if ($dataFim !== '') {
        $dataFimExclusiva = date('Y-m-d', strtotime($dataFim . ' +1 day')) . ' 00:00:00';
        $where[] = 'r.data_hora < ?'; $params[] = $dataFimExclusiva; $types .= 's';
    }
    if ($horaInicio !== '') { $where[] = 'TIME(r.data_hora) >= ?'; $params[] = $horaInicio . ':00'; $types .= 's'; }
    if ($horaFim !== '')    { $where[] = 'TIME(r.data_hora) <= ?'; $params[] = $horaFim . ':59'; $types .= 's'; }
    if ($placa !== '')      { $where[] = 'r.placa LIKE ?'; $params[] = '%' . $placa . '%'; $types .= 's'; }
    if ($modelo !== '')     { $where[] = 'r.modelo LIKE ?'; $params[] = '%' . $modelo . '%'; $types .= 's'; }
    if ($unidade !== '') {
        $where[] = "COALESCE(NULLIF(TRIM(mt.unidade), ''), NULLIF(TRIM(rt.unidade_destino), ''), NULLIF(TRIM(r.unidade_destino), '')) LIKE ?";
        $params[] = '%' . $unidade . '%';
        $types .= 's';
    }
    if ($nome !== '') {
        $where[] = '(rt.nome_visitante LIKE ? OR rt.documento_visitante LIKE ?
            OR vt.nome_completo LIKE ? OR vt.documento LIKE ?
            OR mt.nome LIKE ? OR mt.cpf LIKE ?
            OR EXISTS (
                SELECT 1 FROM registros_acesso filtro_pessoa
                LEFT JOIN visitantes filtro_vo ON filtro_vo.id = filtro_pessoa.visitante_id
                    AND filtro_vo.tenant_id = filtro_pessoa.tenant_id
                WHERE filtro_pessoa.tenant_id = r.tenant_id
                  AND (filtro_pessoa.id = $grupoExpr
                       OR filtro_pessoa.registro_titular_id = $grupoExpr)
                  AND (filtro_pessoa.nome_visitante LIKE ? OR filtro_pessoa.documento_visitante LIKE ?
                       OR filtro_vo.nome_completo LIKE ? OR filtro_vo.documento LIKE ?)
            ))';
        $buscaNome = '%' . $nome . '%';
        for ($i = 0; $i < 10; $i++) $params[] = $buscaNome;
        $types .= 'ssssssssss';
    }
    if ($tipo !== '' && !$tipos) {
        $where[] = '1 = 0';
    } elseif ($tipos) {
        $placeholders = implode(', ', array_fill(0, count($tipos), '?'));
        $where[] = "EXISTS (
            SELECT 1 FROM registros_acesso filtro_tipo
            WHERE filtro_tipo.tenant_id = r.tenant_id
              AND (filtro_tipo.id = $grupoExpr
                   OR filtro_tipo.registro_titular_id = $grupoExpr)
              AND filtro_tipo.tipo IN ($placeholders)
        )";
        foreach ($tipos as $tipoFiltro) { $params[] = $tipoFiltro; $types .= 's'; }
    }
    if ($liberados) {
        $where[] = "EXISTS (
            SELECT 1 FROM registros_acesso filtro_liberado
            WHERE filtro_liberado.tenant_id = r.tenant_id
              AND (filtro_liberado.id = $grupoExpr
                   OR filtro_liberado.registro_titular_id = $grupoExpr)
              AND filtro_liberado.liberado = 1
        )";
    }

    $sql = "SELECT
                r.id, r.data_hora, DATE_FORMAT(r.data_hora, '%d/%m/%Y %H:%i:%s') AS data_hora_formatada,
                r.placa, r.modelo, r.cor, r.tipo, r.tipo_acesso, r.status, r.liberado, r.observacao,
                $grupoExpr AS registro_titular_id,
                COALESCE(r.papel_veiculo, 'TITULAR') AS linha_papel,
                r.unidade_destino,
                CASE WHEN r.papel_veiculo = 'OCUPANTE'
                    THEN COALESCE(NULLIF(TRIM(vo.documento), ''), NULLIF(TRIM(r.documento_visitante), ''), 'Não informado')
                    ELSE 'Não informado'
                END AS ocupante_cpf,
                COALESCE(NULLIF(TRIM(vo.tipo_documento), ''),
                    CASE WHEN r.papel_veiculo = 'OCUPANTE' AND NULLIF(TRIM(r.documento_visitante), '') IS NOT NULL THEN 'CPF/Documento' ELSE 'Não informado' END
                ) AS ocupante_tipo_documento,
                rt.data_hora AS titular_data_hora,
                DATE_FORMAT(rt.data_hora, '%d/%m/%Y %H:%i:%s') AS titular_data_hora_formatada,
                rt.placa AS titular_placa, rt.modelo AS titular_modelo, rt.cor AS titular_cor,
                rt.morador_id AS destino_morador_id,
                COALESCE(NULLIF(TRIM(mt.nome), ''), 'Não identificado') AS destino_morador_nome,
                COALESCE(NULLIF(TRIM(mt.unidade), ''), NULLIF(TRIM(rt.unidade_destino), ''), NULLIF(TRIM(r.unidade_destino), ''), 'Não informado') AS destino_unidade,
                COALESCE(NULLIF(TRIM(rt.nome_visitante), ''), NULLIF(TRIM(vt.nome_completo), ''), NULLIF(TRIM(mt.nome), ''), 'Não identificado') AS condutor_nome,
                COALESCE(NULLIF(TRIM(vt.documento), ''), NULLIF(TRIM(rt.documento_visitante), ''), NULLIF(TRIM(mt.cpf), ''), 'Não informado') AS condutor_cpf,
                COALESCE(NULLIF(TRIM(rt.tipo), ''), 'Não informado') AS condutor_tipo,
                COALESCE(NULLIF(TRIM(rt.nome_visitante), ''), NULLIF(TRIM(vt.nome_completo), ''), NULLIF(TRIM(mt.nome), ''), 'Não identificado') AS titular_nome,
                COALESCE(NULLIF(TRIM(rt.tipo), ''), 'Não informado') AS titular_tipo,
                COALESCE(NULLIF(TRIM(vt.documento), ''), NULLIF(TRIM(rt.documento_visitante), ''), NULLIF(TRIM(mt.cpf), ''), 'Não informado') AS titular_cpf,
                COALESCE(NULLIF(TRIM(vt.tipo_documento), ''),
                    CASE
                        WHEN NULLIF(TRIM(rt.documento_visitante), '') IS NOT NULL THEN 'CPF/Documento'
                        WHEN NULLIF(TRIM(mt.cpf), '') IS NOT NULL THEN 'CPF'
                        ELSE 'Não informado'
                    END
                ) AS titular_tipo_documento,
                rt.tipo_acesso AS titular_tipo_acesso, rt.status AS titular_status,
                rt.liberado AS titular_liberado, rt.observacao AS titular_observacao,
                rt.usuario_liberou AS titular_usuario_liberou,
                CASE WHEN r.papel_veiculo = 'OCUPANTE' THEN COALESCE(NULLIF(TRIM(r.nome_visitante), ''), NULLIF(TRIM(vo.nome_completo), ''), 'Não identificado') END AS ocupante_nome,
                CASE WHEN r.papel_veiculo = 'OCUPANTE' THEN COALESCE(NULLIF(TRIM(r.tipo), ''), 'Não informado') END AS ocupante_tipo,
                COALESCE(NULLIF(TRIM(mt.unidade), ''), NULLIF(TRIM(rt.unidade_destino), ''), NULLIF(TRIM(r.unidade_destino), ''), 'Não informado') AS unidade
            FROM registros_acesso r
            LEFT JOIN registros_acesso rt ON rt.id = $grupoExpr
                AND rt.tenant_id = r.tenant_id AND (rt.papel_veiculo = 'TITULAR' OR rt.papel_veiculo IS NULL)
            LEFT JOIN moradores mt ON mt.id = rt.morador_id AND mt.tenant_id = r.tenant_id
            LEFT JOIN visitantes vo ON vo.id = r.visitante_id AND vo.tenant_id = r.tenant_id
            LEFT JOIN visitantes vt ON vt.id = rt.visitante_id AND vt.tenant_id = r.tenant_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.data_hora DESC, r.id DESC";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) retornar_json(false, 'Erro ao preparar relatório de ocupantes: ' . $conexao->error);
    $bindRefs = [&$types];
    foreach ($params as &$param) $bindRefs[] = &$param;
    call_user_func_array([$stmt, 'bind_param'], $bindRefs);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $registrosOcupantes = [];
    while ($row = $resultado->fetch_assoc()) $registrosOcupantes[] = $row;
    $stmt->close();

    retornar_json(true, 'Relatório de ocupantes gerado com sucesso.', [
        'total' => count($registrosOcupantes),
        'registros' => $registrosOcupantes,
        'filtros' => [
            'data_inicio' => $dataInicio, 'data_fim' => $dataFim,
            'hora_inicio' => $horaInicio, 'hora_fim' => $horaFim,
            'placa' => $placa, 'modelo' => $modelo, 'unidade' => $unidade,
            'nome' => $nome, 'tipo' => $tipo, 'apenas_liberados' => $liberados,
        ],
    ]);
}

// ========== LISTAR REGISTROS ==========
if ($metodo === 'GET') {
    $limite = intval($_GET['limite'] ?? 100);
    $limite = min(max($limite, 1), 1000);
    $where = ['r.tenant_id = ?'];
    $params = [$tenant_id];
    $types = 'i';

    $dataInicio = trim((string)($_GET['data_inicio'] ?? ''));
    $dataFim = trim((string)($_GET['data_fim'] ?? ''));
    $horaInicio = trim((string)($_GET['hora_inicio'] ?? ''));
    $horaFim = trim((string)($_GET['hora_fim'] ?? ''));
    $placa = strtoupper(trim((string)($_GET['placa'] ?? '')));
    $modelo = trim((string)($_GET['modelo'] ?? ''));
    $unidade = trim((string)($_GET['unidade'] ?? ''));
    $nome = trim((string)($_GET['nome'] ?? ''));
    $tipoParam = trim((string)($_GET['tipo'] ?? ''));

    if ($dataInicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
        $where[] = 'r.data_hora >= ?'; $params[] = $dataInicio . ' 00:00:00'; $types .= 's';
    }
    if ($dataFim !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
        $dataFimExclusiva = date('Y-m-d', strtotime($dataFim . ' +1 day')) . ' 00:00:00';
        $where[] = 'r.data_hora < ?'; $params[] = $dataFimExclusiva; $types .= 's';
    }
    if ($horaInicio !== '' && preg_match('/^\d{2}:\d{2}$/', $horaInicio)) {
        $where[] = 'TIME(r.data_hora) >= ?'; $params[] = $horaInicio . ':00'; $types .= 's';
    }
    if ($horaFim !== '' && preg_match('/^\d{2}:\d{2}$/', $horaFim)) {
        $where[] = 'TIME(r.data_hora) <= ?'; $params[] = $horaFim . ':59'; $types .= 's';
    }
    if ($placa !== '') { $where[] = 'r.placa LIKE ?'; $params[] = '%' . $placa . '%'; $types .= 's'; }
    if ($modelo !== '') { $where[] = 'r.modelo LIKE ?'; $params[] = '%' . $modelo . '%'; $types .= 's'; }
    if ($unidade !== '') {
        $where[] = '(r.unidade_destino LIKE ? OR m.unidade LIKE ?)';
        $params[] = '%' . $unidade . '%'; $params[] = '%' . $unidade . '%'; $types .= 'ss';
    }
    if ($nome !== '') {
        $where[] = '(r.nome_visitante LIKE ? OR r.documento_visitante LIKE ? OR m.nome LIKE ? OR m.cpf LIKE ? OR d.nome_completo LIKE ?)';
        $buscaNome = '%' . $nome . '%';
        for ($i = 0; $i < 5; $i++) $params[] = $buscaNome;
        $types .= 'sssss';
    }
    if ($tipoParam !== '') {
        $tiposValidos = array_values(array_intersect(
            ['Morador', 'Visitante', 'Prestador'],
            array_filter(array_map('trim', explode(',', $tipoParam)))
        ));
        if (!$tiposValidos) {
            $where[] = '1 = 0';
        } else {
            $placeholders = implode(', ', array_fill(0, count($tiposValidos), '?'));
            $where[] = "r.tipo IN ($placeholders)";
            foreach ($tiposValidos as $tipoValido) { $params[] = $tipoValido; $types .= 's'; }
        }
    }
    if (($_GET['apenas_liberados'] ?? '') === '1') $where[] = 'r.liberado = 1';
    if (($_GET['ignorar_ocupantes'] ?? '') === '1') $where[] = "r.papel_veiculo <> 'OCUPANTE'";

    $sql = "SELECT r.id,
            DATE_FORMAT(r.data_hora, '%d/%m/%Y %H:%i:%s') AS data_hora_formatada,
            r.data_hora, r.placa, r.modelo, r.cor, r.tag, r.tipo,
            r.nome_visitante, r.documento_visitante, r.unidade_destino, r.dias_permanencia,
            r.status, r.liberado, r.observacao,
            r.tipo_acesso, r.dependente_id, r.modo_registro, r.vestimenta, r.usuario_liberou,
            r.papel_veiculo, r.registro_titular_id,
            m.nome AS morador_nome, m.unidade AS morador_unidade,
            d.nome_completo AS dependente_nome
            FROM registros_acesso r
            LEFT JOIN moradores m ON r.morador_id = m.id AND m.tenant_id = r.tenant_id
            LEFT JOIN dependentes d ON r.dependente_id = d.id AND d.tenant_id = r.tenant_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.data_hora DESC, r.id DESC
            LIMIT ?";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        log_registro('ERRO prepare GET', ['erro' => $conexao->error]);
        retornar_json(false, 'Erro ao preparar consulta: ' . $conexao->error);
    }
    $params[] = $limite;
    $types .= 'i';
    $bindRefs = [&$types];
    foreach ($params as &$param) $bindRefs[] = &$param;
    call_user_func_array([$stmt, 'bind_param'], $bindRefs);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $registros = [];
    while ($row = $resultado->fetch_assoc()) {
        $registros[] = $row;
    }
    $stmt->close();
    retornar_json(true, 'Registros listados com sucesso', $registros);
}

// ========== CRIAR REGISTRO MANUAL ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);

    log_registro('POST recebido', $dados);

    $data_hora        = $dados['data_hora']        ?? date('Y-m-d H:i:s');
    $placa            = strtoupper(trim($dados['placa']    ?? ''));
    $modelo           = trim($dados['modelo']           ?? '');
    $cor              = trim($dados['cor']              ?? '');
    $tipo             = trim($dados['tipo']             ?? '');
    $unidade_destino  = trim($dados['unidade_destino']  ?? '');
    $dias_permanencia = intval($dados['dias_permanencia'] ?? 0);
    $nome_visitante   = trim($dados['nome_visitante']   ?? '');
    $observacao       = trim($dados['observacao']       ?? '');
    $tipo_acesso      = trim($dados['tipo_acesso']      ?? 'Entrada');
    $usuario_liberou  = trim((string)($_SESSION['usuario_nome'] ?? ''));
    if ($usuario_liberou === '') $usuario_liberou = null;
    $idempotency_key  = trim((string)($dados['idempotency_key'] ?? ''));
    if (strlen($idempotency_key) > 36) {
        retornar_json(false, 'A chave de idempotência é inválida.');
    }
    if ($idempotency_key === '') $idempotency_key = null;
    $modo_registro    = strtoupper(trim($dados['modo_registro'] ?? 'VEICULO'));
    $vestimenta       = trim($dados['vestimenta'] ?? '');

    // Validar tipo_acesso
    if (!in_array($tipo_acesso, ['Entrada', 'Saída'])) $tipo_acesso = 'Entrada';
    if (!in_array($modo_registro, ['VEICULO', 'PEDESTRE'])) $modo_registro = 'VEICULO';
    if ($modo_registro !== 'PEDESTRE') {
        $vestimenta = null;
    } elseif ($vestimenta === '') {
        $vestimenta = null;
    } else {
        $vestimenta = function_exists('mb_substr') ? mb_substr($vestimenta, 0, 120) : substr($vestimenta, 0, 120);
    }

    // Validações
    if (empty($tipo) || ($modo_registro === 'VEICULO' && empty($placa))) {
        log_registro('ERRO validacao', ['placa' => $placa, 'tipo' => $tipo, 'modo_registro' => $modo_registro]);
        retornar_json(false, $modo_registro === 'PEDESTRE'
            ? 'Tipo é obrigatório'
            : 'Placa e tipo são obrigatórios');
    }

    if (!in_array($tipo, ['Morador', 'Visitante', 'Prestador'])) {
        log_registro('ERRO tipo invalido', ['tipo' => $tipo]);
        retornar_json(false, 'Tipo inválido: ' . $tipo);
    }

    $morador_id   = isset($dados['morador_id'])   && $dados['morador_id']   ? intval($dados['morador_id'])   : null;
    $visitante_id = isset($dados['visitante_id']) && $dados['visitante_id'] ? intval($dados['visitante_id']) : null;
    $dependente_id = isset($dados['dependente_id']) && $dados['dependente_id'] ? intval($dados['dependente_id']) : null;
    $documento    = trim($dados['documento'] ?? '');

    if ($modo_registro === 'PEDESTRE') {
        $placa = '';
        $modelo = '';
        $cor = '';
    }

    // A placa cadastrada é a fonte autoritativa: não confiar em modelo, cor,
    // morador ou unidade enviados pelo navegador, pois campos desabilitados
    // ainda podem ser alterados por requisições manuais.
    if ($modo_registro === 'VEICULO') {
        $stmtVeiculo = $conexao->prepare("SELECT v.modelo, v.cor, v.morador_id, m.nome, m.unidade
            FROM veiculos v INNER JOIN moradores m ON m.id = v.morador_id AND m.tenant_id = ?
            WHERE v.tenant_id = ? AND REPLACE(REPLACE(UPPER(v.placa), '-', ''), ' ', '') = REPLACE(REPLACE(UPPER(?), '-', ''), ' ', '') AND v.ativo = 1 LIMIT 1");
        if ($stmtVeiculo) {
            $stmtVeiculo->bind_param('iis', $tenant_id, $tenant_id, $placa);
            $stmtVeiculo->execute();
            $veiculoCadastrado = $stmtVeiculo->get_result()->fetch_assoc();
            $stmtVeiculo->close();
            if ($veiculoCadastrado) {
                $modelo = trim((string)($veiculoCadastrado['modelo'] ?? ''));
                $cor = trim((string)($veiculoCadastrado['cor'] ?? ''));
                $morador_id = (int)$veiculoCadastrado['morador_id'];
                $unidade_destino = trim((string)($veiculoCadastrado['unidade'] ?? ''));
                $tipo = 'Morador';
            }
        }
    }
    $tag          = null;
    $liberado     = 0;
    $status       = '';

    // Se for morador, buscar no banco pela placa
    if ($tipo === 'Morador') {
        // Se morador_id já foi enviado pelo frontend (seleção manual), usar diretamente
        if ($morador_id) {
            $stmt2 = $conexao->prepare("SELECT nome, unidade FROM moradores WHERE tenant_id = $tenant_id AND id = ?");
            if ($stmt2) {
                $stmt2->bind_param('i', $morador_id);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($res2->num_rows > 0) {
                    $mor = $res2->fetch_assoc();
                    $liberado = 1;
                    $status   = '✅ Acesso liberado - ' . $mor['nome'];
                    $unidade_destino = $mor['unidade'];
                } else {
                    $status   = '🟨 Registro manual - Morador';
                    $liberado = 1;
                }
                $stmt2->close();
            }
        } elseif ($modo_registro === 'VEICULO') {
            // Tentar detectar pela placa
            $stmt2 = $conexao->prepare(
                "SELECT v.tag, v.morador_id, m.nome, m.unidade
                 FROM veiculos v
                 INNER JOIN moradores m ON v.morador_id = m.id
                 WHERE v.tenant_id = ? AND m.tenant_id = ? AND v.placa = ? AND v.ativo = 1"
            );
            if (!$stmt2) {
                log_registro('ERRO prepare busca veiculo', ['erro' => $conexao->error]);
                retornar_json(false, 'Erro interno ao buscar veículo: ' . $conexao->error);
            }
            $stmt2->bind_param('iis', $tenant_id, $tenant_id, $placa);
            $stmt2->execute();
            $resultado2 = $stmt2->get_result();

            if ($resultado2->num_rows > 0) {
                $veiculo         = $resultado2->fetch_assoc();
                $morador_id      = intval($veiculo['morador_id']);
                $tag             = $veiculo['tag'];
                $liberado        = 1;
                $status          = '✅ Acesso liberado - ' . $veiculo['nome'];
                $unidade_destino = $veiculo['unidade'];
            } else {
                $status   = '❌ Acesso negado - Placa não cadastrada';
                $liberado = 0;
            }
            $stmt2->close();
        } else {
            $liberado = 1;
            $status = '✅ Acesso liberado - Morador';
        }

        // Se for dependente, buscar nome do dependente para o status
        if ($dependente_id) {
            $stmtDep = $conexao->prepare("SELECT nome_completo FROM dependentes WHERE tenant_id = $tenant_id AND id = ?");
            if ($stmtDep) {
                $stmtDep->bind_param('i', $dependente_id);
                $stmtDep->execute();
                $resDep = $stmtDep->get_result();
                if ($resDep->num_rows > 0) {
                    $dep = $resDep->fetch_assoc();
                    $status .= ' (Dependente: ' . $dep['nome_completo'] . ')';
                }
                $stmtDep->close();
            }
        }

    } else {
        // Visitante e Prestador compartilham o cadastro-base. O documento
        // digitalizado só é exigido se este condomínio configurou isso como
        // obrigatório (Configurações > Sistema > Visitantes) — a mesma regra
        // aplicada no cadastro do visitante, para não haver exigência
        // contraditória entre "cadastrar" e "liberar o acesso".
        $configVisitantesCampos = visitantes_obter_config_campos($conexao, $tenant_id);
        $anexoObrigatorio = !empty($configVisitantesCampos['documento_digitalizado']['obrigatorio']);

        $validacaoTitular = _validar_visitante_para_registro($conexao, $tenant_id, $visitante_id, $anexoObrigatorio);
        if (!$validacaoTitular['ok']) {
            retornar_json(false, $validacaoTitular['mensagem']);
        }
        $visitante = $validacaoTitular['visitante'];

        if (!$morador_id) {
            retornar_json(false, 'Selecione o morador e a unidade de destino.');
        }
        $stmtDestino = $conexao->prepare('SELECT unidade FROM moradores WHERE tenant_id = ? AND id = ? LIMIT 1');
        if (!$stmtDestino) {
            log_registro('ERRO prepare unidade destino', ['erro' => $conexao->error, 'morador_id' => $morador_id]);
            retornar_json(false, 'Não foi possível validar a unidade de destino.');
        }
        $stmtDestino->bind_param('ii', $tenant_id, $morador_id);
        $stmtDestino->execute();
        $destino = $stmtDestino->get_result()->fetch_assoc();
        $stmtDestino->close();
        if (!$destino) {
            retornar_json(false, 'Morador ou unidade de destino inválido.');
        }
        $unidade_destino = trim((string)$destino['unidade']);

        // Usa somente os dados confirmados no cadastro do tenant.
        $nome_visitante = $visitante['nome_completo'];
        $documento = $visitante['documento'];
        $status   = '🟨 Registro manual - ' . $tipo;
        $liberado = 1;
    }

    // ── Ocupantes do veículo (apenas Visitante/Prestador) ─────────────────────
    // Cada ocupante é validado com a MESMA regra do titular (cadastro no tenant
    // + configuração de anexo) antes de qualquer gravação, para que a falha de
    // um ocupante não deixe o titular salvo sem os ocupantes informados. Também
    // impede duas entradas com o mesmo documento no mesmo lançamento — nem por
    // ID de cadastro nem pelo número do documento — para que um operador não
    // consiga "forçar" a liberação repetindo a mesma pessoa na lista.
    $ocupantesValidados = [];
    if ($modo_registro === 'VEICULO' && ($tipo === 'Visitante' || $tipo === 'Prestador') && !empty($dados['ocupantes']) && is_array($dados['ocupantes'])) {
        $idsJaUsados = [(int)$visitante_id];
        $documentosJaUsados = [_normalizar_documento_comparacao($documento)];
        foreach ($dados['ocupantes'] as $ocupanteRaw) {
            $ocupanteVisitanteId = (int)($ocupanteRaw['visitante_id'] ?? 0);
            if ($ocupanteVisitanteId > 0 && in_array($ocupanteVisitanteId, $idsJaUsados, true)) {
                retornar_json(false, 'Um dos ocupantes informados já é o titular deste acesso ou está repetido na lista de ocupantes.');
            }
            $validacaoOcupante = _validar_visitante_para_registro($conexao, $tenant_id, $ocupanteVisitanteId, $anexoObrigatorio);
            if (!$validacaoOcupante['ok']) {
                retornar_json(false, $validacaoOcupante['mensagem']);
            }
            $documentoOcupanteNormalizado = _normalizar_documento_comparacao($validacaoOcupante['visitante']['documento']);
            if ($documentoOcupanteNormalizado !== '' && in_array($documentoOcupanteNormalizado, $documentosJaUsados, true)) {
                retornar_json(false, 'O documento de ' . $validacaoOcupante['visitante']['nome_completo'] . ' já está registrado neste lançamento (como titular ou outro ocupante). Não é permitido lançar o mesmo documento duas vezes.');
            }
            $idsJaUsados[] = $ocupanteVisitanteId;
            $documentosJaUsados[] = $documentoOcupanteNormalizado;
            $ocupantesValidados[] = $validacaoOcupante['visitante'];
        }
    }

    // ── Montar INSERT dinamicamente (registro titular + ocupantes) ────────────
    // papel_veiculo/registro_titular_id distinguem o condutor/visitante principal
    // dos ocupantes do mesmo veículo, mesmo que um ocupante já seja titular em
    // outro registro (outro veículo) — cada linha é um evento de acesso próprio.
    $cols  = 'tenant_id, data_hora, placa, modelo, cor, tag, tipo, morador_id, nome_visitante, unidade_destino, dias_permanencia, status, liberado, observacao, tipo_acesso, dependente_id, visitante_id, documento_visitante, papel_veiculo, registro_titular_id, modo_registro, vestimenta, usuario_liberou, idempotency_key';
    $marks = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
    // i=tenant_id(1) s=data_hora(2) s=placa(3) s=modelo(4) s=cor(5) s=tag(6) s=tipo(7)
    // i=morador_id(8) s=nome_visitante(9) s=unidade_destino(10)
    // i=dias_permanencia(11) s=status(12) i=liberado(13) s=observacao(14)
    // s=tipo_acesso(15) i=dependente_id(16) i=visitante_id(17) s=documento_visitante(18)
    // s=papel_veiculo(19) i=registro_titular_id(20) s=modo_registro(21) s=vestimenta(22) s=usuario_liberou(23) s=idempotency_key(24)
    $types = 'issssssissisissiis' . 'sissss';
    $sql   = "INSERT INTO registros_acesso ($cols) VALUES ($marks)";

    $conexao->begin_transaction();
    $id_inserido = 0;
    $ocupantesRegistrados = [];
    try {
        // Registro titular
        $papel_veiculo_titular = 'TITULAR';
        $registro_titular_id_nulo = null;
        $params = [
            &$tenant_id, &$data_hora, &$placa, &$modelo, &$cor, &$tag, &$tipo,
            &$morador_id, &$nome_visitante, &$unidade_destino,
            &$dias_permanencia, &$status, &$liberado, &$observacao,
            &$tipo_acesso, &$dependente_id, &$visitante_id, &$documento,
            &$papel_veiculo_titular, &$registro_titular_id_nulo,
            &$modo_registro, &$vestimenta, &$usuario_liberou, &$idempotency_key
        ];

        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new RuntimeException('Erro ao preparar inserção: ' . $conexao->error);
        call_user_func_array([$stmt, 'bind_param'], array_merge([&$types], $params));

        log_registro('INSERT executando (titular)', [
            'placa' => $placa, 'tipo' => $tipo, 'tipo_acesso' => $tipo_acesso,
            'morador_id' => $morador_id, 'dependente_id' => $dependente_id, 'visitante_id' => $visitante_id,
            'modo_registro' => $modo_registro,
        ]);

        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $errno = $stmt->errno;
            $stmt->close();
            if ($errno === 1062 && $idempotency_key !== null) {
                $conexao->rollback();
                $stmtExistente = $conexao->prepare(
                    'SELECT id, data_hora, placa, modelo, cor, tipo, morador_id, nome_visitante,
                            unidade_destino, dias_permanencia, observacao, tipo_acesso,
                            dependente_id, visitante_id, documento_visitante, modo_registro,
                            vestimenta, liberado, status
                     FROM registros_acesso
                     WHERE tenant_id = ? AND idempotency_key = ?
                     LIMIT 1'
                );
                if ($stmtExistente) {
                    $stmtExistente->bind_param('is', $tenant_id, $idempotency_key);
                    $stmtExistente->execute();
                    $existente = $stmtExistente->get_result()->fetch_assoc();
                    $stmtExistente->close();
                    if ($existente) {
                        $mesmo_lancamento =
                            (string)($existente['data_hora'] ?? '') === (string)$data_hora &&
                            (string)($existente['placa'] ?? '') === (string)$placa &&
                            (string)($existente['modelo'] ?? '') === (string)$modelo &&
                            (string)($existente['cor'] ?? '') === (string)$cor &&
                            (string)($existente['tipo'] ?? '') === (string)$tipo &&
                            (int)($existente['morador_id'] ?? 0) === (int)($morador_id ?? 0) &&
                            (string)($existente['nome_visitante'] ?? '') === (string)$nome_visitante &&
                            (string)($existente['unidade_destino'] ?? '') === (string)$unidade_destino &&
                            (int)($existente['dias_permanencia'] ?? 0) === (int)$dias_permanencia &&
                            (string)($existente['observacao'] ?? '') === (string)$observacao &&
                            (string)($existente['tipo_acesso'] ?? '') === (string)$tipo_acesso &&
                            (int)($existente['dependente_id'] ?? 0) === (int)($dependente_id ?? 0) &&
                            (int)($existente['visitante_id'] ?? 0) === (int)($visitante_id ?? 0) &&
                            (string)($existente['documento_visitante'] ?? '') === (string)$documento &&
                            (string)($existente['modo_registro'] ?? '') === (string)$modo_registro &&
                            (string)($existente['vestimenta'] ?? '') === (string)($vestimenta ?? '');

                        if ($mesmo_lancamento) {
                            log_registro('POST idempotente', ['id' => $existente['id'], 'idempotency_key' => $idempotency_key]);
                            retornar_json(true, 'Acesso já havia sido registrado.', [
                                'id' => (int)$existente['id'],
                                'liberado' => (int)$existente['liberado'],
                                'status' => $existente['status'],
                                'tipo_acesso' => $existente['tipo_acesso'],
                                'modo_registro' => $existente['modo_registro'],
                                'vestimenta' => $existente['vestimenta'],
                                'ocupantes_registrados' => [],
                                'idempotente' => true,
                            ]);
                        }

                        log_registro('CONFLITO chave idempotente', [
                            'id_existente' => $existente['id'],
                            'idempotency_key' => $idempotency_key,
                            'placa_existente' => $existente['placa'],
                            'placa_nova' => $placa,
                            'tipo_acesso_existente' => $existente['tipo_acesso'],
                            'tipo_acesso_novo' => $tipo_acesso,
                        ]);
                        retornar_json(false, 'A chave deste lançamento já pertence a outro acesso. Gere uma nova chave para continuar.', [
                            'codigo' => 'IDEMPOTENCY_CONFLICT',
                            'idempotente' => false,
                        ]);
                    }
                }
            }
            throw new RuntimeException('Erro ao criar registro: ' . $erro);
        }
        $id_inserido = $conexao->insert_id;
        $stmt->close();

        // Registros dos ocupantes — mesmo veículo/horário, cada um com seu próprio visitante_id.
        foreach ($ocupantesValidados as $ocupante) {
            $ocNome        = $ocupante['nome_completo'];
            $ocDocumento   = $ocupante['documento'];
            $ocVisitanteId = (int)$ocupante['id'];
            $ocStatus      = '🟨 Ocupante do veículo (' . $tipo . ') — titular: ' . $nome_visitante;
            $ocLiberado    = 1;
            $ocDependente  = null;
            $ocPapel       = 'OCUPANTE';
            $idempotency_key_ocupante = null;

            $paramsOc = [
                &$tenant_id, &$data_hora, &$placa, &$modelo, &$cor, &$tag, &$tipo,
                &$morador_id, &$ocNome, &$unidade_destino,
                &$dias_permanencia, &$ocStatus, &$ocLiberado, &$observacao,
                &$tipo_acesso, &$ocDependente, &$ocVisitanteId, &$ocDocumento,
                &$ocPapel, &$id_inserido, &$modo_registro, &$vestimenta, &$usuario_liberou, &$idempotency_key_ocupante
            ];

            $stmtOc = $conexao->prepare($sql);
            if (!$stmtOc) throw new RuntimeException('Erro ao preparar inserção de ocupante: ' . $conexao->error);
            call_user_func_array([$stmtOc, 'bind_param'], array_merge([&$types], $paramsOc));

            if (!$stmtOc->execute()) {
                $erroOc = $stmtOc->error;
                $stmtOc->close();
                throw new RuntimeException('Erro ao registrar o ocupante ' . $ocNome . ': ' . $erroOc);
            }
            $ocupantesRegistrados[] = ['id' => $conexao->insert_id, 'nome' => $ocNome, 'visitante_id' => $ocVisitanteId];
            $stmtOc->close();
        }

        $conexao->commit();
    } catch (Throwable $erroTransacao) {
        $conexao->rollback();
        log_registro('ERRO transacao registro+ocupantes', ['erro' => $erroTransacao->getMessage()]);
        retornar_json(false, $erroTransacao->getMessage());
    }

    log_registro('INSERT OK', ['id' => $id_inserido, 'status' => $status, 'tipo_acesso' => $tipo_acesso, 'ocupantes' => count($ocupantesRegistrados)]);
    registrar_log('REGISTRO_CRIADO', "Registro manual criado: $placa ($tipo) - $tipo_acesso" . (count($ocupantesRegistrados) ? ' + ' . count($ocupantesRegistrados) . ' ocupante(s)' : ''));

    // O acesso é a operação prioritária. A notificação é complementar e
    // jamais pode desfazer uma entrada/saída registrada com sucesso.
    $notificacao = ['sucesso' => false, 'motivo' => 'nao_processada'];
    try {
        $notificacao = controle_acesso_criar_notificacao_registro(
            $conexao,
            (int)$tenant_id,
            (int)$id_inserido,
            $morador_id ? (int)$morador_id : null,
            $unidade_destino,
            $tipo_acesso,
            $tipo,
            $placa,
            $modelo,
            $data_hora,
            $nome_visitante,
            $documento,
            (string) ($usuario_liberou ?? '')
        );
    } catch (Throwable $erro_notificacao) {
        log_registro('NOTIFICACAO ACESSO FALHOU (não bloqueante)', [
            'registro_id' => $id_inserido,
            'erro' => $erro_notificacao->getMessage(),
        ]);
        $notificacao = ['sucesso' => false, 'motivo' => 'excecao_nao_bloqueante'];
    }
        log_registro('NOTIFICACAO ACESSO PROCESSADA', [
        'registro_id' => $id_inserido,
        'resultado' => $notificacao,
    ]);
    $alertas_disparados = [];
    try {
        $alertas_disparados = alertas_acesso_processar_evento($conexao, (int)$tenant_id, 'registro_manual', [
            'placa' => $placa, 'modelo' => $modelo, 'cor' => $cor,
            'pessoa_nome' => $nome_visitante, 'pessoa_cpf' => $documento,
            'unidade' => $unidade_destino, 'observacao' => $observacao,
            'tipo_acesso' => $tipo_acesso,
        ], 'registro_manual_' . (int)$id_inserido);
    } catch (Throwable $e) {
        log_registro('ALERTA ACESSO FALHOU (não bloqueante)', ['registro_id' => $id_inserido, 'erro' => $e->getMessage()]);
    }
    retornar_json(true, $status, [
        'id' => $id_inserido,
        'liberado' => $liberado,
        'status' => $status,
        'tipo_acesso' => $tipo_acesso,
        'modo_registro' => $modo_registro,
        'vestimenta' => $vestimenta,
        'notificacao_controle_acesso' => $notificacao,
        'alertas_acesso' => $alertas_disparados,
        'ocupantes_registrados' => $ocupantesRegistrados,
    ]);
}

// ========== ATUALIZAR REGISTRO ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);

    $id         = intval($dados['id'] ?? 0);
    $observacao = trim($dados['observacao'] ?? '');
    $status     = trim($dados['status']     ?? '');

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    $stmt = $conexao->prepare('UPDATE registros_acesso SET observacao=?, status=? WHERE tenant_id = $tenant_id AND id=?');
    if (!$stmt) {
        retornar_json(false, 'Erro ao preparar atualização: ' . $conexao->error);
    }
    $stmt->bind_param('ssi', $observacao, $status, $id);

    if ($stmt->execute()) {
        registrar_log('REGISTRO_ATUALIZADO', "Registro atualizado: ID $id");
        retornar_json(true, 'Registro atualizado com sucesso');
    } else {
        retornar_json(false, 'Erro ao atualizar registro: ' . $stmt->error);
    }
    $stmt->close();
}

// ========== EXCLUIR REGISTRO ==========
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = intval($dados['id'] ?? 0);

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    $stmt = $conexao->prepare('DELETE FROM registros_acesso WHERE tenant_id = $tenant_id AND id = ?');
    if (!$stmt) {
        retornar_json(false, 'Erro ao preparar exclusão: ' . $conexao->error);
    }
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        registrar_log('REGISTRO_EXCLUIDO', "Registro excluído: ID $id");
        retornar_json(true, 'Registro excluído com sucesso');
    } else {
        retornar_json(false, 'Erro ao excluir registro: ' . $stmt->error);
    }
    $stmt->close();
}

fechar_conexao($conexao);
