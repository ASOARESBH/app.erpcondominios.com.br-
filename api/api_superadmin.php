<?php
/**
 * =====================================================
 * API: SUPER-ADMIN — GERENCIAMENTO MULTI-TENANT v2.0
 * =====================================================
 *
 * REQUER: permissao = 'super_admin' na sessão
 *
 * AÇÕES DISPONÍVEIS:
 *
 * DASHBOARD
 *   GET  ?action=dashboard          — KPIs globais + gráficos
 *   GET  ?action=dashboard_grafico  — Dados de crescimento mensal
 *
 * TENANTS (Condomínios)
 *   GET  ?action=tenants            — Lista com filtros
 *   GET  ?action=tenant&id=X        — Dados completos de um tenant
 *   POST ?action=criar_tenant       — Cria novo condomínio
 *   POST ?action=editar_tenant      — Edita dados do condomínio
 *   POST ?action=status_tenant      — Ativa/inativa/suspende
 *   POST ?action=salvar_modulos     — Define módulos habilitados
 *   POST ?action=salvar_plano       — Altera plano do condomínio
 *   GET  ?action=verificar_slug     — Verifica disponibilidade do slug
 *
 * USUÁRIOS
 *   GET  ?action=usuarios_globais   — Todos os usuários do sistema
 *   GET  ?action=usuarios&tenant=X  — Usuários de um tenant
 *   POST ?action=criar_usuario      — Cria usuário em um tenant
 *   POST ?action=vincular_usuario   — Vincula usuário existente
 *   POST ?action=desvincular_usuario — Remove vínculo
 *   POST ?action=resetar_senha      — Reseta senha
 *   POST ?action=toggle_usuario     — Ativa/inativa usuário
 *
 * MÓDULOS
 *   GET  ?action=modulos_sistema    — Lista todos os módulos disponíveis
 *   GET  ?action=modulos_tenant&id=X — Módulos habilitados de um tenant
 *
 * AUDITORIA
 *   GET  ?action=logs_auditoria     — Logs de ações do super-admin
 *   GET  ?action=logs_tenant&id=X   — Logs de um tenant específico
 *
 * ONBOARDING
 *   POST ?action=onboarding         — Cria tenant + admin em uma chamada
 *
 * @version 2.0.0 (Fase 5 — Multi-Tenant)
 * @date 2026-07-22
 */

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// CORS dinâmico
$_sa_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (
    preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_sa_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_sa_origin)
) {
    header('Access-Control-Allow-Origin: ' . $_sa_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Autenticação: exige super_admin ──────────────────────────────────────
verificarAutenticacao(true, 'super_admin');

$conexao = conectar_banco();
$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// ─── Helpers ─────────────────────────────────────────────────────────────
function sa_ok($dados = null, $msg = 'OK') {
    $r = ['sucesso' => true, 'mensagem' => $msg];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}
function sa_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['sucesso' => false, 'mensagem' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function sa_log($conexao, $acao, $descricao, $tenant_id = null) {
    $usuario_id   = $_SESSION['usuario_id']   ?? 0;
    $usuario_nome = $_SESSION['usuario_nome'] ?? 'super_admin';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conexao->prepare(
        "INSERT INTO logs_sistema (usuario_id, usuario_nome, acao, descricao, tenant_id, ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE id = id"
    );
    if ($stmt) {
        $stmt->bind_param('isssss', $usuario_id, $usuario_nome, $acao, $descricao, $tenant_id, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// ─── DASHBOARD ────────────────────────────────────────────────────────────
if ($action === 'dashboard') {
    $kpis = [];

    $r = $conexao->query("SELECT COUNT(*) AS total, SUM(status='ativo') AS ativos, SUM(status='inativo') AS inativos, SUM(status='suspenso') AS suspensos FROM tenants");
    $kpis['tenants'] = $r->fetch_assoc();

    $r = $conexao->query("SELECT COUNT(*) AS total, SUM(ativo=1) AS ativos FROM usuarios");
    $kpis['usuarios'] = $r->fetch_assoc();

    $r = $conexao->query("SELECT COUNT(*) AS total FROM moradores");
    $kpis['moradores'] = $r->fetch_assoc();

    $r = $conexao->query("SELECT COUNT(*) AS total FROM unidades");
    $kpis['unidades'] = $r->fetch_assoc();

    // Distribuição por plano
    $r = $conexao->query("SELECT plano, COUNT(*) AS total FROM tenants GROUP BY plano ORDER BY total DESC");
    $kpis['planos'] = $r->fetch_all(MYSQLI_ASSOC);

    // Últimos 5 tenants
    $r = $conexao->query("SELECT id, slug, nome_fantasia, razao_social, plano, status, data_criacao FROM tenants ORDER BY data_criacao DESC LIMIT 5");
    $kpis['recentes'] = $r->fetch_all(MYSQLI_ASSOC);

    // Top 5 tenants por usuários
    $r = $conexao->query(
        "SELECT t.id, t.slug, t.nome_fantasia, COUNT(ut.usuario_id) AS total_usuarios
         FROM tenants t
         LEFT JOIN usuario_tenant ut ON ut.tenant_id = t.id AND ut.ativo = 1
         GROUP BY t.id ORDER BY total_usuarios DESC LIMIT 5"
    );
    $kpis['top_tenants'] = $r->fetch_all(MYSQLI_ASSOC);

    // Tenants com alertas (suspensos ou inativos)
    $r = $conexao->query("SELECT id, slug, nome_fantasia, status FROM tenants WHERE status != 'ativo' ORDER BY data_criacao DESC");
    $kpis['alertas'] = $r->fetch_all(MYSQLI_ASSOC);

    fechar_conexao($conexao);
    sa_ok($kpis, 'Dashboard carregado');
}

// ─── GRÁFICO DE CRESCIMENTO ────────────────────────────────────────────────
if ($action === 'dashboard_grafico') {
    $meses = [];
    // Crescimento de tenants nos últimos 12 meses
    $r = $conexao->query(
        "SELECT DATE_FORMAT(data_criacao, '%Y-%m') AS mes, COUNT(*) AS novos
         FROM tenants
         WHERE data_criacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY mes ORDER BY mes ASC"
    );
    $meses['tenants'] = $r->fetch_all(MYSQLI_ASSOC);

    // Crescimento de usuários nos últimos 12 meses
    $r2 = $conexao->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS mes, COUNT(*) AS novos
         FROM usuario_tenant
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY mes ORDER BY mes ASC"
    );
    $meses['usuarios'] = $r2->fetch_all(MYSQLI_ASSOC);

    fechar_conexao($conexao);
    sa_ok($meses);
}

// ─── LISTAR TENANTS ───────────────────────────────────────────────────────
if ($action === 'tenants') {
    $filtro_status = $_GET['status'] ?? '';
    $filtro_plano  = $_GET['plano']  ?? '';
    $busca         = $_GET['busca']  ?? '';

    $where = ['1=1']; $tipos = ''; $vals = [];
    if ($filtro_status) { $where[] = 't.status = ?'; $tipos .= 's'; $vals[] = $filtro_status; }
    if ($filtro_plano)  { $where[] = 't.plano = ?';  $tipos .= 's'; $vals[] = $filtro_plano; }
    if ($busca) {
        $where[] = '(t.nome_fantasia LIKE ? OR t.razao_social LIKE ? OR t.cnpj LIKE ? OR t.slug LIKE ?)';
        $tipos .= 'ssss'; $b = "%$busca%";
        $vals = array_merge($vals, [$b, $b, $b, $b]);
    }

    $sql = "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.cnpj, t.plano, t.status,
                   t.logo_url, t.email_principal, t.telefone, t.cidade, t.estado, t.data_criacao,
                   t.modulos_habilitados,
                   COUNT(DISTINCT ut.usuario_id) AS total_usuarios,
                   COUNT(DISTINCT m.id) AS total_moradores
            FROM tenants t
            LEFT JOIN usuario_tenant ut ON ut.tenant_id = t.id AND ut.ativo = 1
            LEFT JOIN moradores m ON m.tenant_id = t.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY t.id ORDER BY t.nome_fantasia ASC";

    $stmt = $conexao->prepare($sql);
    if (!empty($vals)) $stmt->bind_param($tipos, ...$vals);
    $stmt->execute();
    $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    fechar_conexao($conexao);
    sa_ok(['tenants' => $list, 'total' => count($list)]);
}

// ─── OBTER TENANT COMPLETO ────────────────────────────────────────────────
if ($action === 'tenant') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) sa_err('ID obrigatório');

    $stmt = $conexao->prepare(
        "SELECT t.*,
                COUNT(DISTINCT ut.usuario_id) AS total_usuarios,
                COUNT(DISTINCT m.id) AS total_moradores,
                COUNT(DISTINCT u.id) AS total_unidades
         FROM tenants t
         LEFT JOIN usuario_tenant ut ON ut.tenant_id = t.id AND ut.ativo = 1
         LEFT JOIN moradores m ON m.tenant_id = t.id
         LEFT JOIN unidades u ON u.tenant_id = t.id
         WHERE t.id = ? GROUP BY t.id LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant) { fechar_conexao($conexao); sa_err('Condomínio não encontrado', 404); }

    // Usuários
    $stmt2 = $conexao->prepare(
        "SELECT u.id, u.nome, u.email, u.funcao, u.permissao, u.ativo,
                ut.permissao AS permissao_tenant, ut.ativo AS vinculo_ativo, ut.created_at AS vinculado_em
         FROM usuarios u
         INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ?
         ORDER BY u.nome ASC"
    );
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    $usuarios = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    // Módulos habilitados (JSON)
    $modulos_habilitados = [];
    if (!empty($tenant['modulos_habilitados'])) {
        $modulos_habilitados = json_decode($tenant['modulos_habilitados'], true) ?? [];
    }

    fechar_conexao($conexao);
    sa_ok(['tenant' => $tenant, 'usuarios' => $usuarios, 'modulos_habilitados' => $modulos_habilitados]);
}

// ─── VERIFICAR SLUG ───────────────────────────────────────────────────────
if ($action === 'verificar_slug') {
    $slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', $_GET['slug'] ?? ''));
    $id_excluir = (int)($_GET['excluir_id'] ?? 0);
    if (empty($slug)) sa_err('Slug inválido');

    $sql = "SELECT id FROM tenants WHERE slug = ?";
    $params = [$slug]; $tipos = 's';
    if ($id_excluir) { $sql .= " AND id != ?"; $params[] = $id_excluir; $tipos .= 'i'; }

    $stmt = $conexao->prepare($sql . " LIMIT 1");
    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $stmt->store_result();
    $disponivel = $stmt->num_rows === 0;
    $stmt->close();
    fechar_conexao($conexao);
    sa_ok(['disponivel' => $disponivel, 'slug' => $slug]);
}

// ─── CRIAR TENANT ─────────────────────────────────────────────────────────
if ($action === 'criar_tenant') {
    $slug          = strtolower(preg_replace('/[^a-z0-9\-]/', '', $input['slug'] ?? ''));
    $razao_social  = trim($input['razao_social']  ?? '');
    $nome_fantasia = trim($input['nome_fantasia'] ?? $razao_social);
    $cnpj          = preg_replace('/\D/', '', $input['cnpj'] ?? '');
    $email         = trim($input['email_principal'] ?? '');
    $telefone      = trim($input['telefone'] ?? '');
    $cidade        = trim($input['cidade']   ?? '');
    $estado        = trim($input['estado']   ?? '');
    $plano         = in_array($input['plano'] ?? '', ['basico','profissional','enterprise']) ? $input['plano'] : 'basico';

    if (empty($slug) || empty($razao_social) || empty($cnpj) || empty($email)) sa_err('Campos obrigatórios: slug, razao_social, cnpj, email_principal');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sa_err('E-mail inválido');

    $chk = $conexao->prepare("SELECT id FROM tenants WHERE slug = ? LIMIT 1");
    $chk->bind_param('s', $slug); $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); fechar_conexao($conexao); sa_err("Slug '{$slug}' já está em uso"); }
    $chk->close();

    $stmt = $conexao->prepare(
        "INSERT INTO tenants (slug, razao_social, nome_fantasia, cnpj, email_principal, telefone, cidade, estado, plano, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')"
    );
    $stmt->bind_param('sssssssss', $slug, $razao_social, $nome_fantasia, $cnpj, $email, $telefone, $cidade, $estado, $plano);
    $stmt->execute();
    $novo_id = $conexao->insert_id;
    $stmt->close();

    sa_log($conexao, 'TENANT_CRIADO', "Novo tenant: {$slug} (ID={$novo_id})", $novo_id);
    fechar_conexao($conexao);
    sa_ok(['id' => $novo_id, 'slug' => $slug], 'Condomínio criado com sucesso!');
}

// ─── EDITAR TENANT ────────────────────────────────────────────────────────
if ($action === 'editar_tenant') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if (!$id) sa_err('ID obrigatório');

    $campos = []; $tipos = ''; $vals = [];
    $map = ['razao_social'=>'s','nome_fantasia'=>'s','email_principal'=>'s','telefone'=>'s',
            'cidade'=>'s','estado'=>'s','logo_url'=>'s','endereco'=>'s'];
    foreach ($map as $campo => $tipo) {
        if (isset($input[$campo])) { $campos[] = "`{$campo}` = ?"; $tipos .= $tipo; $vals[] = trim($input[$campo]); }
    }
    if (isset($input['plano']) && in_array($input['plano'], ['basico','profissional','enterprise'])) {
        $campos[] = '`plano` = ?'; $tipos .= 's'; $vals[] = $input['plano'];
    }
    if (empty($campos)) sa_err('Nenhum campo para atualizar');

    $sql = "UPDATE tenants SET " . implode(', ', $campos) . " WHERE id = ?";
    $tipos .= 'i'; $vals[] = $id;
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($tipos, ...$vals);
    $stmt->execute();
    $stmt->close();

    sa_log($conexao, 'TENANT_EDITADO', "Tenant ID={$id} editado", $id);
    fechar_conexao($conexao);
    sa_ok(null, 'Condomínio atualizado com sucesso!');
}

