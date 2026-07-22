<?php
// =====================================================
// API DE CONTRATOS
// =====================================================
// Gerencia contratos, documentos, orçamentos e
// integração automática com contas a pagar
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

header('Content-Type: application/json; charset=utf-8');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Autenticação ────────────────────────────────────────────────────────────
verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();

// ─── Log de debug ────────────────────────────────────────────────────────────
function log_contrato(string $tipo, string $descricao, array $extra = []): void {
    $usuario = $_SESSION['usuario_nome'] ?? $_SESSION['usuario_email'] ?? 'sistema';
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ts      = date('Y-m-d H:i:s');
    try {
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$c->connect_error) {
            $c->set_charset('utf8mb4');
            $desc = $descricao . (!empty($extra) ? ' | ' . json_encode($extra, JSON_UNESCAPED_UNICODE) : '');
            $s = $c->prepare("INSERT INTO logs_sistema (tipo, descricao, usuario, ip, data_hora) VALUES (?,?,?,?,?)");
            if ($s) { $s->bind_param('sssss', $tipo, $desc, $usuario, $ip, $ts); $s->execute(); $s->close(); }
            $c->close();
        }
    } catch (Exception $e) { error_log('[log_contrato] ' . $e->getMessage()); }
}

// ─── Constantes ──────────────────────────────────────────────────────────────
define('UPLOAD_CONTRATOS', dirname(__DIR__) . '/uploads/contratos/');
define('UPLOAD_CONTRATOS_URL', '../uploads/contratos/');
define('TIPOS_MIME_PERMITIDOS', [
    'image/jpeg', 'image/jpg', 'image/png',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
]);
define('EXTENSOES_PERMITIDAS', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);
define('TAMANHO_MAX_UPLOAD', 10 * 1024 * 1024); // 10 MB

