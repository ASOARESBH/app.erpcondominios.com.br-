<?php
/**
 * =====================================================================
 * API DE ADMINISTRAÇÃO DE SESSÕES - api_admin_sessoes.php
 * =====================================================================
 * Permite ao administrador:
 *   - Listar moradores com suas configurações de sessão
 *   - Configurar timeout personalizado por morador
 *   - Ver sessões ativas em tempo real
 *   - Encerrar sessões manualmente
 *   - Configurar timeouts globais do sistema
 *   - Ver log de sessões
 *
 * Requer: autenticação de administrador via $_SESSION (ERP interno)
 * Log: logs/admin_sessoes.txt
 * =====================================================================
 */

ob_start();
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';

// ─── LOG ─────────────────────────────────────────────────────────────────────
function log_admin($msg, $nivel = 'INFO') {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/admin_sessoes.txt',
        '[' . date('Y-m-d H:i:s') . '] [' . $nivel . '] ' . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX);
}

// ─── AUTENTICAÇÃO ADMIN ──────────────────────────────────────────────────────
function verificar_admin() {
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Autenticação necessária'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $permissao = strtolower($_SESSION['usuario_permissao'] ?? '');
    if (!in_array($permissao, ['admin', 'administrador', 'gerente'])) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Acesso negado. Requer permissão de administrador.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return [
        'id'        => $_SESSION['usuario_id']   ?? null,
        'nome'      => $_SESSION['usuario_nome'] ?? 'Admin',
        'permissao' => $permissao,
    ];
}