// ─── STATUS DO TENANT ─────────────────────────────────────────────────────
if ($action === 'status_tenant') {
    $id     = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $status = $input['status'] ?? '';
    if (!$id) sa_err('ID obrigatório');
    if (!in_array($status, ['ativo','inativo','suspenso'])) sa_err('Status inválido');

    $stmt = $conexao->prepare("UPDATE tenants SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();

    sa_log($conexao, 'TENANT_STATUS', "Tenant ID={$id} → {$status}", $id);
    fechar_conexao($conexao);
    sa_ok(null, "Status alterado para '{$status}'");
}

// ─── SALVAR MÓDULOS DO TENANT ─────────────────────────────────────────────
if ($action === 'salvar_modulos') {
    $id      = (int)($input['id'] ?? 0);
    $modulos = $input['modulos'] ?? [];
    if (!$id) sa_err('ID do tenant obrigatório');
    if (!is_array($modulos)) sa_err('Módulos deve ser um array');

    // Sanitizar: apenas strings alfanuméricas com underscore
    $modulos = array_values(array_filter($modulos, fn($m) => preg_match('/^[a-z0-9_]+$/', $m)));
    $json    = json_encode($modulos);

    $stmt = $conexao->prepare("UPDATE tenants SET modulos_habilitados = ? WHERE id = ?");
    $stmt->bind_param('si', $json, $id);
    $stmt->execute();
    $stmt->close();

    sa_log($conexao, 'TENANT_MODULOS', "Tenant ID={$id}: " . count($modulos) . " módulos configurados", $id);
    fechar_conexao($conexao);
    sa_ok(['modulos' => $modulos, 'total' => count($modulos)], 'Módulos atualizados com sucesso!');
}

// ─── SALVAR PLANO DO TENANT ───────────────────────────────────────────────
if ($action === 'salvar_plano') {
    $id    = (int)($input['id'] ?? 0);
    $plano = $input['plano'] ?? '';
    if (!$id) sa_err('ID obrigatório');
    if (!in_array($plano, ['basico','profissional','enterprise'])) sa_err('Plano inválido');

    $stmt = $conexao->prepare("UPDATE tenants SET plano = ? WHERE id = ?");
    $stmt->bind_param('si', $plano, $id);
    $stmt->execute();
    $stmt->close();

    sa_log($conexao, 'TENANT_PLANO', "Tenant ID={$id} → plano {$plano}", $id);
    fechar_conexao($conexao);
    sa_ok(null, "Plano alterado para '{$plano}'");
}

// ─── MÓDULOS DO SISTEMA ───────────────────────────────────────────────────
if ($action === 'modulos_sistema') {
    $r = $conexao->query(
        "SELECT id, chave, nome, grupo, icone, descricao, permissao_minima, ativo, ordem
         FROM modulos_sistema WHERE ativo = 1 ORDER BY grupo, ordem ASC"
    );
    $modulos = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];

    // Agrupar por grupo
    $agrupados = [];
    foreach ($modulos as $m) {
        $agrupados[$m['grupo']][] = $m;
    }

    fechar_conexao($conexao);
    sa_ok(['modulos' => $modulos, 'agrupados' => $agrupados, 'total' => count($modulos)]);
}

