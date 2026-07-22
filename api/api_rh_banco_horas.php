<?php
// =====================================================
// API: RH — Banco de Horas
// =====================================================
// GET  ?acao=extrato&colaborador_id=N[&data_ini=Y-m-d&data_fim=Y-m-d]
// GET  ?acao=saldo&colaborador_id=N
// POST ?acao=registrar  {colaborador_id, tipo:'abatimento'|'pagamento', minutos, descricao}

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$allowed = ['https://asl.erpcondominios.com.br','http://asl.erpcondominios.com.br','https://erpcondominios.com.br','http://erpcondominios.com.br','http://localhost','http://127.0.0.1'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache, must-revalidate');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try { verificarAutenticacao(true, 'operador'); }
catch (Exception $e) {
    http_response_code(401);
    retornar_json(false, 'Não autenticado.');
    exit;
}

$conn = conectar_banco();
if (!$conn) { retornar_json(false, 'Erro ao conectar ao banco.'); exit; }

// ── Auto-cria tabela rh_banco_horas ──────────────────────────────────────────
function _bh_garantir_tabela(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS rh_banco_horas (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        colaborador_id  INT NOT NULL,
        lancamento_id   INT NULL COMMENT 'ID em rh_ponto_lancamento, NULL=manual',
        data            DATE NOT NULL,
        tipo            ENUM('credito','debito','abatimento','pagamento') NOT NULL,
        minutos         INT NOT NULL DEFAULT 0 COMMENT 'sempre positivo',
        descricao       VARCHAR(255) NOT NULL DEFAULT '',
        usuario         VARCHAR(100) NULL,
        criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_colab_data (colaborador_id, data),
        INDEX idx_lancamento (lancamento_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
_bh_garantir_tabela($conn);

$metodo = $_SERVER['REQUEST_METHOD'];
$acao   = trim($_GET['acao'] ?? '');

// ── EXTRATO ───────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'extrato') {
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    if ($colab_id <= 0) { fechar_conexao($conn); retornar_json(false, 'colaborador_id obrigatório.'); exit; }

    $data_ini = $_GET['data_ini'] ?? null;
    $data_fim = $_GET['data_fim'] ?? null;

    $where = 'WHERE b.colaborador_id = ?';
    $types = 'i';
    $params = [$colab_id];

    if ($data_ini && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ini)) {
        $where .= ' AND b.data >= ?'; $types .= 's'; $params[] = $data_ini;
    }
    if ($data_fim && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) {
        $where .= ' AND b.data <= ?'; $types .= 's'; $params[] = $data_fim;
    }

    $sql = "SELECT b.id, DATE_FORMAT(b.data,'%d/%m/%Y') AS data_fmt, b.data,
                   b.tipo, b.minutos, b.descricao, b.usuario,
                   DATE_FORMAT(b.criado_em,'%d/%m/%Y %H:%i') AS criado_em_fmt,
                   b.lancamento_id
            FROM rh_banco_horas b
            $where
            ORDER BY b.data ASC, b.id ASC";

    $st = $conn->prepare($sql);
    if (!$st) { fechar_conexao($conn); retornar_json(false, 'Erro: ' . $conn->error); exit; }

    $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();

    $linhas  = [];
    $saldo   = 0;
    while ($r = $res->fetch_assoc()) {
        $min = intval($r['minutos']);
        if (in_array($r['tipo'], ['credito'])) {
            $saldo += $min;
            $r['sinal'] = '+';
        } else {
            $saldo -= $min;
            $r['sinal'] = '-';
        }
        $r['saldo_corrente']     = $saldo;
        $r['saldo_corrente_fmt'] = _bh_fmt($saldo);
        $r['minutos_fmt']        = _bh_fmt($min);
        $linhas[] = $r;
    }
    $st->close();
    fechar_conexao($conn);
    retornar_json(true, 'OK', ['linhas' => $linhas, 'saldo_final' => $saldo, 'saldo_final_fmt' => _bh_fmt($saldo)]);
    exit;
}

// ── SALDO ─────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'saldo') {
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    if ($colab_id <= 0) { fechar_conexao($conn); retornar_json(false, 'colaborador_id obrigatório.'); exit; }

    $st = $conn->prepare(
        "SELECT tipo, SUM(minutos) AS total
         FROM rh_banco_horas
         WHERE colaborador_id = ?
         GROUP BY tipo"
    );
    $st->bind_param('i', $colab_id);
    $st->execute();
    $res = $st->get_result();

    $credito = 0; $debito = 0;
    while ($r = $res->fetch_assoc()) {
        if ($r['tipo'] === 'credito') $credito += intval($r['total']);
        else                          $debito  += intval($r['total']);
    }
    $st->close();
    $saldo = $credito - $debito;
    fechar_conexao($conn);
    retornar_json(true, 'OK', [
        'credito'     => $credito,
        'debito'      => $debito,
        'saldo'       => $saldo,
        'credito_fmt' => _bh_fmt($credito),
        'debito_fmt'  => _bh_fmt($debito),
        'saldo_fmt'   => _bh_fmt($saldo),
        'positivo'    => $saldo >= 0,
    ]);
    exit;
}

// ── REGISTRAR (abatimento / pagamento manual) ─────────────────────────────────
if ($metodo === 'POST' && $acao === 'registrar') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $colab_id   = intval($body['colaborador_id'] ?? 0);
    $tipo_input = trim($body['tipo'] ?? '');
    $minutos    = intval($body['minutos'] ?? 0);
    $descricao  = trim($body['descricao'] ?? '');
    $data       = trim($body['data'] ?? date('Y-m-d'));
    $usuario    = $_SESSION['nome'] ?? $_SESSION['usuario_nome'] ?? 'Sistema';

    if ($colab_id <= 0)                          { fechar_conexao($conn); retornar_json(false, 'colaborador_id obrigatório.'); exit; }
    if (!in_array($tipo_input, ['abatimento','pagamento'])) { fechar_conexao($conn); retornar_json(false, 'Tipo inválido. Use abatimento ou pagamento.'); exit; }
    if ($minutos <= 0)                           { fechar_conexao($conn); retornar_json(false, 'Minutos deve ser maior que zero.'); exit; }
    if (empty($descricao))                       { fechar_conexao($conn); retornar_json(false, 'Descrição obrigatória.'); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = date('Y-m-d');

    // Verifica se há saldo suficiente para abatimento/pagamento
    $st2 = $conn->prepare(
        "SELECT tipo, SUM(minutos) AS total FROM rh_banco_horas WHERE colaborador_id = ? GROUP BY tipo"
    );
    $st2->bind_param('i', $colab_id);
    $st2->execute();
    $res2 = $st2->get_result();
    $cred = 0; $deb = 0;
    while ($r2 = $res2->fetch_assoc()) {
        if ($r2['tipo'] === 'credito') $cred += intval($r2['total']);
        else                           $deb  += intval($r2['total']);
    }
    $st2->close();
    $saldoAtual = $cred - $deb;

    if ($minutos > $saldoAtual) {
        fechar_conexao($conn);
        retornar_json(false, 'Saldo insuficiente. Saldo atual: ' . _bh_fmt($saldoAtual));
        exit;
    }

    $st3 = $conn->prepare(
        "INSERT INTO rh_banco_horas (colaborador_id, data, tipo, minutos, descricao, usuario)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $st3->bind_param('isisss', $colab_id, $data, $tipo_input, $minutos, $descricao, $usuario);
    if (!$st3->execute()) {
        $erro = $st3->error; $st3->close(); fechar_conexao($conn);
        retornar_json(false, 'Erro ao registrar: ' . $erro);
        exit;
    }
    $st3->close();
    fechar_conexao($conn);
    retornar_json(true, ucfirst($tipo_input) . ' registrado com sucesso.');
    exit;
}

// ── EXCLUIR lançamento manual ─────────────────────────────────────────────────
if ($metodo === 'DELETE') {
    $body4 = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = intval($body4['id'] ?? 0);
    if ($id <= 0) { fechar_conexao($conn); retornar_json(false, 'ID inválido.'); exit; }

    // Só exclui lançamentos sem lancamento_id (manuais)
    $st4 = $conn->prepare("DELETE FROM rh_banco_horas WHERE id=? AND lancamento_id IS NULL");
    $st4->bind_param('i', $id);
    $ok = $st4->execute(); $afetado = $st4->affected_rows; $st4->close();
    fechar_conexao($conn);
    if (!$ok || $afetado === 0) retornar_json(false, 'Não foi possível excluir (somente lançamentos manuais podem ser removidos).');
    retornar_json(true, 'Lançamento removido.');
    exit;
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida.');

// ── Utilitários ───────────────────────────────────────────────────────────────
function _bh_fmt(int $minutos): string {
    $neg = $minutos < 0;
    $abs = abs($minutos);
    $h   = intdiv($abs, 60);
    $m   = $abs % 60;
    return ($neg ? '-' : '') . sprintf('%02d:%02d', $h, $m);
}