$acao   = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $conexao = conectar_banco();

    switch ($acao) {

        // ── CONTRATOS ─────────────────────────────────────────────────────────

        case 'listar':
            listarContratos($conexao);
            break;

        case 'buscar':
            buscarContrato($conexao);
            break;

        case 'cadastrar':
            verificarPermissao('operador');
            cadastrarContrato($conexao);
            break;

        case 'atualizar':
            verificarPermissao('operador');
            atualizarContrato($conexao);
            break;

        case 'deletar':
            verificarPermissao('admin');
            deletarContrato($conexao);
            break;

        // ── DOCUMENTOS ────────────────────────────────────────────────────────

        case 'upload_documento':
            verificarPermissao('operador');
            uploadDocumento($conexao);
            break;

        case 'listar_documentos':
            listarDocumentos($conexao);
            break;

        case 'deletar_documento':
            verificarPermissao('operador');
            deletarDocumento($conexao);
            break;

        // ── ORÇAMENTOS ────────────────────────────────────────────────────────

        case 'salvar_orcamento':
            verificarPermissao('operador');
            salvarOrcamento($conexao);
            break;

        case 'listar_orcamentos':
            listarOrcamentos($conexao);
            break;

        case 'deletar_orcamento':
            verificarPermissao('operador');
            deletarOrcamento($conexao);
            break;

        // ── DOCUMENTOS DO ORÇAMENTO ──────────────────────────────────────────

        case 'upload_orcamento_documento':
            verificarPermissao('operador');
            uploadOrcamentoDocumento($conexao);
            break;

        case 'listar_orcamento_documentos':
            listarOrcamentoDocumentos($conexao);
            break;

        case 'deletar_orcamento_documento':
            verificarPermissao('operador');
            deletarOrcamentoDocumento($conexao);
            break;

        // ── BUSCA DE FORNECEDORES ─────────────────────────────────────────────

        case 'buscar_fornecedores':
            buscarFornecedores($conexao);
            break;

        // ── RELATÓRIOS ────────────────────────────────────────────────────────

        case 'relatorio_ativos':
            relatorioAtivos($conexao);
            break;

        case 'relatorio_vencimentos':
            relatorioVencimentos($conexao);
            break;

        case 'relatorio_por_fornecedor':
            relatorioPorFornecedor($conexao);
            break;

        case 'relatorio_financeiro':
            relatorioFinanceiro($conexao);
            break;

        default:
            log_contrato('CONTRATO_ACAO_INVALIDA', "Ação inválida: '$acao'");
            http_response_code(400);
            retornar_json(false, 'Ação não especificada ou inválida');
    }

} catch (Exception $e) {
    log_contrato('CONTRATO_EXCECAO', $e->getMessage(), ['acao' => $acao, 'line' => $e->getLine()]);
    http_response_code(500);
    retornar_json(false, 'Erro interno: ' . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES — CONTRATOS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Listar contratos com filtros opcionais
 */
function listarContratos($db): void {
    $status     = $_GET['status']      ?? '';
    $fornecedor = $_GET['fornecedor']  ?? '';
    $tipo       = $_GET['tipo']        ?? '';
    $data_ini   = $_GET['data_ini']    ?? '';
    $data_fim   = $_GET['data_fim']    ?? '';
    $pagina     = max(1, intval($_GET['pagina'] ?? 1));
    $por_pagina = min(100, max(10, intval($_GET['por_pagina'] ?? 20)));
    $offset     = ($pagina - 1) * $por_pagina;

    $where  = ['c.ativo = 1'];
    $params = [];
    $types  = '';

    if (!empty($status)) {
        $where[] = 'c.status = ?';
        $params[] = $status; $types .= 's';
    }
    if (!empty($fornecedor)) {
        $where[] = '(c.fornecedor_nome LIKE ? OR c.fornecedor_cnpj LIKE ?)';
        $like = "%$fornecedor%";
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }
    if (!empty($tipo)) {
        $where[] = 'c.tipo_servico = ?';
        $params[] = $tipo; $types .= 's';
    }
    if (!empty($data_ini)) {
        $where[] = 'c.data_inicio >= ?';
        $params[] = $data_ini; $types .= 's';
    }
    if (!empty($data_fim)) {
        $where[] = 'c.data_fim <= ?';
        $params[] = $data_fim; $types .= 's';
    }

    $whereStr = implode(' AND ', $where);

    // Contar total
    $sqlCount = "SELECT COUNT(*) as total FROM contratos c WHERE tenant_id = $tenant_id AND $whereStr";
    $stmtC = $db->prepare($sqlCount);
    if (!empty($params)) $stmtC->bind_param($types, ...$params);
    $stmtC->execute();
    $total = $stmtC->get_result()->fetch_assoc()['total'];
    $stmtC->close();

    // Buscar dados
    $sql = "SELECT c.*,
                   pc.nome AS plano_conta_nome,
                   pc.codigo AS plano_conta_codigo,
                   (SELECT COUNT(*) FROM contrato_documentos cd WHERE tenant_id = $tenant_id AND cd.contrato_id = c.id AND cd.ativo = 1) AS total_documentos,
                   (SELECT COUNT(*) FROM contrato_orcamentos co WHERE tenant_id = $tenant_id AND co.contrato_id = c.id) AS total_orcamentos
            FROM contratos c
            LEFT JOIN planos_contas pc ON c.plano_conta_id = pc.id
            WHERE $whereStr
            ORDER BY c.data_criacao DESC
            LIMIT ? OFFSET ?";

    $params[] = $por_pagina; $params[] = $offset;
    $types   .= 'ii';

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $contratos = [];
    while ($row = $result->fetch_assoc()) {
        $row['valor_total']    = (float)$row['valor_total'];
        $contratos[] = $row;
    }
    $stmt->close();

    retornar_json(true, 'Contratos carregados', [
        'lista'      => $contratos,
        'total'      => (int)$total,
        'pagina'     => $pagina,
        'por_pagina' => $por_pagina,
        'paginas'    => ceil($total / $por_pagina),
    ]);
}

/**
 * Buscar um contrato específico por ID
 */
function buscarContrato($db): void {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $db->prepare("SELECT c.*, pc.nome AS plano_conta_nome, pc.codigo AS plano_conta_codigo
                          FROM contratos c
                          LEFT JOIN planos_contas pc ON c.plano_conta_id = pc.id
                          WHERE c.id = ? AND c.ativo = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) retornar_json(false, 'Contrato não encontrado');

    $row['valor_total'] = (float)$row['valor_total'];
    retornar_json(true, 'Contrato encontrado', $row);
}

/**
 * Cadastrar novo contrato e gerar lançamentos em contas_pagar
 */
function cadastrarContrato($db): void {
    // ── Coleta de campos ──────────────────────────────────────────────────────
    $fornecedor_id   = intval($_POST['fornecedor_id']   ?? 0);
    $fornecedor_nome = trim($_POST['fornecedor_nome']   ?? '');
    $fornecedor_cnpj = trim($_POST['fornecedor_cnpj']   ?? '');
    $tipo_servico    = trim($_POST['tipo_servico']      ?? ''); // prestacao_servico | venda
    $nome_contrato   = trim($_POST['nome_contrato']     ?? '');
    $data_inicio     = trim($_POST['data_inicio']       ?? '');
    $data_fim        = trim($_POST['data_fim']          ?? '');
    $recorrencia     = trim($_POST['recorrencia']       ?? ''); // unica | mensal | anual | diaria
    $valor_total     = floatval($_POST['valor_total']   ?? 0);
    $data_vencimento = trim($_POST['data_vencimento']   ?? '');
    $plano_conta_id  = intval($_POST['plano_conta_id']  ?? 0);
    $observacoes     = trim($_POST['observacoes']       ?? '');

    // ── Validações ────────────────────────────────────────────────────────────
    $erros = [];
    if (empty($fornecedor_nome))  $erros[] = 'Nome do fornecedor é obrigatório';
    if (empty($tipo_servico))     $erros[] = 'Tipo de serviço é obrigatório';
    if (empty($nome_contrato))    $erros[] = 'Nome do contrato é obrigatório';
    if (empty($data_inicio))      $erros[] = 'Data de início é obrigatória';
    if (empty($data_fim))         $erros[] = 'Data de fim é obrigatória';
    if (empty($recorrencia))      $erros[] = 'Tipo de recorrência é obrigatório';
    if ($valor_total <= 0)        $erros[] = 'Valor total deve ser maior que zero';
    if (empty($data_vencimento))  $erros[] = 'Data de vencimento é obrigatória';
    if ($plano_conta_id <= 0)     $erros[] = 'Plano de contas é obrigatório';

    if (!empty($erros)) retornar_json(false, implode('; ', $erros));

    // ── Gerar número sequencial ───────────────────────────────────────────────
    $ano = date('Y');
    $stmtSeq = $db->prepare("SELECT COUNT(*) as total FROM contratos WHERE tenant_id = $tenant_id AND YEAR(data_criacao) = ?");
    $stmtSeq->bind_param('i', $ano);
    $stmtSeq->execute();
    $seq = $stmtSeq->get_result()->fetch_assoc()['total'] + 1;
    $stmtSeq->close();
    $numero_contrato = sprintf('CTR-%s-%04d', $ano, $seq);

    // ── Status automático baseado nas datas ───────────────────────────────────
    $hoje  = date('Y-m-d');
    $status = 'ativo';
    if ($data_inicio > $hoje)  $status = 'aguardando';
    if ($data_fim    < $hoje)  $status = 'encerrado';

    // ── Iniciar transação para garantir contrato + contas a pagar ─────────────
    if (!$db->begin_transaction()) {
        log_contrato('CONTRATO_ERRO_TRANSACAO', "Falha ao iniciar transação: {$db->error}");
        retornar_json(false, 'Erro interno ao iniciar criação do contrato.');
    }

    // ── Inserir contrato ──────────────────────────────────────────────────────
    $sql = "INSERT INTO contratos
                (numero_contrato, fornecedor_id, fornecedor_nome, fornecedor_cnpj,
                 tipo_servico, nome_contrato, data_inicio, data_fim, recorrencia,
                 valor_total, data_vencimento, plano_conta_id, observacoes, status, ativo, data_criacao)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'".addslashes($status)."',1,NOW())";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('sisssssssdiss',
        $numero_contrato,
        $fornecedor_id,
        $fornecedor_nome,
        $fornecedor_cnpj,
        $tipo_servico,
        $nome_contrato,
        $data_inicio,
        $data_fim,
        $recorrencia,
        $valor_total,
        $data_vencimento,
        $plano_conta_id,
        $observacoes
    );

    if (!$stmt->execute()) {
        $db->rollback();
        log_contrato('CONTRATO_ERRO_INSERT', $stmt->error);
        retornar_json(false, 'Erro ao salvar contrato: ' . $stmt->error);
    }
    $contrato_id = $stmt->insert_id;
    $stmt->close();

    // ── Gerar lançamentos em contas_pagar ─────────────────────────────────────
    $ok = _gerarLancamentosContasPagar($db, $contrato_id, $numero_contrato, $fornecedor_nome,
        $plano_conta_id, $nome_contrato, $valor_total, $data_inicio, $data_vencimento,
        $recorrencia, $data_fim);

    if (!$ok) {
        $db->rollback();
        retornar_json(false, 'Contrato criadoo, mas erro ao gerar lançamentos em contas a pagar. Verifique o log do sistema.');
    }

    if (!$db->commit()) {
        log_contrato('CONTRATO_ERRO_COMMIT', "Falha ao finalizar transação: {$db->error}");
        retornar_json(false, 'Erro interno ao confirmar criação do contrato.');
    }

    log_contrato('CONTRATO_CRIADO', "Contrato $numero_contrato criado", ['id' => $contrato_id]);

    retornar_json(true, 'Contrato criado com sucesso', [
        'id'              => $contrato_id,
        'numero_contrato' => $numero_contrato,
        'status'          => $status,
    ]);
}