// ─── MÓDULOS HABILITADOS DE UM TENANT ────────────────────────────────────
if ($action === 'modulos_tenant') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) sa_err('ID obrigatório');

    $stmt = $conexao->prepare("SELECT modulos_habilitados FROM tenants WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    fechar_conexao($conexao);

    $modulos = [];
    if ($row && !empty($row['modulos_habilitados'])) {
        $modulos = json_decode($row['modulos_habilitados'], true) ?? [];
    }
    sa_ok(['modulos' => $modulos, 'total' => count($modulos)]);
}

// ─── USUÁRIOS GLOBAIS ─────────────────────────────────────────────────────
if ($action === 'usuarios_globais') {
    $busca = $_GET['busca'] ?? '';
    $where = '1=1'; $vals = []; $tipos = '';
    if ($busca) {
        $where = '(u.nome LIKE ? OR u.email LIKE ?)';
        $b = "%$busca%"; $vals = [$b, $b]; $tipos = 'ss';
    }

    $sql = "SELECT u.id, u.nome, u.email, u.funcao, u.permissao, u.ativo,
                   COUNT(DISTINCT ut.tenant_id) AS total_tenants,
                   GROUP_CONCAT(DISTINCT t.nome_fantasia ORDER BY t.nome_fantasia SEPARATOR ', ') AS condominios
            FROM usuarios u
            LEFT JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.ativo = 1
            LEFT JOIN tenants t ON t.id = ut.tenant_id
            WHERE {$where}
            GROUP BY u.id ORDER BY u.nome ASC LIMIT 200";

    $stmt = $conexao->prepare($sql);
    if (!empty($vals)) $stmt->bind_param($tipos, ...$vals);
    $stmt->execute();
    $usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    fechar_conexao($conexao);
    sa_ok(['usuarios' => $usuarios, 'total' => count($usuarios)]);
}