// ─── RETORNO JSON ─────────────────────────────────────────────────────────────
function retornar($sucesso, $mensagem, $dados = null, $codigo = 200) {
    http_response_code($codigo);
    $resp = ['sucesso' => $sucesso, 'mensagem' => $mensagem, 'timestamp' => date('Y-m-d H:i:s')];
    if ($dados !== null) $resp['dados'] = $dados;
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── VERIFICAR/CRIAR TABELAS ──────────────────────────────────────────────────
function garantir_tabelas($conexao) {
    // config_sessao
    $conexao->query("CREATE TABLE IF NOT EXISTS `config_sessao` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `chave` VARCHAR(100) NOT NULL,
        `valor` VARCHAR(255) NOT NULL,
        `descricao` TEXT DEFAULT NULL,
        `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `chave` (`chave`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Inserir defaults se não existirem
    $conexao->query("INSERT IGNORE INTO `config_sessao` (`chave`, `valor`, `descricao`) VALUES
        ('timeout_total_min',       '30',  'Tempo total máximo de sessão em minutos'),
        ('timeout_inatividade_min', '10',  'Tempo de inatividade antes de encerrar sessão em minutos'),
        ('renovacao_automatica',    '1',   '1 = renovar automaticamente ao detectar atividade'),
        ('aviso_expiracao_min',     '5',   'Minutos antes de expirar para exibir aviso')");

    // Colunas em moradores
    $conexao->query("ALTER TABLE `moradores`
        ADD COLUMN IF NOT EXISTS `timeout_total_min`      SMALLINT UNSIGNED DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `timeout_inatividade_min` SMALLINT UNSIGNED DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `sessao_personalizada`    TINYINT(1) DEFAULT 0");

    // Colunas em sessoes_portal
    $conexao->query("ALTER TABLE `sessoes_portal`
        ADD COLUMN IF NOT EXISTS `ultimo_ativo`            DATETIME DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `timeout_total_min`       SMALLINT UNSIGNED DEFAULT 30,
        ADD COLUMN IF NOT EXISTS `timeout_inatividade_min` SMALLINT UNSIGNED DEFAULT 10");
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
$admin   = verificar_admin();
$conexao = conectar_banco();
$metodo  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';

garantir_tabelas($conexao);

// ══════════════════════════════════════════════════════════════════════════════
// GET: Listar dados
// ══════════════════════════════════════════════════════════════════════════════
if ($metodo === 'GET') {

    // ── Listar moradores com configurações de sessão ──────────────────────────
    if ($action === 'moradores' || $action === '') {
        $busca  = $_GET['busca']    ?? '';
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $por_pag = min(50, max(10, (int)($_GET['por_pagina'] ?? 20)));
        $offset = ($pagina - 1) * $por_pag;

        $where = 'WHERE m.ativo = 1';
        $params = [];
        $tipos  = '';

        if (!empty($busca)) {
            $where   .= ' AND (m.nome LIKE ? OR m.unidade LIKE ? OR m.email LIKE ?)';
            $like     = '%' . $busca . '%';
            $params   = [$like, $like, $like];
            $tipos    = 'sss';
        }

        // Total
        $sql_total = "SELECT COUNT(*) AS total FROM moradores m $where";
        $stmt = $conexao->prepare($sql_total);
        if (!empty($params)) { $stmt->bind_param($tipos, ...$params); }
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        // Dados
        $sql = "SELECT
                    m.id, m.nome, m.unidade, m.email, m.ativo,
                    COALESCE(m.sessao_personalizada, 0) AS sessao_personalizada,
                    m.timeout_total_min, m.timeout_inatividade_min,
                    m.ultimo_acesso,
                    (SELECT COUNT(*) FROM sessoes_portal sp
                     WHERE sp.morador_id = m.id AND sp.ativo = 1
                       AND sp.data_expiracao > NOW()) AS sessoes_ativas
                FROM moradores m
                $where
                ORDER BY m.nome ASC
                LIMIT ? OFFSET ?";

        $stmt = $conexao->prepare($sql);
        if (!empty($params)) {
            $tipos .= 'ii';
            $params[] = $por_pag;
            $params[] = $offset;
            $stmt->bind_param($tipos, ...$params);
        } else {
            $stmt->bind_param('ii', $por_pag, $offset);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $itens = [];
        while ($row = $res->fetch_assoc()) $itens[] = $row;
        $stmt->close();

        retornar(true, 'Moradores carregados', [
            'itens'        => $itens,
            'total'        => (int)$total,
            'pagina'       => $pagina,
            'por_pagina'   => $por_pag,
            'total_paginas'=> (int)ceil($total / $por_pag),
        ]);
    }

    // ── Listar sessões ativas ─────────────────────────────────────────────────
    if ($action === 'sessoes_ativas') {
        $stmt = $conexao->prepare("
            SELECT
                sp.id, sp.morador_id, sp.data_login, sp.data_expiracao,
                sp.ultimo_ativo, sp.ip_address, sp.timeout_total_min,
                sp.timeout_inatividade_min, sp.ativo,
                m.nome AS morador_nome, m.unidade AS morador_unidade,
                TIMESTAMPDIFF(MINUTE, sp.data_login, NOW()) AS minutos_logado,
                TIMESTAMPDIFF(MINUTE, sp.ultimo_ativo, NOW()) AS minutos_inativo
            FROM sessoes_portal sp
            INNER JOIN moradores m ON m.id = sp.morador_id
            WHERE sp.ativo = 1 AND sp.data_expiracao > NOW()
            ORDER BY sp.data_login DESC
        ");
        $stmt->execute();
        $res = $stmt->get_result();
        $sessoes = [];
        while ($row = $res->fetch_assoc()) $sessoes[] = $row;
        $stmt->close();

        retornar(true, 'Sessões ativas carregadas', ['itens' => $sessoes, 'total' => count($sessoes)]);
    }

    // ── Configurações globais ─────────────────────────────────────────────────
    if ($action === 'config_global') {
        $stmt = $conexao->prepare("SELECT chave, valor, descricao FROM config_sessao ORDER BY chave");
        $stmt->execute();
        $res = $stmt->get_result();
        $config = [];
        while ($row = $res->fetch_assoc()) $config[$row['chave']] = $row;
        $stmt->close();
        retornar(true, 'Configurações carregadas', $config);
    }

    // ── Histórico de sessões de um morador ────────────────────────────────────
    if ($action === 'historico') {
        $morador_id = (int)($_GET['morador_id'] ?? 0);
        if (!$morador_id) retornar(false, 'morador_id obrigatório', null, 400);

        $stmt = $conexao->prepare("
            SELECT sp.id, sp.data_login, sp.data_expiracao, sp.ultimo_ativo,
                   sp.ip_address, sp.ativo,
                   TIMESTAMPDIFF(MINUTE, sp.data_login, COALESCE(sp.ultimo_ativo, sp.data_login)) AS duracao_min
            FROM sessoes_portal sp
            WHERE sp.morador_id = ?
            ORDER BY sp.data_login DESC
            LIMIT 30
        ");
        $stmt->bind_param('i', $morador_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $hist = [];
        while ($row = $res->fetch_assoc()) $hist[] = $row;
        $stmt->close();

        retornar(true, 'Histórico carregado', ['itens' => $hist, 'total' => count($hist)]);
    }

    retornar(false, 'Ação não encontrada: ' . htmlspecialchars($action), null, 404);
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: Ações de escrita
// ══════════════════════════════════════════════════════════════════════════════
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $dados['action'] ?? $_GET['action'] ?? '';

    // ── Configurar timeout de um morador ─────────────────────────────────────
    if ($action === 'configurar_morador') {
        $morador_id          = (int)($dados['morador_id'] ?? 0);
        $sessao_personalizada = (int)($dados['sessao_personalizada'] ?? 0);
        $timeout_total       = isset($dados['timeout_total_min'])       ? (int)$dados['timeout_total_min']       : null;
        $timeout_inatividade = isset($dados['timeout_inatividade_min']) ? (int)$dados['timeout_inatividade_min'] : null;

        if (!$morador_id) retornar(false, 'morador_id obrigatório', null, 400);

        // Validações
        if ($timeout_total !== null && ($timeout_total < 5 || $timeout_total > 480)) {
            retornar(false, 'timeout_total_min deve ser entre 5 e 480 minutos', null, 400);
        }
        if ($timeout_inatividade !== null && ($timeout_inatividade < 2 || $timeout_inatividade > 120)) {
            retornar(false, 'timeout_inatividade_min deve ser entre 2 e 120 minutos', null, 400);
        }
        if ($timeout_total !== null && $timeout_inatividade !== null && $timeout_inatividade >= $timeout_total) {
            retornar(false, 'timeout_inatividade_min deve ser menor que timeout_total_min', null, 400);
        }

        $stmt = $conexao->prepare("
            UPDATE moradores SET
                sessao_personalizada    = ?,
                timeout_total_min       = ?,
                timeout_inatividade_min = ?
            WHERE id = ?
        ");
        $stmt->bind_param('iiii', $sessao_personalizada, $timeout_total, $timeout_inatividade, $morador_id);
        $stmt->execute();
        $stmt->close();

        log_admin("Admin {$admin['nome']} configurou sessão do morador ID {$morador_id}: personalizada={$sessao_personalizada}, total={$timeout_total}min, inatividade={$timeout_inatividade}min");
        retornar(true, 'Configuração de sessão atualizada com sucesso');
    }

    // ── Encerrar sessão de um morador ─────────────────────────────────────────
    if ($action === 'encerrar_sessao') {
        $sessao_id  = (int)($dados['sessao_id']  ?? 0);
        $morador_id = (int)($dados['morador_id'] ?? 0);

        if ($sessao_id) {
            $stmt = $conexao->prepare("UPDATE sessoes_portal SET ativo = 0 WHERE id = ?");
            $stmt->bind_param('i', $sessao_id);
            $stmt->execute();
            $stmt->close();
            log_admin("Admin {$admin['nome']} encerrou sessão ID {$sessao_id}");
            retornar(true, 'Sessão encerrada com sucesso');
        } elseif ($morador_id) {
            $stmt = $conexao->prepare("UPDATE sessoes_portal SET ativo = 0 WHERE morador_id = ? AND ativo = 1");
            $stmt->bind_param('i', $morador_id);
            $stmt->execute();
            $af = $stmt->affected_rows;
            $stmt->close();
            log_admin("Admin {$admin['nome']} encerrou {$af} sessão(ões) do morador ID {$morador_id}");
            retornar(true, "Todas as sessões do morador foram encerradas ({$af} sessão(ões))");
        }

        retornar(false, 'Informe sessao_id ou morador_id', null, 400);
    }

    // ── Encerrar TODAS as sessões ativas ──────────────────────────────────────
    if ($action === 'encerrar_todas') {
        $conexao->query("UPDATE sessoes_portal SET ativo = 0 WHERE ativo = 1");
        $af = $conexao->affected_rows;
        log_admin("Admin {$admin['nome']} encerrou TODAS as sessões ativas ({$af} sessões)", 'WARN');
        retornar(true, "Todas as {$af} sessões foram encerradas");
    }

    // ── Salvar configurações globais ──────────────────────────────────────────
    if ($action === 'salvar_config_global') {
        $campos_permitidos = ['timeout_total_min', 'timeout_inatividade_min', 'aviso_expiracao_min', 'renovacao_automatica'];

        $atualizados = 0;
        foreach ($campos_permitidos as $campo) {
            if (isset($dados[$campo])) {
                $valor = (string)$dados[$campo];
                // Validações específicas
                if ($campo === 'timeout_total_min' && ((int)$valor < 5 || (int)$valor > 480)) continue;
                if ($campo === 'timeout_inatividade_min' && ((int)$valor < 2 || (int)$valor > 120)) continue;
                if ($campo === 'aviso_expiracao_min' && ((int)$valor < 1 || (int)$valor > 30)) continue;

                $stmt = $conexao->prepare("INSERT INTO config_sessao (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?");
                $stmt->bind_param('sss', $campo, $valor, $valor);
                $stmt->execute();
                $stmt->close();
                $atualizados++;
            }
        }

        log_admin("Admin {$admin['nome']} atualizou {$atualizados} configuração(ões) global(is) de sessão");
        retornar(true, "Configurações globais salvas ({$atualizados} campo(s) atualizado(s))");
    }

    // ── Remover configuração personalizada de um morador ─────────────────────
    if ($action === 'remover_personalizacao') {
        $morador_id = (int)($dados['morador_id'] ?? 0);
        if (!$morador_id) retornar(false, 'morador_id obrigatório', null, 400);

        $stmt = $conexao->prepare("UPDATE moradores SET sessao_personalizada=0, timeout_total_min=NULL, timeout_inatividade_min=NULL WHERE id=?");
        $stmt->bind_param('i', $morador_id);
        $stmt->execute();
        $stmt->close();

        log_admin("Admin {$admin['nome']} removeu personalização de sessão do morador ID {$morador_id}");
        retornar(true, 'Personalização removida. Morador usará configuração padrão do sistema.');
    }

    retornar(false, 'Ação não encontrada: ' . htmlspecialchars($action), null, 404);
}

http_response_code(405);
echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
?>