/**
 * Gera os lançamentos automáticos em contas_pagar conforme a recorrência
 */
function _gerarLancamentosContasPagar($db, $contrato_id, $numero_contrato, $fornecedor_nome,
    $plano_conta_id, $descricao, $valor_total, $data_inicio, $data_vencimento,
    $recorrencia, $data_fim): bool {

    $vencimentos = [];
    try {
        $dtVenc = new DateTime($data_vencimento);
        $dtFim  = new DateTime($data_fim);
    } catch (Exception $e) {
        log_contrato('CONTRATO_ERRO_CP_DATA', "Data inválida em contrato $numero_contrato: {$e->getMessage()}");
        return false;
    }
    $hoje   = new DateTime();

    switch ($recorrencia) {
        case 'unica':
            $vencimentos[] = $dtVenc->format('Y-m-d');
            break;

        case 'mensal':
            $dtAtual = clone $dtVenc;
            $parcela = 1;
            while ($dtAtual <= $dtFim) {
                $vencimentos[] = ['data' => $dtAtual->format('Y-m-d'), 'parcela' => $parcela++];
                $dtAtual->modify('+1 month');
            }
            break;

        case 'anual':
            $dtAtual = clone $dtVenc;
            $parcela = 1;
            while ($dtAtual <= $dtFim) {
                $vencimentos[] = ['data' => $dtAtual->format('Y-m-d'), 'parcela' => $parcela++];
                $dtAtual->modify('+1 year');
            }
            break;

        case 'diaria':
            $dtAtual = clone $dtVenc;
            $parcela = 1;
            while ($dtAtual <= $dtFim) {
                $vencimentos[] = ['data' => $dtAtual->format('Y-m-d'), 'parcela' => $parcela++];
                $dtAtual->modify('+1 day');
            }
            break;
    }

    // Para recorrência única, formatar como array uniforme
    if ($recorrencia === 'unica') {
        $vencimentos = [['data' => $vencimentos[0], 'parcela' => 1]];
    }

    $total_parcelas = count($vencimentos);
    if ($total_parcelas === 0) {
        log_contrato('CONTRATO_ERRO_CP_VENCIMENTOS', "Nenhum vencimento gerado para contrato $numero_contrato");
        return false;
    }

    // Valor por parcela (para mensais/anuais/diárias, divide o total)
    $valor_parcela = $recorrencia === 'unica' ? $valor_total : round($valor_total / $total_parcelas, 2);

    $sql = "INSERT INTO contas_pagar
                (numero_documento, fornecedor_nome, plano_conta_id, descricao,
                 valor_original, valor_pago, saldo_devedor,
                 data_emissao, data_vencimento, status, observacoes,
                 contrato_id, ativo, data_criacao)
            VALUES (?,?,?,?,?,0,?,?,?,'PENDENTE',?,?,1,NOW())";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        log_contrato('CONTRATO_ERRO_CP_PREPARE', "Erro ao preparar insert de contas_pagar para contrato $numero_contrato: {$db->error}");
        return false;
    }

    foreach ($vencimentos as $v) {
        $num_doc    = $numero_contrato . ($total_parcelas > 1 ? '-P' . str_pad($v['parcela'], 3, '0', STR_PAD_LEFT) : '');
        $desc_parc  = $descricao . ($total_parcelas > 1 ? " — Parcela {$v['parcela']}/{$total_parcelas}" : '');
        $obs        = "Lançamento automático do contrato $numero_contrato";
        $data_emis  = date('Y-m-d');
        $saldo      = $valor_parcela;

        if (!$stmt->bind_param('ssissdsssi',
            $num_doc,
            $fornecedor_nome,
            $plano_conta_id,
            $desc_parc,
            $valor_parcela,
            $saldo,
            $data_emis,
            $v['data'],
            $obs,
            $contrato_id
        )) {
            log_contrato('CONTRATO_ERRO_CP_BIND', "Erro ao vincular parâmetros de contas_pagar para contrato $numero_contrato: {$stmt->error}");
            $stmt->close();
            return false;
        }

        if (!$stmt->execute()) {
            log_contrato('CONTRATO_ERRO_CP_EXECUTE', "Erro ao inserir contas_pagar para contrato $numero_contrato: {$stmt->error}", ['parcela' => $v['parcela'] ?? null]);
            $stmt->close();
            return false;
        }
    }
    $stmt->close();
    return true;
}