// ─── USUÁRIOS DE UM TENANT ────────────────────────────────────────────────
if ($action === 'usuarios') {
    $tenant_id_filtro = (int)($_GET['tenant'] ?? 0);
    if (!$tenant_id_filtro) sa_err('Parâmetro tenant obrigatório');

    $stmt = $conexao->prepare(
        "SELECT u.id, u.nome, u.email, u.funcao, u.departamento, u.permissao, u.ativo,
                ut.permissao AS permissao_tenant, ut.ativo AS vinculo_ativo, ut.created_at AS vinculado_em
         FROM usuarios u
         INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ?
         ORDER BY u.nome ASC"
    );
    $stmt->bind_param('i', $tenant_id_filtro);
    $stmt->execute();
    $usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    fechar_conexao($conexao);
    sa_ok(['usuarios' => $usuarios, 'total' => count($usuarios)]);
}

// ─── CRIAR USUÁRIO ────────────────────────────────────────────────────────
if ($action === 'criar_usuario') {
    $tenant_id_novo = (int)($input['tenant_id'] ?? 0);
    $nome      = trim($input['nome']  ?? '');
    $email     = strtolower(trim($input['email'] ?? ''));
    $senha     = $input['senha']      ?? '';
    $funcao    = trim($input['funcao']    ?? 'Operador');
    $permissao = in_array($input['permissao'] ?? '', ['visualizador','operador','gerente','admin']) ? $input['permissao'] : 'operador';

    if (!$tenant_id_novo || empty($nome) || empty($email) || empty($senha)) sa_err('Campos obrigatórios: tenant_id, nome, email, senha');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sa_err('E-mail inválido');
    if (strlen($senha) < 6) sa_err('Senha deve ter pelo menos 6 caracteres');

    $chk = $conexao->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $chk->bind_param('s', $email); $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); fechar_conexao($conexao); sa_err('E-mail já cadastrado'); }
    $chk->close();

    $hash = password_hash($senha, PASSWORD_BCRYPT);
    $stmt = $conexao->prepare(
        "INSERT INTO usuarios (tenant_id, nome, email, senha, funcao, permissao, ativo) VALUES (?, ?, ?, ?, ?, ?, 1)"
    );
    $stmt->bind_param('isssss', $tenant_id_novo, $nome, $email, $hash, $funcao, $permissao);
    $stmt->execute();
    $novo_id = $conexao->insert_id;
    $stmt->close();

    $stmt2 = $conexao->prepare("INSERT INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo) VALUES (?, ?, ?, 1)");
    $stmt2->bind_param('iis', $novo_id, $tenant_id_novo, $permissao);
    $stmt2->execute();
    $stmt2->close();

    sa_log($conexao, 'USUARIO_CRIADO', "Usuário {$email} criado no tenant {$tenant_id_novo}", $tenant_id_novo);
    fechar_conexao($conexao);
    sa_ok(['id' => $novo_id], 'Usuário criado com sucesso!');
}

