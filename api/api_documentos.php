<?php
/**
 * API Unificada — GED (Gestão Eletrônica de Documentos)
 *
 * Ações GET:
 *   dashboard_stats, departamentos_listar (somente leitura — consome o
 *   cadastro central "departamentos", gerido em Configurações → Sistema →
 *   Departamentos; o GED não possui mais CRUD próprio de departamentos),
 *   grupos_listar, pastas_listar, documentos_listar, documento_carregar,
 *   compartilhamentos_listar, acessos_listar, logs_listar,
 *   download, grupo_membros
 *
 * Ações POST:
 *   grupo_salvar, grupo_excluir, grupo_membro_add, grupo_membro_remove,
 *   pasta_salvar, pasta_excluir,
 *   documento_salvar, documento_excluir,
 *   compartilhamento_gerar, compartilhamento_desativar
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Segurança: qualquer exceção ou erro fatal não tratado deve virar um JSON
// padronizado — nunca um HTML de erro ou um corpo vazio com status 500.
// Mesmo padrão já usado em api_leituras.php.
set_exception_handler(function ($e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('Exception em api_documentos: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno no servidor: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        error_log('Fatal error em api_documentos: ' . print_r($err, true));
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro fatal no servidor: ' . $err['message']], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

verificarAutenticacao();

$db  = conectar_banco();
$tenant_id = exigirTenantId();
if (!$db) retornar_json(false, 'Erro ao conectar ao banco de dados');
$db->set_charset('utf8mb4');

_criar_tabelas($db);

$sessao = _sessao();
$acao   = $_GET['acao'] ?? $_POST['acao'] ?? '';

switch ($acao) {
    // ── Leitura ──────────────────────────────────────────────
    case 'dashboard_stats':       _dashboard_stats($db);       break;
    case 'departamentos_listar':  _departamentos_listar($db);  break;
    case 'grupos_listar':         _grupos_listar($db);         break;
    case 'grupo_membros':         _grupo_membros($db);         break;
    case 'pastas_listar':         _pastas_listar($db);         break;
    case 'documentos_listar':     _documentos_listar($db);     break;
    case 'documento_carregar':    _documento_carregar($db);    break;
    case 'compartilhamentos_listar': _compartilhamentos_listar($db); break;
    case 'acessos_listar':        _acessos_listar($db);        break;
    case 'logs_listar':           _logs_listar($db);           break;
    case 'download':              _download($db, $sessao);     break;
    case 'tipos_listar':          _tipos_listar($db);          break;
    case 'relatorio_geral':       _relatorio_geral($db);       break;
    case 'unidades_select':       _unidades_select($db);       break;
    case 'usuarios_sistema':      _usuarios_sistema($db);      break;
    case 'doc_usuarios':          _doc_usuarios($db);          break;

    // ── Escrita ───────────────────────────────────────────────
    // Departamentos não têm mais criação/edição/exclusão aqui — são
    // gerenciados exclusivamente em Configurações → Sistema → Departamentos.
    case 'grupo_salvar':          _grupo_salvar($db, $sessao);         break;
    case 'grupo_excluir':         _grupo_excluir($db);                 break;
    case 'grupo_membro_add':      _grupo_membro_add($db);              break;
    case 'grupo_membro_remove':   _grupo_membro_remove($db);           break;
    case 'pasta_salvar':          _pasta_salvar($db, $sessao);         break;
    case 'pasta_excluir':         _pasta_excluir($db);                 break;
    case 'documento_salvar':      _documento_salvar($db, $sessao);     break;
    case 'documento_excluir':     _documento_excluir($db, $sessao);    break;
    case 'compartilhamento_gerar':    _compartilhamento_gerar($db, $sessao);    break;
    case 'compartilhamento_desativar':_compartilhamento_desativar($db);         break;
    case 'tipo_salvar':           _tipo_salvar($db, $sessao);  break;
    case 'tipo_excluir':          _tipo_excluir($db);          break;

    default:
        retornar_json(false, "Ação '$acao' não reconhecida.");
}

// ============================================================
// CRIAÇÃO AUTOMÁTICA DE TABELAS
// ============================================================
function _criar_tabelas($db) {
    // Departamentos: cadastro central único (Configurações → Sistema → Departamentos).
    // O GED apenas consome esta tabela — não possui mais cadastro próprio.
    $db->query("CREATE TABLE IF NOT EXISTS `departamentos` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `nome`          VARCHAR(100) NOT NULL,
        `descricao`     VARCHAR(255) DEFAULT NULL,
        `ativo`         TINYINT(1) NOT NULL DEFAULT 1,
        `criado_em`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_nome` (`nome`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    _migrar_departamentos_legado($db);

    $db->query("CREATE TABLE IF NOT EXISTS `documentos_grupos` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL,
        `descricao` TEXT,
        `acesso_tipo` ENUM('todos','moradores','administradores','conselho','diretoria',
                           'financeiro','juridico','portaria','manutencao',
                           'prestadores','visitantes','personalizado') NOT NULL DEFAULT 'todos',
        `ativo` TINYINT(1) NOT NULL DEFAULT 1,
        `criado_por` INT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `documentos_grupos_usuarios` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `grupo_id` INT NOT NULL,
        `usuario_id` INT NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_gu` (`grupo_id`,`usuario_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `documentos_grupos_moradores` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `grupo_id` INT NOT NULL,
        `morador_id` INT NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_gm` (`grupo_id`,`morador_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `documentos_pastas` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nome` VARCHAR(200) NOT NULL,
        `departamento_id` INT DEFAULT NULL,
        `pasta_pai_id` INT DEFAULT NULL,
        `descricao` TEXT,
        `ordem` SMALLINT UNSIGNED DEFAULT 0,
        `ativo` TINYINT(1) NOT NULL DEFAULT 1,
        `criado_por` INT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `documentos` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nome` VARCHAR(300) NOT NULL,
        `descricao` TEXT,
        `departamento_id` INT DEFAULT NULL,
        `pasta_id` INT DEFAULT NULL,
        `grupo_id` INT DEFAULT NULL,
        `tags` TEXT,
        `arquivo` VARCHAR(600) DEFAULT NULL,
        `arquivo_tipo` VARCHAR(100) DEFAULT NULL,
        `arquivo_tamanho` BIGINT DEFAULT 0,
        `arquivo_nome_original` VARCHAR(500) DEFAULT NULL,
        `link_externo` VARCHAR(1000) DEFAULT NULL,
        `status` ENUM('ativo','inativo','expirado','rascunho') NOT NULL DEFAULT 'ativo',
        `data_publicacao` DATE DEFAULT NULL,
        `data_expiracao` DATE DEFAULT NULL,
        `total_downloads` INT UNSIGNED DEFAULT 0,
        `total_visualizacoes` INT UNSIGNED DEFAULT 0,
        `criado_por` INT DEFAULT NULL,
        `atualizado_por` INT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `documentos_compartilhamentos` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `documento_id` INT NOT NULL,
        `token` VARCHAR(64) NOT NULL,
        `descricao` VARCHAR(300) DEFAULT NULL,
        `expira_em` DATETIME DEFAULT NULL,
        `limite_acessos` INT UNSIGNED DEFAULT NULL,
        `total_acessos` INT UNSIGNED DEFAULT 0,
        `ativo` TINYINT(1) NOT NULL DEFAULT 1,
        `criado_por` INT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_token` (`token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `documentos_acessos` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `documento_id` INT NOT NULL,
        `tipo` ENUM('visualizacao','download','compartilhamento') NOT NULL DEFAULT 'visualizacao',
        `origem` ENUM('interno','externo') NOT NULL DEFAULT 'interno',
        `usuario_id` INT DEFAULT NULL,
        `usuario_nome` VARCHAR(200) DEFAULT NULL,
        `usuario_perfil` VARCHAR(100) DEFAULT NULL,
        `morador_id` INT DEFAULT NULL,
        `token_compartilhamento` VARCHAR(64) DEFAULT NULL,
        `ip` VARCHAR(45) DEFAULT NULL,
        `user_agent` TEXT,
        `referer` VARCHAR(500) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_doc` (`documento_id`),
        KEY `idx_ts` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `documentos_logs` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `documento_id` INT DEFAULT NULL,
        `usuario_id` INT DEFAULT NULL,
        `acao` ENUM('criacao','edicao','exclusao','download','visualizacao',
                    'compartilhamento','expiracao','restauracao','upload') NOT NULL,
        `descricao` TEXT,
        `ip` VARCHAR(45) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_doc` (`documento_id`),
        KEY `idx_ts`  (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabela de Tipos de Documento
    $db->query("CREATE TABLE IF NOT EXISTS `documentos_tipos` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL,
        `descricao` TEXT,
        `icone` VARCHAR(60) DEFAULT 'fas fa-file-alt',
        `cor` VARCHAR(7) DEFAULT '#2563eb',
        `ativo` TINYINT(1) NOT NULL DEFAULT 1,
        `criado_por` INT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Inserir tipos padrão se a tabela estiver vazia
    $rTipos = $db->query("SELECT COUNT(*) c FROM documentos_tipos");
    if ($rTipos && (int)$rTipos->fetch_assoc()['c'] === 0) {
        $db->query("INSERT INTO documentos_tipos (nome, descricao, icone, cor) VALUES
            ('ATA', 'Atas de reuniões e assembleias', 'fas fa-file-signature', '#2563eb'),
            ('Estatuto', 'Estatuto social e regimento interno', 'fas fa-scroll', '#7c3aed'),
            ('Nota Fiscal', 'Notas fiscais e documentos fiscais', 'fas fa-file-invoice-dollar', '#059669'),
            ('Contrato', 'Contratos e aditivos', 'fas fa-file-contract', '#d97706'),
            ('Comunicado', 'Comunicados e circulares', 'fas fa-bullhorn', '#dc2626'),
            ('Relatório', 'Relatórios e prestações de contas', 'fas fa-chart-bar', '#0891b2'),
            ('Regulamento', 'Regulamentos internos', 'fas fa-gavel', '#9333ea'),
            ('Planta', 'Plantas e projetos', 'fas fa-drafting-compass', '#16a34a'),
            ('Manual', 'Manuais e instruções', 'fas fa-book', '#ea580c'),
            ('Outro', 'Outros documentos', 'fas fa-file', '#64748b')
        ");
    }

    // Adicionar colunas tipo_id e unidades_acesso se não existirem
    $rCols = $db->query("SHOW COLUMNS FROM documentos LIKE 'tipo_id'");
    if ($rCols && $rCols->num_rows === 0) {
        $db->query("ALTER TABLE documentos ADD COLUMN `tipo_id` INT DEFAULT NULL AFTER `grupo_id`");
    }
    $rCols2 = $db->query("SHOW COLUMNS FROM documentos LIKE 'unidades_acesso'");
    if ($rCols2 && $rCols2->num_rows === 0) {
        $db->query("ALTER TABLE documentos ADD COLUMN `unidades_acesso` TEXT DEFAULT NULL COMMENT 'JSON array de IDs de unidades ou null para todas' AFTER `tipo_id`");
    }
    // Tabela de permissões individuais por usuário
    $rTabUA = $db->query("SHOW TABLES LIKE 'documentos_usuarios_acesso'");
    if ($rTabUA && $rTabUA->num_rows === 0) {
        $db->query("CREATE TABLE IF NOT EXISTS `documentos_usuarios_acesso` (
            `id`           INT NOT NULL AUTO_INCREMENT,
            `documento_id` INT NOT NULL,
            `usuario_id`   INT NOT NULL,
            `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_doc_usr` (`documento_id`, `usuario_id`),
            KEY `idx_documento_id` (`documento_id`),
            KEY `idx_usuario_id`   (`usuario_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Permissoes individuais de visualizacao por usuario'");
    }
    $rCols3 = $db->query("SHOW COLUMNS FROM documentos LIKE 'visibilidade'");
    if ($rCols3 && $rCols3->num_rows === 0) {
        $db->query("ALTER TABLE documentos ADD COLUMN `visibilidade` ENUM('todos','moradores','usuarios','unidades_especificas') NOT NULL DEFAULT 'todos' AFTER `unidades_acesso`");
    }

    // Garantir diretório de uploads
    $dir = dirname(__DIR__) . '/uploads/documentos';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess',
            "Order Deny,Allow\nDeny from all\n"
        );
    }
}

// ============================================================
// MIGRAÇÃO ÚNICA: documentos_departamentos (GED) → departamentos (central)
// ============================================================
// Roda uma única vez: casa por nome (case-insensitive) com o cadastro
// central, cria lá os departamentos que só existiam no GED, remapeia
// documentos/pastas para o novo id central e renomeia (não apaga) a
// tabela antiga — ela vira o guard de idempotência: se não existir mais
// com esse nome, a migração já ocorreu e a função retorna imediatamente.
function _migrar_departamentos_legado($db) {
    $rExiste = $db->query("SHOW TABLES LIKE 'documentos_departamentos'");
    if (!$rExiste || $rExiste->num_rows === 0) return;

    $rOld = $db->query("SELECT * FROM documentos_departamentos");
    if ($rOld) {
        while ($old = $rOld->fetch_assoc()) {
            $oldId    = (int)$old['id'];
            $nomeEsc  = $db->real_escape_string(trim($old['nome']));

            $rMatch = $db->query("SELECT id FROM departamentos WHERE tenant_id = $tenant_id AND UPPER(nome) = UPPER('$nomeEsc') LIMIT 1");
            $match  = $rMatch ? $rMatch->fetch_assoc() : null;

            if ($match) {
                $novoId = (int)$match['id'];
            } else {
                $descEsc = $db->real_escape_string(trim($old['descricao'] ?? ''));
                $ativo   = (int)$old['ativo'];
                $db->query("INSERT INTO departamentos (nome, descricao, ativo) VALUES ('$nomeEsc','$descEsc',$ativo)");
                $novoId = $db->insert_id;
            }

            $db->query("UPDATE documentos SET departamento_id=$novoId WHERE tenant_id = $tenant_id AND departamento_id=$oldId");
            $db->query("UPDATE documentos_pastas SET departamento_id=$novoId WHERE tenant_id = $tenant_id AND departamento_id=$oldId");
        }
    }

    $db->query("RENAME TABLE documentos_departamentos TO documentos_departamentos_migrado_bkp");
}

// ============================================================
// HELPERS
// ============================================================
function _sessao(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return [
        'id'     => $_SESSION['usuario_id']    ?? null,
        'nome'   => $_SESSION['usuario_nome']  ?? 'Sistema',
        'perfil' => $_SESSION['usuario_perfil'] ?? 'operador',
    ];
}

function _esc($db, string $v): string {
    return $db->real_escape_string(trim($v));
}

function _ip(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

function _log($db, $sessao, ?int $docId, string $acao, string $desc): void {
    $uid = (int)($sessao['id'] ?? 0);
    $ip  = _esc($db, _ip());
    $a   = _esc($db, $acao);
    $d   = _esc($db, $desc);
    $didSql = $docId ? $docId : 'NULL';
    $db->query("INSERT INTO documentos_logs (documento_id, usuario_id, acao, descricao, ip)
                VALUES ($didSql, $uid, '$a', '$d', '$ip')");
}

function _registrar_acesso($db, $sessao, int $docId, string $tipo, string $origem): void {
    $uid   = (int)($sessao['id'] ?? 0);
    $nome  = _esc($db, $sessao['nome'] ?? '');
    $perf  = _esc($db, $sessao['perfil'] ?? '');
    $ip    = _esc($db, _ip());
    $ua    = _esc($db, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
    $ref   = _esc($db, substr($_SERVER['HTTP_REFERER']    ?? '', 0, 499));

    $db->query("INSERT INTO documentos_acessos
                (documento_id, tipo, origem, usuario_id, usuario_nome, usuario_perfil, ip, user_agent, referer)
                VALUES ($docId, '$tipo', '$origem', " . ($uid ?: 'NULL') . ", '$nome', '$perf', '$ip', '$ua', '$ref')");

    // Incrementar contador
    if ($tipo === 'download') {
        $db->query("UPDATE documentos SET total_downloads = total_downloads + 1 WHERE id = $docId");
    } elseif ($tipo === 'visualizacao') {
        $db->query("UPDATE documentos SET total_visualizacoes = total_visualizacoes + 1 WHERE tenant_id = $tenant_id AND id = $docId");
    }
}

// ============================================================
// DASHBOARD
// ============================================================
function _dashboard_stats($db) {
    $hoje = date('Y-m-d');

    $rTotal    = $db->query("SELECT COUNT(*) c FROM documentos WHERE tenant_id = $tenant_id AND status = 'ativo'");
    $rHoje     = $db->query("SELECT COUNT(*) c FROM documentos WHERE tenant_id = $tenant_id AND DATE(created_at) = '$hoje'");
    $rDow      = $db->query("SELECT SUM(total_downloads) c FROM documentos");
    $rVis      = $db->query("SELECT SUM(total_visualizacoes) c FROM documentos");
    $rExp      = $db->query("SELECT COUNT(*) c FROM documentos WHERE tenant_id = $tenant_id AND status='ativo' AND data_expiracao IS NOT NULL AND data_expiracao < '$hoje'");
    $rComp     = $db->query("SELECT COUNT(*) c FROM documentos_compartilhamentos WHERE ativo=1");

    // Qualquer uma destas pode falhar (tabela/coluna ausente, permissão de DDL
    // insuficiente para o CREATE TABLE em _criar_tabelas etc.) — sem a guarda
    // "$r ? ... : 0", chamar fetch_assoc() em `false` é erro fatal em PHP e
    // gerava um HTTP 500 com corpo vazio em vez de um JSON de erro.
    $total    = $rTotal ? (int)$rTotal->fetch_assoc()['c'] : 0;
    $novosHj  = $rHoje  ? (int)$rHoje->fetch_assoc()['c']  : 0;
    $downloads= $rDow   ? (int)($rDow->fetch_assoc()['c'] ?? 0) : 0;
    $visu     = $rVis   ? (int)($rVis->fetch_assoc()['c'] ?? 0) : 0;
    $expirand = $rExp   ? (int)$rExp->fetch_assoc()['c']  : 0;
    $links    = $rComp  ? (int)$rComp->fetch_assoc()['c'] : 0;

    if (!$rTotal || !$rHoje || !$rDow || !$rVis || !$rExp || !$rComp) {
        error_log('api_documentos._dashboard_stats: falha em uma ou mais consultas — ' . $db->error);
    }

    // Top 5 mais acessados
    $rTop = $db->query("SELECT d.id, d.nome, d.total_visualizacoes, d.total_downloads,
                               dep.nome AS departamento
                        FROM documentos d
                        LEFT JOIN departamentos dep ON d.departamento_id = dep.id
                        WHERE d.status = 'ativo'
                        ORDER BY (d.total_visualizacoes + d.total_downloads) DESC
                        LIMIT 5");
    $topDocs = [];
    if ($rTop) while ($r = $rTop->fetch_assoc()) $topDocs[] = $r;

    // Últimos 5 documentos
    $rUlt = $db->query("SELECT d.id, d.nome, d.status, d.created_at,
                               dep.nome AS departamento
                        FROM documentos d
                        LEFT JOIN departamentos dep ON d.departamento_id = dep.id
                        ORDER BY d.created_at DESC LIMIT 5");
    $ultDocs = [];
    if ($rUlt) while ($r = $rUlt->fetch_assoc()) $ultDocs[] = $r;

    // Últimos 5 acessos
    $rUltAc = $db->query("SELECT a.created_at, a.tipo, a.origem, a.usuario_nome, a.ip,
                                  d.nome AS documento
                           FROM documentos_acessos a
                           LEFT JOIN documentos d ON a.documento_id = d.id
                           ORDER BY a.created_at DESC LIMIT 5");
    $ultAcessos = [];
    if ($rUltAc) while ($r = $rUltAc->fetch_assoc()) $ultAcessos[] = $r;

    retornar_json(true, 'OK', [
        'total_documentos'  => $total,
        'novos_hoje'        => $novosHj,
        'total_downloads'   => $downloads,
        'total_visualizacoes' => $visu,
        'expirando'         => $expirand,
        'links_ativos'      => $links,
        'top_documentos'    => $topDocs,
        'ultimos_documentos' => $ultDocs,
        'ultimos_acessos'   => $ultAcessos,
    ]);
}

// ============================================================
// DEPARTAMENTOS (somente leitura — cadastro central)
// ============================================================
// Fonte única: tabela `departamentos`, gerida em Configurações → Sistema →
// Departamentos (api_departamentos.php). O GED apenas lê e agrega a
// contagem de documentos vinculados; não há criação/edição/exclusão aqui.
function _departamentos_listar($db) {
    $res = $db->query("SELECT d.id, d.nome, d.descricao, d.ativo,
                               COUNT(doc.id) AS total_documentos
                       FROM departamentos d
                       LEFT JOIN documentos doc ON doc.departamento_id = d.id AND doc.status != 'excluido'
                       GROUP BY d.id
                       ORDER BY d.ativo DESC, d.nome ASC");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    retornar_json(true, 'OK', ['departamentos' => $rows]);
}

// Bloqueia associação de um documento/pasta a um departamento inativo,
// mas permite manter o vínculo se ele já existia antes da mudança
// (preserva o histórico quando um departamento é desativado depois).
function _departamento_ativo_para_associacao($db, int $depId, int $depIdAtual): bool {
    if (!$depId || $depId === $depIdAtual) return true;
    $res = $db->query("SELECT ativo FROM departamentos WHERE tenant_id = $tenant_id AND id=$depId LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    return $row && (int)$row['ativo'] === 1;
}

// ============================================================
// GRUPOS
// ============================================================
function _grupos_listar($db) {
    $res = $db->query("SELECT g.*,
                              COUNT(DISTINCT gu.usuario_id) AS total_usuarios,
                              COUNT(DISTINCT gm.morador_id) AS total_moradores
                       FROM documentos_grupos g
                       LEFT JOIN documentos_grupos_usuarios  gu ON gu.grupo_id = g.id
                       LEFT JOIN documentos_grupos_moradores gm ON gm.grupo_id = g.id
                       WHERE g.ativo = 1
                       GROUP BY g.id
                       ORDER BY g.nome ASC");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    retornar_json(true, 'OK', ['grupos' => $rows]);
}

function _grupo_membros($db) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');

    $rU = $db->query("SELECT u.id, u.nome, u.email, 'usuario' AS tipo
                      FROM documentos_grupos_usuarios gu
                      JOIN usuarios u ON u.id = gu.usuario_id
                      WHERE gu.grupo_id = $id");
    $rM = $db->query("SELECT m.id, m.nome, m.email, 'morador' AS tipo
                      FROM documentos_grupos_moradores gm
                      JOIN moradores m ON m.id = gm.morador_id
                      WHERE gm.grupo_id = $id");
    $membros = [];
    if ($rU) while ($r = $rU->fetch_assoc()) $membros[] = $r;
    if ($rM) while ($r = $rM->fetch_assoc()) $membros[] = $r;
    retornar_json(true, 'OK', ['membros' => $membros]);
}

function _grupo_salvar($db, $sessao) {
    $id   = (int)($_POST['id'] ?? 0);
    $nome = _esc($db, $_POST['nome'] ?? '');
    $desc = _esc($db, $_POST['descricao'] ?? '');
    $tipo = _esc($db, $_POST['acesso_tipo'] ?? 'todos');

    if (!$nome) retornar_json(false, 'Nome é obrigatório.');

    if ($id) {
        $db->query("UPDATE documentos_grupos SET nome='$nome',descricao='$desc',acesso_tipo='$tipo' WHERE tenant_id = $tenant_id AND id=$id");
        retornar_json(true, 'Grupo atualizado.');
    } else {
        $uid = (int)($sessao['id'] ?? 0);
        $db->query("INSERT INTO documentos_grupos (nome,descricao,acesso_tipo,criado_por)
                    VALUES ('$nome','$desc','$tipo',$uid)");
        retornar_json(true, 'Grupo criado.', ['id' => $db->insert_id]);
    }
}

function _grupo_excluir($db) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');
    $db->query("UPDATE documentos_grupos SET ativo=0 WHERE tenant_id = $tenant_id AND id=$id");
    retornar_json(true, 'Grupo removido.');
}

function _grupo_membro_add($db) {
    $gid  = (int)($_POST['grupo_id']  ?? 0);
    $uid  = (int)($_POST['usuario_id'] ?? 0);
    $mid  = (int)($_POST['morador_id'] ?? 0);
    if (!$gid) retornar_json(false, 'grupo_id inválido.');

    if ($uid) {
        $db->query("INSERT IGNORE INTO documentos_grupos_usuarios (grupo_id,usuario_id) VALUES ($gid,$uid)");
    }
    if ($mid) {
        $db->query("INSERT IGNORE INTO documentos_grupos_moradores (grupo_id,morador_id) VALUES ($gid,$mid)");
    }
    retornar_json(true, 'Membro adicionado.');
}

function _grupo_membro_remove($db) {
    $gid  = (int)($_POST['grupo_id']  ?? 0);
    $uid  = (int)($_POST['usuario_id'] ?? 0);
    $mid  = (int)($_POST['morador_id'] ?? 0);
    if ($uid) $db->query("DELETE FROM documentos_grupos_usuarios WHERE grupo_id=$gid AND usuario_id=$uid");
    if ($mid) $db->query("DELETE FROM documentos_grupos_moradores WHERE grupo_id=$gid AND morador_id=$mid");
    retornar_json(true, 'Membro removido.');
}

// ============================================================
// PASTAS
// ============================================================
function _pastas_listar($db) {
    $depId = (int)($_GET['departamento_id'] ?? 0);
    $where = $depId ? "WHERE p.ativo=1 AND p.departamento_id=$depId" : "WHERE p.ativo=1";
    $res = $db->query("SELECT p.*, dep.nome AS departamento_nome, dep.ativo AS departamento_ativo,
                              pai.nome AS pasta_pai_nome,
                              COUNT(doc.id) AS total_documentos
                       FROM documentos_pastas p
                       LEFT JOIN departamentos dep ON dep.id = p.departamento_id
                       LEFT JOIN documentos_pastas pai ON pai.id = p.pasta_pai_id
                       LEFT JOIN documentos doc ON doc.pasta_id = p.id
                       $where
                       GROUP BY p.id
                       ORDER BY p.departamento_id, p.pasta_pai_id, p.ordem, p.nome");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    retornar_json(true, 'OK', ['pastas' => $rows]);
}

function _pasta_salvar($db, $sessao) {
    $id    = (int)($_POST['id'] ?? 0);
    $nome  = _esc($db, $_POST['nome'] ?? '');
    $depId = (int)($_POST['departamento_id'] ?? 0);
    $paiId = (int)($_POST['pasta_pai_id'] ?? 0);
    $desc  = _esc($db, $_POST['descricao'] ?? '');

    if (!$nome) retornar_json(false, 'Nome é obrigatório.');

    $depIdAtual = 0;
    if ($id) {
        $rCur = $db->query("SELECT departamento_id FROM documentos_pastas WHERE tenant_id = $tenant_id AND id=$id");
        $depIdAtual = $rCur ? (int)($rCur->fetch_assoc()['departamento_id'] ?? 0) : 0;
    }
    if (!_departamento_ativo_para_associacao($db, $depId, $depIdAtual)) {
        retornar_json(false, 'Departamento inativo. Selecione um departamento ativo para novas associações.');
    }

    $paiSql = $paiId ? $paiId : 'NULL';
    $depSql = $depId ? $depId : 'NULL';

    if ($id) {
        $db->query("UPDATE documentos_pastas SET nome='$nome',departamento_id=$depSql,
                    pasta_pai_id=$paiSql,descricao='$desc' WHERE tenant_id = $tenant_id AND id=$id");
        retornar_json(true, 'Pasta atualizada.');
    } else {
        $uid = (int)($sessao['id'] ?? 0);
        $db->query("INSERT INTO documentos_pastas (nome,departamento_id,pasta_pai_id,descricao,criado_por)
                    VALUES ('$nome',$depSql,$paiSql,'$desc',$uid)");
        retornar_json(true, 'Pasta criada.', ['id' => $db->insert_id]);
    }
}

function _pasta_excluir($db) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');
    $res = $db->query("SELECT COUNT(*) c FROM documentos WHERE tenant_id = $tenant_id AND pasta_id=$id");
    if ($res && $res->fetch_assoc()['c'] > 0)
        retornar_json(false, 'Pasta contém documentos. Mova-os antes de excluir.');
    $db->query("UPDATE documentos_pastas SET ativo=0 WHERE tenant_id = $tenant_id AND id=$id");
    retornar_json(true, 'Pasta removida.');
}

// ============================================================
// DOCUMENTOS
// ============================================================
function _documentos_listar($db) {
    $busca  = _esc($db, $_GET['busca'] ?? '');
    $depId  = (int)($_GET['departamento_id'] ?? 0);
    $pastId = (int)($_GET['pasta_id'] ?? 0);
    $status = _esc($db, $_GET['status'] ?? '');
    $grupoId= (int)($_GET['grupo_id'] ?? 0);
    $pag    = max(1, (int)($_GET['pagina'] ?? 1));
    $limit  = 20;
    $offset = ($pag - 1) * $limit;

    $where = "WHERE 1=1";
    if ($busca)  $where .= " AND (d.nome LIKE '%$busca%' OR d.descricao LIKE '%$busca%' OR d.tags LIKE '%$busca%')";
    if ($depId)  $where .= " AND d.departamento_id=$depId";
    if ($pastId) $where .= " AND d.pasta_id=$pastId";
    if ($status) $where .= " AND d.status='$status'";
    if ($grupoId)$where .= " AND d.grupo_id=$grupoId";
    // Filtro de visibilidade: se visibilidade = 'usuarios', só mostra para usuários com permissão
    // Admins e gerentes veem tudo; operadores/visualizadores são filtrados
    $nivel_usuario = $sessao['nivel'] ?? 'operador';
    $uid_logado    = (int)($sessao['id'] ?? 0);
    if (!in_array($nivel_usuario, ['admin', 'gerente'])) {
        $where .= " AND (
            d.visibilidade != 'usuarios'
            OR EXISTS (
                SELECT 1 FROM documentos_usuarios_acesso dua
                WHERE dua.documento_id = d.id AND dua.usuario_id = $uid_logado
            )
        )";
    }

    $sqlCount = "SELECT COUNT(*) c FROM documentos d $where";
    $rCount   = $db->query($sqlCount);
    $total    = $rCount ? (int)$rCount->fetch_assoc()['c'] : 0;

    $sql = "SELECT d.*,
                   dep.nome AS departamento_nome, dep.ativo AS departamento_ativo,
                   p.nome AS pasta_nome,
                   g.nome AS grupo_nome,
                   DATE_FORMAT(d.created_at,'%d/%m/%Y %H:%i') AS criado_em,
                   DATE_FORMAT(d.data_publicacao,'%d/%m/%Y')   AS pub_formatada,
                   DATE_FORMAT(d.data_expiracao,'%d/%m/%Y')    AS exp_formatada
            FROM documentos d
            LEFT JOIN departamentos dep ON dep.id = d.departamento_id
            LEFT JOIN documentos_pastas p ON p.id = d.pasta_id
            LEFT JOIN documentos_grupos  g ON g.id = d.grupo_id
            $where
            ORDER BY d.created_at DESC
            LIMIT $limit OFFSET $offset";

    $res  = $db->query($sql);
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

    retornar_json(true, 'OK', [
        'documentos'  => $rows,
        'total'       => $total,
        'pagina'      => $pag,
        'total_paginas' => (int)ceil($total / $limit),
    ]);
}

function _documento_carregar($db) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');

    $res = $db->query("SELECT d.*,
                              dep.nome AS departamento_nome, dep.ativo AS departamento_ativo,
                              p.nome AS pasta_nome,
                              g.nome AS grupo_nome,
                              DATE_FORMAT(d.data_publicacao,'%d/%m/%Y') AS pub_formatada,
                              DATE_FORMAT(d.data_expiracao,'%d/%m/%Y')  AS exp_formatada
                       FROM documentos d
                       LEFT JOIN departamentos dep ON dep.id = d.departamento_id
                       LEFT JOIN documentos_pastas p ON p.id = d.pasta_id
                       LEFT JOIN documentos_grupos  g ON g.id = d.grupo_id
                       WHERE d.id=$id LIMIT 1");

    if (!$res || $res->num_rows === 0) retornar_json(false, 'Documento não encontrado.');
    $doc = $res->fetch_assoc();

    // Últimos 10 acessos deste documento
    $rAc = $db->query("SELECT tipo, origem, usuario_nome, ip,
                               DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS data_acesso
                        FROM documentos_acessos WHERE documento_id=$id
                        ORDER BY created_at DESC LIMIT 10");
    $acessos = [];
    if ($rAc) while ($r = $rAc->fetch_assoc()) $acessos[] = $r;

    // Usuários com permissão individual
    $rUsr = $db->query("SELECT usuario_id FROM documentos_usuarios_acesso WHERE documento_id=$id");
    $doc['usuarios_acesso_ids'] = [];
    if ($rUsr) while ($ru = $rUsr->fetch_assoc()) $doc['usuarios_acesso_ids'][] = (int)$ru['usuario_id'];
    retornar_json(true, 'OK', ['documento' => $doc, 'acessos' => $acessos]);
}

function _documento_salvar($db, $sessao) {
    $id          = (int)($_POST['id'] ?? 0);
    $nome        = _esc($db, $_POST['nome']    ?? '');
    $desc        = _esc($db, $_POST['descricao'] ?? '');
    $depId       = (int)($_POST['departamento_id'] ?? 0);
    $pastId      = (int)($_POST['pasta_id']   ?? 0);
    $grupoId     = (int)($_POST['grupo_id']   ?? 0);
    $tipoId      = (int)($_POST['tipo_id']    ?? 0);
    $visib       = _esc($db, $_POST['visibilidade'] ?? 'todos');
    $usuariosIds = $_POST['usuarios_ids'] ?? '';  // JSON array de IDs
    $unidAcesso  = _esc($db, $_POST['unidades_acesso'] ?? '');
    $tags        = _esc($db, $_POST['tags']   ?? '');
    $linkExt     = _esc($db, $_POST['link_externo'] ?? '');
    $status      = _esc($db, $_POST['status'] ?? 'ativo');
    $dataPub     = _esc($db, $_POST['data_publicacao'] ?? '');
    $dataExp     = _esc($db, $_POST['data_expiracao']  ?? '');
    $uid         = (int)($sessao['id'] ?? 0);
    if (!$nome) retornar_json(false, 'Nome do documento é obrigatório.');

    $depIdAtual = 0;
    if ($id) {
        $rCur = $db->query("SELECT departamento_id FROM documentos WHERE tenant_id = $tenant_id AND id=$id");
        $depIdAtual = $rCur ? (int)($rCur->fetch_assoc()['departamento_id'] ?? 0) : 0;
    }
    if (!_departamento_ativo_para_associacao($db, $depId, $depIdAtual)) {
        retornar_json(false, 'Departamento inativo. Selecione um departamento ativo para novas associações.');
    }

    $depSql    = $depId   ? $depId   : 'NULL';
    $pastSql   = $pastId  ? $pastId  : 'NULL';
    $grupoSql  = $grupoId ? $grupoId : 'NULL';
    $tipoSqlId = $tipoId  ? $tipoId  : 'NULL';
    $unidSql   = $unidAcesso ? "'$unidAcesso'" : 'NULL';
    $pubSql    = $dataPub ? "'$dataPub'" : 'NULL';
    $expSql    = $dataExp ? "'$dataExp'" : 'NULL';

    // Upload de arquivo
    $arquivoNovo = '';
    $arquivoTipo = '';
    $arquivoTam  = 0;
    $arquivoOrig = '';

    if (!empty($_FILES['arquivo']['tmp_name'])) {
        $upload = _processar_upload($_FILES['arquivo']);
        if (!$upload['success']) retornar_json(false, $upload['error']);
        $arquivoNovo = _esc($db, $upload['path']);
        $arquivoTipo = _esc($db, $upload['mime']);
        $arquivoTam  = (int)$upload['size'];
        $arquivoOrig = _esc($db, $upload['original']);
    }

    if ($id) {
        $setSql = "nome='$nome', descricao='$desc', departamento_id=$depSql, pasta_id=$pastSql,
                   grupo_id=$grupoSql, tipo_id=$tipoSqlId, visibilidade='$visib',
                   unidades_acesso=$unidSql, tags='$tags', link_externo='$linkExt', status='$status',
                   data_publicacao=$pubSql, data_expiracao=$expSql, atualizado_por=$uid";

        if ($arquivoNovo) {
            // Remover arquivo anterior
            $rOld = $db->query("SELECT arquivo FROM documentos WHERE tenant_id = $tenant_id AND id=$id");
            if ($rOld) {
                $old = $rOld->fetch_assoc();
                if (!empty($old['arquivo'])) {
                    $oldPath = dirname(__DIR__) . '/uploads/documentos/' . basename($old['arquivo']);
                    if (file_exists($oldPath)) unlink($oldPath);
                }
            }
            $setSql .= ", arquivo='$arquivoNovo', arquivo_tipo='$arquivoTipo',
                          arquivo_tamanho=$arquivoTam, arquivo_nome_original='$arquivoOrig'";
        }

        $db->query("UPDATE documentos SET $setSql WHERE tenant_id = $tenant_id AND id=$id");
        _log($db, $sessao, $id, 'edicao', "Documento editado: $nome");
        _sincronizar_usuarios_acesso($db, $id, $visib, $usuariosIds);
        retornar_json(true, 'Documento atualizado com sucesso.');
    } else {
        $arqSql   = $arquivoNovo ? "'$arquivoNovo'" : 'NULL';
        $tipoSql  = $arquivoTipo ? "'$arquivoTipo'" : 'NULL';
        $origSql  = $arquivoOrig ? "'$arquivoOrig'" : 'NULL';

        $arqMimeSql = $arquivoTipo ? "'$arquivoTipo'" : 'NULL';
        $db->query("INSERT INTO documentos
                    (nome, descricao, departamento_id, pasta_id, grupo_id, tipo_id,
                     visibilidade, unidades_acesso, tags,
                     arquivo, arquivo_tipo, arquivo_tamanho, arquivo_nome_original,
                     link_externo, status, data_publicacao, data_expiracao, criado_por, atualizado_por)
                    VALUES ('$nome','$desc',$depSql,$pastSql,$grupoSql,$tipoSqlId,
                            '$visib',$unidSql,'$tags',
                            $arqSql,$arqMimeSql,$arquivoTam,$origSql,
                            '$linkExt','$status',$pubSql,$expSql,$uid,$uid)");

        $novoId = $db->insert_id;
        _log($db, $sessao, $novoId, 'criacao', "Documento criado: $nome");

        // Notificação por e-mail (opcional — integra ao sistema existente)
         _notificar_novo_documento($db, $novoId, $nome, $grupoId, $uid);
        _sincronizar_usuarios_acesso($db, $novoId, $visib, $usuariosIds);
        retornar_json(true, 'Documento cadastrado com sucesso.', ['id' => $novoId]);
    }
}

// ============================================================
// FUNÇÕES AUXILIARES — PERMISSÕES POR USUÁRIO
// ============================================================
function _sincronizar_usuarios_acesso($db, int $docId, string $visib, $usuariosIdsRaw): void {
    // Remover todos os vínculos anteriores
    $db->query("DELETE FROM documentos_usuarios_acesso WHERE documento_id=$docId");
    if ($visib !== 'usuarios' || empty($usuariosIdsRaw)) return;
    // Decodificar JSON
    $ids = [];
    if (is_array($usuariosIdsRaw)) {
        $ids = $usuariosIdsRaw;
    } else {
        $decoded = json_decode($usuariosIdsRaw, true);
        if (is_array($decoded)) $ids = $decoded;
    }
    foreach ($ids as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) {
            $db->query("INSERT IGNORE INTO documentos_usuarios_acesso (documento_id, usuario_id)
                        VALUES ($docId, $uid)");
        }
    }
}

function _usuarios_sistema($db): void {
    $res = $db->query("SELECT id, nome, email, nivel FROM usuarios WHERE tenant_id = $tenant_id AND ativo = 1 ORDER BY nome ASC");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    retornar_json(true, 'OK', ['usuarios' => $rows]);
}

function _doc_usuarios($db): void {
    $docId = (int)($_GET['id'] ?? 0);
    if (!$docId) retornar_json(false, 'ID inválido.');
    $res = $db->query("SELECT usuario_id FROM documentos_usuarios_acesso WHERE documento_id=$docId");
    $ids = [];
    if ($res) while ($r = $res->fetch_assoc()) $ids[] = (int)$r['usuario_id'];
    retornar_json(true, 'OK', ['usuarios_ids' => $ids]);
}

function _processar_upload(array $file): array {
    $tipos_permitidos = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp',
        'application/zip', 'application/x-zip-compressed',
        'text/plain',
    ];

    $exts_permitidas = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif','webp','zip','txt'];

    $limite_bytes = 50 * 1024 * 1024; // 50 MB

    if ($file['error'] !== UPLOAD_ERR_OK)
        return ['success' => false, 'error' => 'Erro no upload: código ' . $file['error']];

    if ($file['size'] > $limite_bytes)
        return ['success' => false, 'error' => 'Arquivo muito grande. Limite: 50 MB.'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts_permitidas))
        return ['success' => false, 'error' => "Tipo de arquivo .$ext não permitido."];

    // Validar MIME via finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $tipos_permitidos))
        return ['success' => false, 'error' => "Tipo MIME não permitido: $mime."];

    $dir = dirname(__DIR__) . '/uploads/documentos';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $nomeUnico = uniqid('doc_', true) . '.' . $ext;
    $destino   = $dir . '/' . $nomeUnico;

    if (!move_uploaded_file($file['tmp_name'], $destino))
        return ['success' => false, 'error' => 'Falha ao salvar o arquivo no servidor.'];

    return [
        'success'  => true,
        'path'     => $nomeUnico,
        'mime'     => $mime,
        'size'     => $file['size'],
        'original' => $file['name'],
    ];
}

function _documento_excluir($db, $sessao) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');

    $res = $db->query("SELECT nome, arquivo FROM documentos WHERE tenant_id = $tenant_id AND id=$id LIMIT 1");
    if (!$res || $res->num_rows === 0) retornar_json(false, 'Documento não encontrado.');
    $doc = $res->fetch_assoc();

    // Marcar como inativo (soft delete) e remover arquivo físico
    $db->query("UPDATE documentos SET status='expirado' WHERE tenant_id = $tenant_id AND id=$id");

    if (!empty($doc['arquivo'])) {
        $path = dirname(__DIR__) . '/uploads/documentos/' . basename($doc['arquivo']);
        if (file_exists($path)) unlink($path);
    }

    _log($db, $sessao, $id, 'exclusao', "Documento excluído: " . $doc['nome']);
    retornar_json(true, 'Documento excluído com sucesso.');
}

// ============================================================
// DOWNLOAD (autenticado, registra acesso)
// ============================================================
function _download($db, $sessao) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');

    $res = $db->query("SELECT * FROM documentos WHERE tenant_id = $tenant_id AND id=$id AND status='ativo' LIMIT 1");
    if (!$res || $res->num_rows === 0) retornar_json(false, 'Documento não disponível.');
    $doc = $res->fetch_assoc();

    if (empty($doc['arquivo'])) {
        // Sem arquivo físico — retornar link externo
        retornar_json(true, 'OK', ['link_externo' => $doc['link_externo']]);
    }

    $path = dirname(__DIR__) . '/uploads/documentos/' . basename($doc['arquivo']);
    if (!file_exists($path)) retornar_json(false, 'Arquivo não encontrado no servidor.');

    _registrar_acesso($db, $sessao, $id, 'download', 'interno');
    _log($db, $sessao, $id, 'download', "Download: " . $doc['nome']);

    if (ob_get_length()) ob_clean();
    header('Content-Type: ' . ($doc['arquivo_tipo'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . rawurlencode($doc['arquivo_nome_original'] ?: basename($doc['arquivo'])) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-cache');
    readfile($path);
    exit;
}

// ============================================================
// COMPARTILHAMENTOS
// ============================================================
function _compartilhamentos_listar($db) {
    $docId = (int)($_GET['documento_id'] ?? 0);
    $where = $docId ? "WHERE c.documento_id=$docId" : "WHERE 1=1";
    $res = $db->query("SELECT c.*, d.nome AS documento_nome,
                              DATE_FORMAT(c.expira_em,'%d/%m/%Y %H:%i') AS expira_formatado,
                              DATE_FORMAT(c.created_at,'%d/%m/%Y %H:%i') AS criado_em
                       FROM documentos_compartilhamentos c
                       JOIN documentos d ON d.id = c.documento_id
                       $where
                       ORDER BY c.created_at DESC LIMIT 50");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) {
        // Montar URL pública
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $r['url'] = "$proto://$host/doc.php?t=" . $r['token'];
        $rows[] = $r;
    }
    retornar_json(true, 'OK', ['compartilhamentos' => $rows]);
}

function _compartilhamento_gerar($db, $sessao) {
    $docId      = (int)($_POST['documento_id'] ?? 0);
    $desc       = _esc($db, $_POST['descricao'] ?? '');
    $expira     = _esc($db, $_POST['expira_em'] ?? '');
    $limite     = (int)($_POST['limite_acessos'] ?? 0);
    $uid        = (int)($sessao['id'] ?? 0);

    if (!$docId) retornar_json(false, 'documento_id é obrigatório.');

    // Verificar se documento existe e está ativo
    $res = $db->query("SELECT id, nome FROM documentos WHERE tenant_id = $tenant_id AND id=$docId AND status='ativo' LIMIT 1");
    if (!$res || $res->num_rows === 0) retornar_json(false, 'Documento não encontrado ou inativo.');
    $doc = $res->fetch_assoc();

    $token    = bin2hex(random_bytes(24)); // 48 chars
    $expSql   = $expira ? "'$expira'" : 'NULL';
    $limSql   = $limite ? $limite : 'NULL';

    $db->query("INSERT INTO documentos_compartilhamentos
                (documento_id, token, descricao, expira_em, limite_acessos, criado_por)
                VALUES ($docId,'$token','$desc',$expSql,$limSql,$uid)");

    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url   = "$proto://$host/doc.php?t=$token";

    _log($db, $sessao, $docId, 'compartilhamento', "Link criado para: " . $doc['nome']);

    retornar_json(true, 'Link de compartilhamento criado.', [
        'token' => $token,
        'url'   => $url,
    ]);
}

function _compartilhamento_desativar($db) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');
    $db->query("UPDATE documentos_compartilhamentos SET ativo=0 WHERE id=$id");
    retornar_json(true, 'Link desativado com sucesso.');
}

// ============================================================
// ACESSOS
// ============================================================
function _acessos_listar($db) {
    $docId  = (int)($_GET['documento_id'] ?? 0);
    $pag    = max(1, (int)($_GET['pagina'] ?? 1));
    $limit  = 30;
    $offset = ($pag - 1) * $limit;
    $where  = $docId ? "WHERE a.documento_id=$docId" : "WHERE 1=1";

    $rCount = $db->query("SELECT COUNT(*) c FROM documentos_acessos a $where");
    $total  = $rCount ? (int)$rCount->fetch_assoc()['c'] : 0;

    $res = $db->query("SELECT a.*, d.nome AS documento_nome,
                              DATE_FORMAT(a.created_at,'%d/%m/%Y %H:%i') AS data_acesso
                       FROM documentos_acessos a
                       LEFT JOIN documentos d ON d.id = a.documento_id
                       $where
                       ORDER BY a.created_at DESC
                       LIMIT $limit OFFSET $offset");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

    retornar_json(true, 'OK', [
        'acessos'       => $rows,
        'total'         => $total,
        'pagina'        => $pag,
        'total_paginas' => (int)ceil($total / $limit),
    ]);
}

// ============================================================
// LOGS
// ============================================================
function _logs_listar($db) {
    $docId = (int)($_GET['documento_id'] ?? 0);
    $pag   = max(1, (int)($_GET['pagina'] ?? 1));
    $limit = 30;
    $offset= ($pag - 1) * $limit;
    $where = $docId ? "WHERE l.documento_id=$docId" : "WHERE 1=1";

    $rCount = $db->query("SELECT COUNT(*) c FROM documentos_logs l $where");
    $total  = $rCount ? (int)$rCount->fetch_assoc()['c'] : 0;

    $res = $db->query("SELECT l.*, d.nome AS documento_nome, u.nome AS usuario_nome,
                              DATE_FORMAT(l.created_at,'%d/%m/%Y %H:%i') AS data_log
                       FROM documentos_logs l
                       LEFT JOIN documentos d ON d.id = l.documento_id
                       LEFT JOIN usuarios   u ON u.id = l.usuario_id
                       $where
                       ORDER BY l.created_at DESC
                       LIMIT $limit OFFSET $offset");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

    retornar_json(true, 'OK', [
        'logs'          => $rows,
        'total'         => $total,
        'pagina'        => $pag,
        'total_paginas' => (int)ceil($total / $limit),
    ]);
}

// ============================================================
// NOTIFICAÇÃO DE NOVO DOCUMENTO (integra ao EmailSender)
// ============================================================
function _notificar_novo_documento($db, int $docId, string $nomeDoc, int $grupoId, int $criadoPor): void {
    // Somente disparar se o grupo NÃO for "Todos" (grupo 1) — grupos específicos são notificados
    if (!$grupoId || $grupoId === 1) return;

    try {
        require_once __DIR__ . '/EmailSender.php';

        $rGrupo = $db->query("SELECT nome FROM documentos_grupos WHERE tenant_id = $tenant_id AND id=$grupoId LIMIT 1");
        $grupo  = $rGrupo ? ($rGrupo->fetch_assoc()['nome'] ?? 'Todos') : 'Todos';

        $rDep = $db->query("SELECT dep.nome FROM documentos d
                             LEFT JOIN departamentos dep ON dep.id = d.departamento_id
                             WHERE d.id=$docId LIMIT 1");
        $depto = $rDep ? ($rDep->fetch_assoc()['nome'] ?? '') : '';

        $corpo = "
<div style='font-family:Arial,sans-serif;background:#f1f5f9;padding:24px'>
<div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
  <div style='background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;padding:24px;text-align:center'>
    <h2 style='margin:0'><i class='fas fa-folder-open'></i> Novo Documento Disponível</h2>
  </div>
  <div style='padding:28px'>
    <p>Um novo documento foi publicado no sistema:</p>
    <table style='width:100%;border-collapse:collapse;font-size:14px'>
      <tr><td style='padding:8px 12px;color:#64748b;width:140px'>Documento:</td><td style='padding:8px 12px;font-weight:600'>$nomeDoc</td></tr>
      <tr style='background:#f8fafc'><td style='padding:8px 12px;color:#64748b'>Departamento:</td><td style='padding:8px 12px'>$depto</td></tr>
      <tr><td style='padding:8px 12px;color:#64748b'>Grupo de Acesso:</td><td style='padding:8px 12px'>$grupo</td></tr>
    </table>
    <div style='text-align:center;margin-top:20px'>
      <a href='#' style='background:#2563eb;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600'>
        Acessar Documento
      </a>
    </div>
  </div>
  <div style='text-align:center;padding:16px;font-size:12px;color:#94a3b8'>
    Sistema ERP Condomínio — E-mail automático, não responda.
  </div>
</div></div>";

        // Buscar administradores do grupo para notificar
        $rUsuarios = $db->query("SELECT u.email, u.nome
                                 FROM documentos_grupos_usuarios gu
                                 JOIN usuarios u ON u.id = gu.usuario_id
                                 WHERE gu.grupo_id=$grupoId AND u.email != '' LIMIT 50");
        if ($rUsuarios) {
            $sender = new EmailSender($db, false);
            while ($u = $rUsuarios->fetch_assoc()) {
                try {
                    $sender->enviar($u['email'], "Novo documento disponível: $nomeDoc", $corpo, $u['nome']);
                } catch (\Throwable $e) {
                    error_log('[DocumentosGED] Erro ao notificar ' . $u['email'] . ': ' . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[DocumentosGED] Erro na notificação: ' . $e->getMessage());
    }
}

// ============================================================
// TIPOS DE DOCUMENTO
// ============================================================
function _tipos_listar($db) {
    $res  = $db->query("SELECT t.*, COUNT(d.id) AS total_documentos
                        FROM documentos_tipos t
                        LEFT JOIN documentos d ON d.tipo_id = t.id
                        WHERE t.ativo = 1
                        GROUP BY t.id
                        ORDER BY t.nome ASC");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    retornar_json(true, 'OK', ['tipos' => $rows]);
}

function _tipo_salvar($db, $sessao) {
    $id   = (int)($_POST['id'] ?? 0);
    $nome = _esc($db, trim($_POST['nome'] ?? ''));
    $desc = _esc($db, trim($_POST['descricao'] ?? ''));
    $icone= _esc($db, trim($_POST['icone'] ?? 'fas fa-file-alt'));
    $cor  = _esc($db, trim($_POST['cor'] ?? '#2563eb'));
    $uid  = (int)($sessao['id'] ?? 0);
    if (!$nome) retornar_json(false, 'Nome do tipo é obrigatório.');
    if ($id) {
        $db->query("UPDATE documentos_tipos SET nome='$nome', descricao='$desc', icone='$icone', cor='$cor' WHERE id=$id");
        retornar_json(true, 'Tipo atualizado com sucesso.');
    } else {
        $db->query("INSERT INTO documentos_tipos (nome, descricao, icone, cor, criado_por) VALUES ('$nome','$desc','$icone','$cor',$uid)");
        retornar_json(true, 'Tipo cadastrado com sucesso.', ['id' => $db->insert_id]);
    }
}

function _tipo_excluir($db) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) retornar_json(false, 'ID inválido.');
    // Verificar se há documentos vinculados
    $rCheck = $db->query("SELECT COUNT(*) c FROM documentos WHERE tenant_id = $tenant_id AND tipo_id=$id");
    if ($rCheck && (int)$rCheck->fetch_assoc()['c'] > 0) {
        retornar_json(false, 'Não é possível excluir: existem documentos vinculados a este tipo.');
    }
    $db->query("UPDATE documentos_tipos SET ativo=0 WHERE id=$id");
    retornar_json(true, 'Tipo removido com sucesso.');
}

// ============================================================
// UNIDADES (integração com tabela unidades)
// ============================================================
function _unidades_select($db) {
    $res = $db->query("SELECT id, nome, bloco FROM unidades WHERE tenant_id = $tenant_id AND ativo = 1
                       ORDER BY CASE WHEN bloco = 'ADMIN' THEN 9999 ELSE CAST(SUBSTRING_INDEX(nome, ' ', -1) AS UNSIGNED) END ASC, nome ASC");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    retornar_json(true, 'OK', $rows);
}

// ============================================================
// RELATÓRIO GERAL GED
// ============================================================
function _relatorio_geral($db) {
    $periodo = _esc($db, $_GET['periodo'] ?? '30');
    $depId   = (int)($_GET['departamento_id'] ?? 0);
    $tipoId  = (int)($_GET['tipo_id'] ?? 0);

    $whereDoc = "WHERE d.status != 'excluido'";
    if ($depId)  $whereDoc .= " AND d.departamento_id=$depId";
    if ($tipoId) $whereDoc .= " AND d.tipo_id=$tipoId";

    // Documentos por tipo
    $rTipos = $db->query("SELECT COALESCE(t.nome,'Sem Tipo') AS nome, t.cor, COUNT(d.id) AS total
                          FROM documentos d
                          LEFT JOIN documentos_tipos t ON t.id = d.tipo_id
                          $whereDoc
                          GROUP BY d.tipo_id ORDER BY total DESC LIMIT 10");
    $porTipo = [];
    if ($rTipos) while ($r = $rTipos->fetch_assoc()) $porTipo[] = $r;

    // Documentos por departamento
    $rDeps = $db->query("SELECT COALESCE(dep.nome,'Sem Departamento') AS nome, COUNT(d.id) AS total
                         FROM documentos d
                         LEFT JOIN departamentos dep ON dep.id = d.departamento_id
                         $whereDoc
                         GROUP BY d.departamento_id ORDER BY total DESC LIMIT 10");
    $porDep = [];
    if ($rDeps) while ($r = $rDeps->fetch_assoc()) $porDep[] = $r;

    // Downloads nos últimos N dias
    $whereAc = "WHERE a.tipo='download' AND a.created_at >= DATE_SUB(NOW(), INTERVAL $periodo DAY)";
    $rDl = $db->query("SELECT DATE_FORMAT(a.created_at,'%d/%m') AS dia, COUNT(*) AS total
                       FROM documentos_acessos a $whereAc
                       GROUP BY DATE(a.created_at) ORDER BY DATE(a.created_at) ASC LIMIT 30");
    $downloads = [];
    if ($rDl) while ($r = $rDl->fetch_assoc()) $downloads[] = $r;

    // Top documentos mais acessados
    $rTop = $db->query("SELECT d.nome, d.total_downloads, d.total_visualizacoes,
                               COALESCE(dep.nome,'—') AS departamento,
                               COALESCE(t.nome,'—') AS tipo
                        FROM documentos d
                        LEFT JOIN departamentos dep ON dep.id = d.departamento_id
                        LEFT JOIN documentos_tipos t ON t.id = d.tipo_id
                        $whereDoc
                        ORDER BY (d.total_downloads + d.total_visualizacoes) DESC LIMIT 10");
    $topDocs = [];
    if ($rTop) while ($r = $rTop->fetch_assoc()) $topDocs[] = $r;

    // Documentos por status
    $rStatus = $db->query("SELECT status, COUNT(*) AS total FROM documentos $whereDoc GROUP BY status");
    $porStatus = [];
    if ($rStatus) while ($r = $rStatus->fetch_assoc()) $porStatus[] = $r;

    // Documentos por visibilidade
    $rVis = $db->query("SELECT COALESCE(visibilidade,'todos') AS visibilidade, COUNT(*) AS total
                        FROM documentos $whereDoc GROUP BY visibilidade");
    $porVis = [];
    if ($rVis) while ($r = $rVis->fetch_assoc()) $porVis[] = $r;

    retornar_json(true, 'OK', [
        'por_tipo'       => $porTipo,
        'por_departamento' => $porDep,
        'downloads_periodo' => $downloads,
        'top_documentos' => $topDocs,
        'por_status'     => $porStatus,
        'por_visibilidade' => $porVis,
    ]);
}