/**
 * Atualizar contrato existente
 */
function atualizarContrato($db): void {
    $id              = intval($_POST['id']            ?? 0);
    $fornecedor_id   = intval($_POST['fornecedor_id'] ?? 0);
    $fornecedor_nome = trim($_POST['fornecedor_nome'] ?? '');
    $fornecedor_cnpj = trim($_POST['fornecedor_cnpj'] ?? '');
    $tipo_servico    = trim($_POST['tipo_servico']    ?? '');
    $nome_contrato   = trim($_POST['nome_contrato']   ?? '');
    $data_inicio     = trim($_POST['data_inicio']     ?? '');
    $data_fim        = trim($_POST['data_fim']        ?? '');
    $recorrencia     = trim($_POST['recorrencia']     ?? '');
    $valor_total     = floatval($_POST['valor_total'] ?? 0);
    $data_vencimento = trim($_POST['data_vencimento'] ?? '');
    $plano_conta_id  = intval($_POST['plano_conta_id']?? 0);
    $observacoes     = trim($_POST['observacoes']     ?? '');

    if ($id <= 0) retornar_json(false, 'ID inválido');
    if (empty($fornecedor_nome) || empty($nome_contrato) || $valor_total <= 0)
        retornar_json(false, 'Campos obrigatórios não preenchidos');

    // Recalcular status
    $hoje   = date('Y-m-d');
    $status = 'ativo';
    if ($data_inicio > $hoje) $status = 'aguardando';
    if ($data_fim    < $hoje) $status = 'encerrado';

    $sql = "UPDATE contratos SET
                fornecedor_id=?, fornecedor_nome=?, fornecedor_cnpj=?,
                tipo_servico=?, nome_contrato=?, data_inicio=?, data_fim=?,
                recorrencia=?, valor_total=?, data_vencimento=?,
                plano_conta_id=?, observacoes=?, status=?,
                data_atualizacao=NOW() WHERE tenant_id = $tenant_id AND id=? AND ativo=1";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('isssssssdssssi',
        $fornecedor_id, $fornecedor_nome, $fornecedor_cnpj,
        $tipo_servico, $nome_contrato, $data_inicio, $data_fim,
        $recorrencia, $valor_total, $data_vencimento,
        $plano_conta_id, $observacoes, $status, $id
    );

    if (!$stmt->execute()) retornar_json(false, 'Erro ao atualizar: ' . $stmt->error);
    $stmt->close();

    log_contrato('CONTRATO_ATUALIZADO', "Contrato ID $id atualizado");
    retornar_json(true, 'Contrato atualizado com sucesso');
}

/**
 * Soft delete de contrato
 */