// ─── VINCULAR USUÁRIO ─────────────────────────────────────────────────────
if ($action === 'vincular_usuario') {
    $uid = (int)($input['usuario_id'] ?? 0);
    $tid = (int)($input['tenant_id']  ?? 0);
    $perm = in_array($input['permissao'] ?? '', ['visualizador','operador','gerente','admin']) ? $input['permissao'] : 'operador';
    if (!$uid || !$tid) sa_err('usuario_id e tenant_id são obrigatórios');

    $stmt = $conexao->prepare(
        "INSERT INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo) VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE permissao = VALUES(permissao), ativo = 1"
    );
    $stmt->bind_param('iis', $uid, $tid, $perm);
    $stmt->execute();
    $stmt->close();

    sa_log($conexao, 'USUARIO_VINCULADO', "Usuário {$uid} → tenant {$tid}", $tid);
    fechar_conexao($conexao);
    sa_ok(null, 'Usuário vinculado com sucesso!');
}

// ─── DESVINCULAR USUÁRIO ──────────────────────────────────────────────────
if ($action === 'desvincular_usuario') {
    $uid = (int)($input['usuario_id'] ?? 0);
    $tid = (int)($input['tenant_id']  ?? 0);
    if (!$uid || !$tid) sa_err('usuario_id e tenant_id são obrigatórios');

    $stmt = $conexao->prepare("UPDATE usuario_tenant SET ativo = 0 WHERE usuario_id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $uid, $tid);
    $stmt->execute();
    $stmt->close();

    sa_log($conexao, 'USUARIO_DESVINCULADO', "Usuário {$uid} removido do tenant {$tid}", $tid);
    fechar_conexao($conexao);
    sa_ok(null, 'Vínculo removido com sucesso!');
}

// ─── TOGGLE USUÁRIO (ativar/inativar) ─────────────────────────────────────
if ($action === 'toggle_usuario') {
    $uid  = (int)($input['usuario_id'] ?? 0);
    $ativo = (int)($input['ativo'] ?? 0);
    if (!$uid) sa_err('usuario_id obrigatório');

    $stmt = $conexao->prepare("UPDATE usuarios SET ativo = ? WHERE id = ?");
    $stmt->bind_param('ii', $ativo, $uid);
    $stmt->execute();
    $stmt->close();

    $label = $ativo ? 'ativado' : 'inativado';
    sa_log($conexao, 'USUARIO_TOGGLE', "Usuário {$uid} {$label}");
    fechar_conexao($conexao);
    sa_ok(null, "Usuário {$label} com sucesso!");
}

// ─── RESETAR SENHA ────────────────────────────────────────────────────────
if ($action === 'resetar_senha') {
    $uid   = (int)($input['usuario_id'] ?? 0);
    $senha = $input['nova_senha'] ?? '';
    if (!$uid || strlen($senha) < 6) sa_err('usuario_id e nova_senha (mín. 6 chars) são obrigatórios');

    $hash = password_hash($senha, PASSWORD_BCRYPT);
    $stmt = $conexao->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
    $stmt->bind_param('si', $hash, $uid);
    $stmt->execute();
    $stmt->close();

    sa_log($conexao, 'SENHA_RESETADA', "Senha do usuário {$uid} resetada");
    fechar_conexao($conexao);
    sa_ok(null, 'Senha alterada com sucesso!');
}

// ─── LOGS DE AUDITORIA ────────────────────────────────────────────────────
if ($action === 'logs_auditoria') {
    $limite = min((int)($_GET['limite'] ?? 50), 200);
    $r = $conexao->query(
        "SELECT id, usuario_nome, acao, descricao, tenant_id, ip, created_at
         FROM logs_sistema
         WHERE acao LIKE 'TENANT_%' OR acao LIKE 'USUARIO_%' OR acao LIKE 'SENHA_%' OR acao LIKE 'SUPERADMIN_%'
         ORDER BY created_at DESC LIMIT {$limite}"
    );
    $logs = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    fechar_conexao($conexao);
    sa_ok(['logs' => $logs, 'total' => count($logs)]);
}

// ─── LOGS DE UM TENANT ────────────────────────────────────────────────────
if ($action === 'logs_tenant') {
    $id     = (int)($_GET['id'] ?? 0);
    $limite = min((int)($_GET['limite'] ?? 30), 100);
    if (!$id) sa_err('ID obrigatório');

    $stmt = $conexao->prepare(
        "SELECT id, usuario_nome, acao, descricao, ip, created_at
         FROM logs_sistema WHERE tenant_id = ?
         ORDER BY created_at DESC LIMIT {$limite}"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    fechar_conexao($conexao);
    sa_ok(['logs' => $logs, 'total' => count($logs)]);
}

// ─── ONBOARDING COMPLETO ──────────────────────────────────────────────────
if ($action === 'onboarding') {
    $slug          = strtolower(preg_replace('/[^a-z0-9\-]/', '', $input['slug'] ?? ''));
    $razao_social  = trim($input['razao_social']  ?? '');
    $nome_fantasia = trim($input['nome_fantasia'] ?? $razao_social);
    $cnpj          = preg_replace('/\D/', '', $input['cnpj'] ?? '');
    $email_cond    = trim($input['email_condominio'] ?? '');
    $telefone      = trim($input['telefone'] ?? '');
    $cidade        = trim($input['cidade']   ?? '');
    $estado        = trim($input['estado']   ?? '');
    $plano         = in_array($input['plano'] ?? '', ['basico','profissional','enterprise']) ? $input['plano'] : 'basico';
    $admin_nome    = trim($input['admin_nome']  ?? '');
    $admin_email   = strtolower(trim($input['admin_email'] ?? ''));
    $admin_senha   = $input['admin_senha'] ?? '';
    $modulos       = $input['modulos'] ?? null;

    if (empty($slug) || empty($razao_social) || empty($cnpj) || empty($email_cond) ||
        empty($admin_nome) || empty($admin_email) || empty($admin_senha)) {
        sa_err('Campos obrigatórios: slug, razao_social, cnpj, email_condominio, admin_nome, admin_email, admin_senha');
    }
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) sa_err('E-mail do admin inválido');
    if (strlen($admin_senha) < 6) sa_err('Senha do admin deve ter pelo menos 6 caracteres');

    $chk = $conexao->prepare("SELECT id FROM tenants WHERE slug = ? LIMIT 1");
    $chk->bind_param('s', $slug); $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); fechar_conexao($conexao); sa_err("Slug '{$slug}' já está em uso"); }
    $chk->close();

    $chk2 = $conexao->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $chk2->bind_param('s', $admin_email); $chk2->execute();
    $admin_row = $chk2->get_result()->fetch_assoc();
    $chk2->close();

    $conexao->begin_transaction();
    try {
        // Módulos padrão por plano
        if ($modulos === null) {
            $modulos_padrao = [
                'basico'       => ['dashboard','moradores','veiculos','visitantes','registro','acesso'],
                'profissional' => ['dashboard','moradores','veiculos','visitantes','registro','acesso','relatorios','financeiro','contas_pagar','contas_receber','manutencao','hidrometro','leitura','estoque','contratos','protocolos','notificacoes','documentos','rh','configuracao'],
                'enterprise'   => null // null = todos os módulos
            ];
            $modulos = $modulos_padrao[$plano] ?? $modulos_padrao['basico'];
        }
        $modulos_json = $modulos ? json_encode($modulos) : null;

        $stmt = $conexao->prepare(
            "INSERT INTO tenants (slug, razao_social, nome_fantasia, cnpj, email_principal, telefone, cidade, estado, plano, status, modulos_habilitados)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo', ?)"
        );
        $stmt->bind_param('ssssssssss', $slug, $razao_social, $nome_fantasia, $cnpj, $email_cond, $telefone, $cidade, $estado, $plano, $modulos_json);
        $stmt->execute();
        $novo_tenant_id = $conexao->insert_id;
        $stmt->close();

        if ($admin_row) {
            $novo_admin_id = (int)$admin_row['id'];
        } else {
            $hash = password_hash($admin_senha, PASSWORD_BCRYPT);
            $funcao_a = 'Administrador'; $perm_a = 'admin';
            $stmt2 = $conexao->prepare(
                "INSERT INTO usuarios (tenant_id, nome, email, senha, funcao, permissao, ativo) VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            $stmt2->bind_param('isssss', $novo_tenant_id, $admin_nome, $admin_email, $hash, $funcao_a, $perm_a);
            $stmt2->execute();
            $novo_admin_id = $conexao->insert_id;
            $stmt2->close();
        }

        $perm_admin = 'admin';
        $stmt3 = $conexao->prepare(
            "INSERT INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE permissao = 'admin', ativo = 1"
        );
        $stmt3->bind_param('iis', $novo_admin_id, $novo_tenant_id, $perm_admin);
        $stmt3->execute();
        $stmt3->close();

        $conexao->commit();
        sa_log($conexao, 'ONBOARDING', "Onboarding: {$slug} (tenant={$novo_tenant_id}, admin={$admin_email})", $novo_tenant_id);
        fechar_conexao($conexao);

        sa_ok([
            'tenant_id'   => $novo_tenant_id,
            'tenant_slug' => $slug,
            'admin_id'    => $novo_admin_id,
            'admin_email' => $admin_email,
            'plano'       => $plano,
            'modulos'     => $modulos ? count($modulos) : 'todos',
            'url_acesso'  => "https://{$slug}.erpcondominios.com.br"
        ], 'Condomínio criado com sucesso! Onboarding concluído.');

    } catch (Exception $e) {
        $conexao->rollback();
        fechar_conexao($conexao);
        error_log('[api_superadmin] Onboarding error: ' . $e->getMessage());
        sa_err('Erro ao criar condomínio. Tente novamente.');
    }
}

// ─── ENTRAR NO TENANT (super_admin navega para uma empresa) ──────────────
if ($action === 'entrar_tenant') {
    $tid = (int)($input['tenant_id'] ?? 0);
    if (!$tid) sa_err('tenant_id obrigatório');

    $stmt = $conexao->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $tid);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant) { fechar_conexao($conexao); sa_err('Condomínio não encontrado', 404); }

    // Salvar tenant original para poder voltar
    if (!isset($_SESSION['superadmin_tenant_original'])) {
        $_SESSION['superadmin_tenant_original']      = $_SESSION['tenant_id']   ?? 1;
        $_SESSION['superadmin_tenant_original_slug'] = $_SESSION['tenant_slug'] ?? '';
        $_SESSION['superadmin_tenant_original_nome'] = $_SESSION['tenant_nome'] ?? '';
    }

    // Injetar contexto do tenant na sessão
    injetarTenantNaSessao($tenant);

    sa_log($conexao, 'SUPERADMIN_ENTRAR_TENANT', "Super admin entrou no tenant: {$tenant['slug']} (id={$tid})", $tid);
    fechar_conexao($conexao);

    sa_ok([
        'tenant' => [
            'id'    => $tenant['id'],
            'slug'  => $tenant['slug'],
            'nome'  => $tenant['nome_fantasia'] ?? $tenant['razao_social'],
            'plano' => $tenant['plano'],
            'logo'  => $tenant['logo_url'] ?? null,
        ],
        'redirect' => '/frontend/layout-base.html?page=dashboard'
    ], "Navegando como: " . ($tenant['nome_fantasia'] ?? $tenant['razao_social']));
}

// ─── SAIR DO TENANT (super_admin retorna ao painel principal) ─────────────
if ($action === 'sair_tenant') {
    $tid_original = (int)($_SESSION['superadmin_tenant_original'] ?? 1);

    $stmt = $conexao->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $tid_original);
    $stmt->execute();
    $tenant_original = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($tenant_original) {
        injetarTenantNaSessao($tenant_original);
    }

    unset($_SESSION['superadmin_tenant_original']);
    unset($_SESSION['superadmin_tenant_original_slug']);
    unset($_SESSION['superadmin_tenant_original_nome']);

    sa_log($conexao, 'SUPERADMIN_SAIR_TENANT', "Super admin retornou ao tenant original: id={$tid_original}");
    fechar_conexao($conexao);

    sa_ok(['redirect' => '/frontend/layout-base.html?page=superadmin'], 'Retornado ao painel principal.');
}

fechar_conexao($conexao);
sa_err("Ação '{$action}' não reconhecida", 400);
?>