function deletarContrato($db): void {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $db->prepare("UPDATE contratos SET ativo=0, data_atualizacao=NOW() WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) retornar_json(false, 'Erro ao excluir');
    $stmt->close();

    log_contrato('CONTRATO_DELETADO', "Contrato ID $id excluído");
    retornar_json(true, 'Contrato excluído com sucesso');
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES — DOCUMENTOS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Upload de documento vinculado a um contrato (máx. 4 por contrato)
 */
function uploadDocumento($db): void {
    $contrato_id  = intval($_POST['contrato_id']  ?? 0);
    $nome_doc     = trim($_POST['nome_documento'] ?? '');
    $tipo_doc     = trim($_POST['tipo_documento'] ?? ''); // contrato | aditivo | ata | outros

    if ($contrato_id <= 0) retornar_json(false, 'Contrato inválido');
    if (empty($nome_doc))  retornar_json(false, 'Nome do documento é obrigatório');
    if (empty($tipo_doc))  retornar_json(false, 'Tipo do documento é obrigatório');

    // Verificar se o contrato existe
    $chk = $db->prepare("SELECT id FROM contratos WHERE tenant_id = $tenant_id AND id=? AND ativo=1");
    $chk->bind_param('i', $contrato_id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) retornar_json(false, 'Contrato não encontrado');
    $chk->close();

    // Verificar limite de 4 documentos
    $cnt = $db->prepare("SELECT COUNT(*) as total FROM contrato_documentos WHERE tenant_id = $tenant_id AND contrato_id=? AND ativo=1");
    $cnt->bind_param('i', $contrato_id);
    $cnt->execute();
    $total_docs = $cnt->get_result()->fetch_assoc()['total'];
    $cnt->close();

    if ($total_docs >= 4) retornar_json(false, 'Limite de 4 documentos por contrato atingido');

    // Validar arquivo
    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        $erros_upload = [
            UPLOAD_ERR_INI_SIZE  => 'Arquivo muito grande (limite do servidor)',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande (limite do formulário)',
            UPLOAD_ERR_PARTIAL   => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE   => 'Nenhum arquivo enviado',
        ];
        $cod = $_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        retornar_json(false, $erros_upload[$cod] ?? 'Erro no upload');
    }

    $arquivo = $_FILES['arquivo'];

    // Validar MIME
    $mime = mime_content_type($arquivo['tmp_name']);
    if (!in_array($mime, TIPOS_MIME_PERMITIDOS)) {
        retornar_json(false, 'Tipo de arquivo não permitido. Use JPG, PNG, PDF, DOC ou DOCX');
    }

    // Validar tamanho
    if ($arquivo['size'] > TAMANHO_MAX_UPLOAD) {
        retornar_json(false, 'Arquivo muito grande. Máximo 10 MB');
    }

    // Validar extensão
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXTENSOES_PERMITIDAS)) {
        retornar_json(false, 'Extensão não permitida');
    }

    // Criar diretório
    $dir = UPLOAD_CONTRATOS . $contrato_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Nome único
    $nome_arquivo = uniqid("doc_{$contrato_id}_") . '.' . $ext;
    $caminho      = $dir . $nome_arquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $caminho)) {
        retornar_json(false, 'Erro ao salvar arquivo no servidor');
    }

    $url_relativa = UPLOAD_CONTRATOS_URL . $contrato_id . '/' . $nome_arquivo;

    // Inserir no banco
    $stmt = $db->prepare("INSERT INTO contrato_documentos
                            (contrato_id, nome_documento, tipo_documento, nome_arquivo, url_arquivo, tamanho, mime_type, ativo, data_upload)
                          VALUES (?,?,?,?,?,?,?,1,NOW())");
    $stmt->bind_param('issssds',
        $contrato_id, $nome_doc, $tipo_doc, $nome_arquivo, $url_relativa,
        $arquivo['size'], $mime
    );

    if (!$stmt->execute()) {
        @unlink($caminho);
        retornar_json(false, 'Erro ao registrar documento: ' . $stmt->error);
    }
    $doc_id = $stmt->insert_id;
    $stmt->close();

    log_contrato('DOC_UPLOAD', "Documento '$nome_doc' enviado para contrato $contrato_id", ['doc_id' => $doc_id]);
    retornar_json(true, 'Documento enviado com sucesso', ['id' => $doc_id, 'url' => $url_relativa]);
}

/**
 * Listar documentos de um contrato
 */
function listarDocumentos($db): void {
    $contrato_id = intval($_GET['contrato_id'] ?? 0);
    if ($contrato_id <= 0) retornar_json(false, 'Contrato inválido');

    $stmt = $db->prepare("SELECT * FROM contrato_documentos WHERE tenant_id = $tenant_id AND contrato_id=? AND ativo=1 ORDER BY data_upload ASC");
    $stmt->bind_param('i', $contrato_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $docs = [];
    while ($row = $result->fetch_assoc()) $docs[] = $row;
    $stmt->close();

    retornar_json(true, 'Documentos carregados', $docs);
}

/**
 * Excluir documento (soft delete + remove arquivo físico)
 */
function deletarDocumento($db): void {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $db->prepare("SELECT nome_arquivo, contrato_id FROM contrato_documentos WHERE tenant_id = $tenant_id AND id=? AND ativo=1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) retornar_json(false, 'Documento não encontrado');

    // Soft delete
    $upd = $db->prepare("UPDATE contrato_documentos SET ativo=0 WHERE tenant_id = $tenant_id AND id=?");
    $upd->bind_param('i', $id);
    $upd->execute();
    $upd->close();

    // Remover arquivo físico
    $caminho = UPLOAD_CONTRATOS . $doc['contrato_id'] . '/' . $doc['nome_arquivo'];
    if (file_exists($caminho)) @unlink($caminho);

    log_contrato('DOC_DELETADO', "Documento ID $id excluído do contrato {$doc['contrato_id']}");
    retornar_json(true, 'Documento excluído com sucesso');
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES — ORÇAMENTOS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Salvar orçamento (INSERT ou UPDATE)
 */
function salvarOrcamento($db): void {
    $id           = intval($_POST['id']           ?? 0);
    $contrato_id  = intval($_POST['contrato_id']  ?? 0);
    $fornecedor   = trim($_POST['fornecedor']     ?? '');
    $descricao    = trim($_POST['descricao']      ?? '');
    $valor        = floatval($_POST['valor']      ?? 0);
    $justificativa= trim($_POST['justificativa']  ?? '');

    if ($contrato_id <= 0) retornar_json(false, 'Contrato inválido');
    if (empty($fornecedor))  retornar_json(false, 'Fornecedor do orçamento é obrigatório');
    if (empty($descricao))   retornar_json(false, 'Descrição é obrigatória');
    if ($valor <= 0)         retornar_json(false, 'Valor deve ser maior que zero');

    // Verificar se o valor do orçamento é maior que o valor do contrato
    // e se a justificativa foi preenchida
    $stmtC = $db->prepare("SELECT valor_total FROM contratos WHERE tenant_id = $tenant_id AND id=? AND ativo=1");
    $stmtC->bind_param('i', $contrato_id);
    $stmtC->execute();
    $contrato = $stmtC->get_result()->fetch_assoc();
    $stmtC->close();

    if ($contrato && $valor > $contrato['valor_total'] && empty($justificativa)) {
        retornar_json(false, 'O valor do orçamento é maior que o valor do contrato. Justificativa obrigatória.');
    }

    if ($id > 0) {
        // Atualizar
        $stmt = $db->prepare("UPDATE contrato_orcamentos SET fornecedor=?, descricao=?, valor=?, justificativa=? WHERE tenant_id = $tenant_id AND id=? AND contrato_id=?");
        $stmt->bind_param('ssdsii', $fornecedor, $descricao, $valor, $justificativa, $id, $contrato_id);
    } else {
        // Inserir
        $stmt = $db->prepare("INSERT INTO contrato_orcamentos (contrato_id, fornecedor, descricao, valor, justificativa, data_criacao) VALUES (?,?,?,?,?,NOW())");
        $stmt->bind_param('issds', $contrato_id, $fornecedor, $descricao, $valor, $justificativa);
    }

    if (!$stmt->execute()) retornar_json(false, 'Erro ao salvar orçamento: ' . $stmt->error);
    $orcamento_id = $id > 0 ? $id : $stmt->insert_id;
    $stmt->close();

    // Verificar se atingiu 3 orçamentos
    $cnt = $db->prepare("SELECT COUNT(*) as total FROM contrato_orcamentos WHERE tenant_id = $tenant_id AND contrato_id=?");
    $cnt->bind_param('i', $contrato_id);
    $cnt->execute();
    $total_orc = $cnt->get_result()->fetch_assoc()['total'];
    $cnt->close();

    log_contrato('ORCAMENTO_SALVO', "Orçamento ID $orcamento_id salvo para contrato $contrato_id");
    retornar_json(true, 'Orçamento salvo com sucesso', [
        'id'            => $orcamento_id,
        'total_orcamentos' => (int)$total_orc,
        'minimo_atingido'  => $total_orc >= 3,
    ]);
}

/**
 * Listar orçamentos de um contrato
 */
function listarOrcamentos($db): void {
    $contrato_id = intval($_GET['contrato_id'] ?? 0);
    if ($contrato_id <= 0) retornar_json(false, 'Contrato inválido');

    $stmt = $db->prepare("SELECT * FROM contrato_orcamentos WHERE tenant_id = $tenant_id AND contrato_id=? ORDER BY valor ASC");
    $stmt->bind_param('i', $contrato_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $orcamentos = [];
    while ($row = $result->fetch_assoc()) {
        $row['valor'] = (float)$row['valor'];
        $orcamentos[] = $row;
    }
    $stmt->close();

    retornar_json(true, 'Orçamentos carregados', $orcamentos);
}

/**
 * Excluir orçamento
 */
function deletarOrcamento($db): void {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $db->prepare("DELETE FROM contrato_orcamentos WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) retornar_json(false, 'Erro ao excluir');
    $stmt->close();

    retornar_json(true, 'Orçamento excluído com sucesso');
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES — DOCUMENTOS DO ORÇAMENTO
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Garante a existência da tabela de documentos de orçamento (auto-provisionamento,
 * já que migrações SQL não rodam automaticamente em produção).
 */
function garantirTabelaOrcamentoDocumentos($db): void {
    $db->query("CREATE TABLE IF NOT EXISTS `contrato_orcamento_documentos` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `orcamento_id`    INT          NOT NULL,
        `nome_documento`  VARCHAR(255) NOT NULL,
        `tipo_documento`  VARCHAR(50)  NOT NULL,
        `nome_arquivo`    VARCHAR(255) NOT NULL,
        `url_arquivo`     VARCHAR(500) NOT NULL,
        `tamanho`         BIGINT       DEFAULT NULL,
        `mime_type`       VARCHAR(100) DEFAULT NULL,
        `ativo`           TINYINT(1)   NOT NULL DEFAULT 1,
        `data_upload`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_orcamento_id` (`orcamento_id`),
        INDEX `idx_ativo`        (`ativo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Upload de documento vinculado a um orçamento
 */
function uploadOrcamentoDocumento($db): void {
    garantirTabelaOrcamentoDocumentos($db);
    $orcamento_id = intval($_POST['orcamento_id'] ?? 0);
    $nome_doc     = trim($_POST['nome_documento'] ?? '');
    $tipo_doc     = trim($_POST['tipo_documento'] ?? '');

    if ($orcamento_id <= 0) retornar_json(false, 'Orçamento inválido');
    if (empty($nome_doc))   retornar_json(false, 'Nome do documento é obrigatório');
    if (empty($tipo_doc))   retornar_json(false, 'Tipo do documento é obrigatório');

    // Verificar se o orçamento existe e obter o contrato vinculado
    $chk = $db->prepare("SELECT contrato_id FROM contrato_orcamentos WHERE tenant_id = $tenant_id AND id=?");
    $chk->bind_param('i', $orcamento_id);
    $chk->execute();
    $orc = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$orc) retornar_json(false, 'Orçamento não encontrado');
    $contrato_id = (int)$orc['contrato_id'];

    // Validar arquivo
    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        $erros_upload = [
            UPLOAD_ERR_INI_SIZE  => 'Arquivo muito grande (limite do servidor)',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande (limite do formulário)',
            UPLOAD_ERR_PARTIAL   => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE   => 'Nenhum arquivo enviado',
        ];
        $cod = $_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        retornar_json(false, $erros_upload[$cod] ?? 'Erro no upload');
    }

    $arquivo = $_FILES['arquivo'];

    // Validar MIME
    $mime = mime_content_type($arquivo['tmp_name']);
    if (!in_array($mime, TIPOS_MIME_PERMITIDOS)) {
        retornar_json(false, 'Tipo de arquivo não permitido. Use JPG, PNG, PDF, DOC ou DOCX');
    }

    // Validar tamanho
    if ($arquivo['size'] > TAMANHO_MAX_UPLOAD) {
        retornar_json(false, 'Arquivo muito grande. Máximo 10 MB');
    }

    // Validar extensão
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXTENSOES_PERMITIDAS)) {
        retornar_json(false, 'Extensão não permitida');
    }

    // Criar diretório
    $dir = UPLOAD_CONTRATOS . $contrato_id . '/orcamentos/' . $orcamento_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Nome único
    $nome_arquivo = uniqid("orcdoc_{$orcamento_id}_") . '.' . $ext;
    $caminho      = $dir . $nome_arquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $caminho)) {
        retornar_json(false, 'Erro ao salvar arquivo no servidor');
    }

    $url_relativa = UPLOAD_CONTRATOS_URL . $contrato_id . '/orcamentos/' . $orcamento_id . '/' . $nome_arquivo;

    // Inserir no banco
    $stmt = $db->prepare("INSERT INTO contrato_orcamento_documentos
                            (orcamento_id, nome_documento, tipo_documento, nome_arquivo, url_arquivo, tamanho, mime_type, ativo, data_upload)
                          VALUES (?,?,?,?,?,?,?,1,NOW())");
    $stmt->bind_param('issssds',
        $orcamento_id, $nome_doc, $tipo_doc, $nome_arquivo, $url_relativa,
        $arquivo['size'], $mime
    );

    if (!$stmt->execute()) {
        @unlink($caminho);
        retornar_json(false, 'Erro ao registrar documento: ' . $stmt->error);
    }
    $doc_id = $stmt->insert_id;
    $stmt->close();

    log_contrato('ORC_DOC_UPLOAD', "Documento '$nome_doc' enviado para orçamento $orcamento_id", ['doc_id' => $doc_id]);
    retornar_json(true, 'Documento enviado com sucesso', ['id' => $doc_id, 'url' => $url_relativa]);
}

/**
 * Listar documentos de um orçamento
 */
function listarOrcamentoDocumentos($db): void {
    garantirTabelaOrcamentoDocumentos($db);
    $orcamento_id = intval($_GET['orcamento_id'] ?? 0);
    if ($orcamento_id <= 0) retornar_json(false, 'Orçamento inválido');

    $stmt = $db->prepare("SELECT * FROM contrato_orcamento_documentos WHERE orcamento_id=? AND ativo=1 ORDER BY data_upload ASC");
    $stmt->bind_param('i', $orcamento_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $docs = [];
    while ($row = $result->fetch_assoc()) $docs[] = $row;
    $stmt->close();

    retornar_json(true, 'Documentos carregados', $docs);
}

/**
 * Excluir documento do orçamento (soft delete + remove arquivo físico)
 */
function deletarOrcamentoDocumento($db): void {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $db->prepare("SELECT od.nome_arquivo, od.orcamento_id, o.contrato_id
                           FROM contrato_orcamento_documentos od
                           JOIN contrato_orcamentos o ON o.id = od.orcamento_id
                           WHERE od.id=? AND od.ativo=1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) retornar_json(false, 'Documento não encontrado');

    // Soft delete
    $upd = $db->prepare("UPDATE contrato_orcamento_documentos SET ativo=0 WHERE id=?");
    $upd->bind_param('i', $id);
    $upd->execute();
    $upd->close();

    // Remover arquivo físico
    $caminho = UPLOAD_CONTRATOS . $doc['contrato_id'] . '/orcamentos/' . $doc['orcamento_id'] . '/' . $doc['nome_arquivo'];
    if (file_exists($caminho)) @unlink($caminho);

    log_contrato('ORC_DOC_DELETADO', "Documento ID $id excluído do orçamento {$doc['orcamento_id']}");
    retornar_json(true, 'Documento excluído com sucesso');
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES — BUSCA DE FORNECEDORES
// ═══════════════════════════════════════════════════════════════════════════════

function buscarFornecedores($db): void {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) retornar_json(true, 'OK', []);

    $like = "%$q%";
    $stmt = $db->prepare("SELECT id, nome_estabelecimento AS nome, cpf_cnpj AS cnpj, email, telefone
                          FROM fornecedores WHERE tenant_id = $tenant_id AND ativo=1 AND aprovado=1
                            AND (nome_estabelecimento LIKE ? OR cpf_cnpj LIKE ?)
                          ORDER BY nome_estabelecimento ASC
                          LIMIT 10");
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $lista = [];
    while ($row = $result->fetch_assoc()) $lista[] = $row;
    $stmt->close();

    retornar_json(true, 'Fornecedores encontrados', $lista);
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES — RELATÓRIOS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Relatório: Contratos Ativos
 */
function relatorioAtivos($db): void {
    $tipo = $_GET['tipo'] ?? '';

    $where = ["c.ativo=1", "c.status='ativo'"];
    $params = []; $types = '';

    if (!empty($tipo)) {
        $where[] = 'c.tipo_servico=?';
        $params[] = $tipo; $types .= 's';
    }

    $whereStr = implode(' AND ', $where);

    $sql = "SELECT c.numero_contrato, c.fornecedor_nome, c.fornecedor_cnpj,
                   c.tipo_servico, c.nome_contrato, c.recorrencia,
                   c.valor_total, c.data_inicio, c.data_fim, c.data_vencimento,
                   pc.nome AS plano_conta,
                   DATEDIFF(c.data_fim, CURDATE()) AS dias_restantes
            FROM contratos c
            LEFT JOIN planos_contas pc ON c.plano_conta_id = pc.id
            WHERE $whereStr
            ORDER BY c.data_fim ASC";

    $stmt = $db->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $lista = [];
    $total_valor = 0;
    while ($row = $result->fetch_assoc()) {
        $row['valor_total'] = (float)$row['valor_total'];
        $total_valor += $row['valor_total'];
        $lista[] = $row;
    }
    $stmt->close();

    retornar_json(true, 'Relatório gerado', [
        'lista'       => $lista,
        'total'       => count($lista),
        'total_valor' => $total_valor,
    ]);
}

/**
 * Relatório: Contratos por Vencimento (próximos 30/60/90 dias)
 */
function relatorioVencimentos($db): void {
    $dias = intval($_GET['dias'] ?? 30);

    $sql = "SELECT c.numero_contrato, c.fornecedor_nome, c.nome_contrato,
                   c.valor_total, c.data_vencimento, c.data_fim, c.status,
                   cp.status AS status_pagamento, cp.valor_original AS valor_parcela,
                   cp.data_vencimento AS vencimento_parcela
            FROM contratos c
            LEFT JOIN contas_pagar cp ON cp.contrato_id = c.id AND cp.status = 'PENDENTE' AND cp.ativo = 1
            WHERE c.ativo=1
              AND c.status='ativo'
              AND cp.data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY cp.data_vencimento ASC";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $dias);
    $stmt->execute();
    $result = $stmt->get_result();
    $lista = [];
    while ($row = $result->fetch_assoc()) {
        $row['valor_total']   = (float)$row['valor_total'];
        $row['valor_parcela'] = (float)$row['valor_parcela'];
        $lista[] = $row;
    }
    $stmt->close();

    retornar_json(true, 'Relatório de vencimentos gerado', ['lista' => $lista, 'total' => count($lista)]);
}

/**
 * Relatório: Contratos por Fornecedor
 */
function relatorioPorFornecedor($db): void {
    $sql = "SELECT c.fornecedor_nome, c.fornecedor_cnpj,
                   COUNT(*) AS total_contratos,
                   SUM(c.valor_total) AS valor_total,
                   SUM(CASE WHEN c.status='ativo' THEN 1 ELSE 0 END) AS ativos,
                   SUM(CASE WHEN c.status='encerrado' THEN 1 ELSE 0 END) AS encerrados
            FROM contratos c WHERE tenant_id = $tenant_id AND c.ativo=1
            GROUP BY c.fornecedor_nome, c.fornecedor_cnpj
            ORDER BY valor_total DESC";

    $result = $db->query($sql);
    $lista = [];
    while ($row = $result->fetch_assoc()) {
        $row['valor_total'] = (float)$row['valor_total'];
        $lista[] = $row;
    }

    retornar_json(true, 'Relatório por fornecedor gerado', ['lista' => $lista, 'total' => count($lista)]);
}

/**
 * Relatório: Financeiro (contratos x contas_pagar)
 */
function relatorioFinanceiro($db): void {
    $data_ini = $_GET['data_ini'] ?? date('Y-01-01');
    $data_fim = $_GET['data_fim'] ?? date('Y-12-31');

    $sql = "SELECT c.numero_contrato, c.fornecedor_nome, c.nome_contrato,
                   c.valor_total AS valor_contrato,
                   SUM(cp.valor_original) AS total_lancado,
                   SUM(CASE WHEN cp.status='PAGO' THEN cp.valor_pago ELSE 0 END) AS total_pago,
                   SUM(CASE WHEN cp.status='PENDENTE' THEN cp.saldo_devedor ELSE 0 END) AS total_pendente,
                   COUNT(cp.id) AS total_parcelas,
                   SUM(CASE WHEN cp.status='PAGO' THEN 1 ELSE 0 END) AS parcelas_pagas
            FROM contratos c
            LEFT JOIN contas_pagar cp ON cp.contrato_id = c.id AND cp.ativo=1
                AND cp.data_vencimento BETWEEN ? AND ?
            WHERE c.ativo=1
            GROUP BY c.id, c.numero_contrato, c.fornecedor_nome, c.nome_contrato, c.valor_total
            ORDER BY c.data_criacao DESC";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $data_ini, $data_fim);
    $stmt->execute();
    $result = $stmt->get_result();
    $lista = [];
    $totais = ['lancado' => 0, 'pago' => 0, 'pendente' => 0];
    while ($row = $result->fetch_assoc()) {
        $row['valor_contrato']  = (float)$row['valor_contrato'];
        $row['total_lancado']   = (float)$row['total_lancado'];
        $row['total_pago']      = (float)$row['total_pago'];
        $row['total_pendente']  = (float)$row['total_pendente'];
        $totais['lancado']  += $row['total_lancado'];
        $totais['pago']     += $row['total_pago'];
        $totais['pendente'] += $row['total_pendente'];
        $lista[] = $row;
    }
    $stmt->close();

    retornar_json(true, 'Relatório financeiro gerado', [
        'lista'    => $lista,
        'total'    => count($lista),
        'totais'   => $totais,
        'periodo'  => ['ini' => $data_ini, 'fim' => $data_fim],
    ]);
}
