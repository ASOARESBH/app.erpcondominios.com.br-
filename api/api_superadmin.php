<?php
/**
 * =====================================================
 * API: SUPER-ADMIN — GERENCIAMENTO MULTI-TENANT
 * =====================================================
 *
 * Painel exclusivo para o Super-Administrador do sistema.
 * Gerencia todos os condomínios (tenants), usuários globais,
 * planos e onboarding de novos clientes.
 *
 * REQUER: permissao = 'super_admin' na sessão
 *
 * Ações disponíveis:
 *
 * DASHBOARD
 *   GET  ?action=dashboard          — KPIs globais do sistema
 *
 * TENANTS (Condomínios)
 *   GET  ?action=tenants            — Lista todos os tenants
 *   GET  ?action=tenant&id=X        — Dados de um tenant
 *   POST ?action=criar_tenant       — Cria novo condomínio
 *   POST ?action=editar_tenant&id=X — Edita dados do condomínio
 *   POST ?action=status_tenant&id=X — Ativa/inativa/suspende
 *
 * USUÁRIOS
 *   GET  ?action=usuarios&tenant=X  — Usuários de um tenant
 *   POST ?action=criar_usuario      — Cria usuário em um tenant
 *   POST ?action=vincular_usuario   — Vincula usuário existente a tenant
 *   POST ?action=desvincular_usuario — Remove vínculo usuário × tenant
 *   POST ?action=resetar_senha      — Reseta senha de qualquer usuário
 *
 * ONBOARDING
 *   POST ?action=onboarding         — Cria tenant + admin em uma única chamada
 *
 * @version 1.0.0 (Multi-Tenant)
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

// ─── Helpers locais ───────────────────────────────────────────────────────
function sa_ok($dados = null, $msg = 'OK') {
    http_response_code(200);
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

// ─── DASHBOARD ────────────────────────────────────────────────────────────
if ($action === 'dashboard') {
    $kpis = [];

    // Total de tenants
    $r = $conexao->query("SELECT COUNT(*) AS total, SUM(status='ativo') AS ativos, SUM(status='inativo') AS inativos, SUM(status='suspenso') AS suspensos FROM tenants");
    $kpis['tenants'] = $r->fetch_assoc();

    // Total de usuários (todos os tenants)
    $r = $conexao->query("SELECT COUNT(*) AS total, SUM(ativo=1) AS ativos FROM usuarios");
    $kpis['usuarios'] = $r->fetch_assoc();

    // Total de moradores (todos os tenants)
    $r = $conexao->query("SELECT COUNT(*) AS total FROM moradores");
    $kpis['moradores'] = $r->fetch_assoc();

    // Tenants por plano
    $r = $conexao->query("SELECT plano, COUNT(*) AS total FROM tenants GROUP BY plano ORDER BY total DESC");
    $kpis['planos'] = $r->fetch_all(MYSQLI_ASSOC);

    // Últimos tenants criados
    $r = $conexao->query("SELECT id, slug, nome_fantasia, plano, status, data_criacao FROM tenants ORDER BY data_criacao DESC LIMIT 5");
    $kpis['recentes'] = $r->fetch_all(MYSQLI_ASSOC);

    // Tenants com mais usuários
    $r = $conexao->query(
        "SELECT t.id, t.slug, t.nome_fantasia, COUNT(ut.usuario_id) AS total_usuarios
         FROM tenants t
         LEFT JOIN usuario_tenant ut ON ut.tenant_id = t.id AND ut.ativo = 1
         GROUP BY t.id ORDER BY total_usuarios DESC LIMIT 5"
    );
    $kpis['top_tenants'] = $r->fetch_all(MYSQLI_ASSOC);

    fechar_conexao($conexao);
    sa_ok($kpis, 'Dashboard carregado');
}

// ─── LISTAR TENANTS ───────────────────────────────────────────────────────
if ($action === 'tenants') {
    $filtro_status = $_GET['status'] ?? '';
    $filtro_plano  = $_GET['plano']  ?? '';
    $busca         = $_GET['busca']  ?? '';

    $where = ['1=1'];
    $tipos = '';
    $vals  = [];

    if ($filtro_status) { $where[] = 't.status = ?'; $tipos .= 's'; $vals[] = $filtro_status; }
    if ($filtro_plano)  { $where[] = 't.plano = ?';  $tipos .= 's'; $vals[] = $filtro_plano; }
    if ($busca) {
        $where[] = '(t.nome_fantasia LIKE ? OR t.razao_social LIKE ? OR t.cnpj LIKE ? OR t.slug LIKE ?)';
        $tipos .= 'ssss';
        $b = "%$busca%";
        $vals = array_merge($vals, [$b, $b, $b, $b]);
    }

    $sql = "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.cnpj, t.plano, t.status,
                   t.logo_url, t.email_principal, t.telefone, t.cidade, t.estado, t.data_criacao,
                   COUNT(DISTINCT ut.usuario_id) AS total_usuarios,
                   COUNT(DISTINCT m.id) AS total_moradores
            FROM tenants t
            LEFT JOIN usuario_tenant ut ON ut.tenant_id = t.id AND ut.ativo = 1
            LEFT JOIN moradores m ON m.tenant_id = t.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY t.id
            ORDER BY t.nome_fantasia ASC";

    $stmt = $conexao->prepare($sql);
    if (!empty($vals)) {
        $stmt->bind_param($tipos, ...$vals);
    }
    $stmt->execute();
    $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    fechar_conexao($conexao);
    sa_ok(['tenants' => $list, 'total' => count($list)]);
}

// ─── OBTER TENANT ─────────────────────────────────────────────────────────
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
         WHERE t.id = ?
         GROUP BY t.id LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) { fechar_conexao($conexao); sa_err('Condomínio não encontrado', 404); }

    // Usuários do tenant
    $stmt2 = $conexao->prepare(
        "SELECT u.id, u.nome, u.email, u.funcao, u.permissao, u.ativo,
                ut.permissao AS permissao_tenant, ut.ativo AS vinculo_ativo
         FROM usuarios u
         INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ?
         ORDER BY u.nome ASC"
    );
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    $usuarios = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    fechar_conexao($conexao);
    sa_ok(['tenant' => $tenant, 'usuarios' => $usuarios]);
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

    if (empty($slug) || empty($razao_social) || empty($cnpj) || empty($email)) {
        sa_err('Campos obrigatórios: slug, razao_social, cnpj, email_principal');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sa_err('E-mail inválido');
    }

    // Verificar slug único
    $chk = $conexao->prepare("SELECT id FROM tenants WHERE slug = ? LIMIT 1");
    $chk->bind_param('s', $slug);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); fechar_conexao($conexao); sa_err("Slug '{$slug}' já está em uso."); }
    $chk->close();

    $stmt = $conexao->prepare(
        "INSERT INTO tenants (slug, razao_social, nome_fantasia, cnpj, email_principal, telefone, cidade, estado, plano, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')"
    );
    $stmt->bind_param('sssssssss', $slug, $razao_social, $nome_fantasia, $cnpj, $email, $telefone, $cidade, $estado, $plano);
    $stmt->execute();
    $novo_id = $conexao->insert_id;
    $stmt->close();
    fechar_conexao($conexao);

    registrar_log('SUPERADMIN_TENANT_CRIADO', "Novo tenant: {$slug} (ID={$novo_id})", $_SESSION['usuario_nome'] ?? '');
    sa_ok(['id' => $novo_id, 'slug' => $slug], 'Condomínio criado com sucesso!');
}

// ─── EDITAR TENANT ────────────────────────────────────────────────────────
if ($action === 'editar_tenant') {
    $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) sa_err('ID obrigatório');

    $campos = [];
    $tipos  = '';
    $vals   = [];

    $map = [
        'razao_social'   => 's',
        'nome_fantasia'  => 's',
        'email_principal'=> 's',
        'telefone'       => 's',
        'cidade'         => 's',
        'estado'         => 's',
        'logo_url'       => 's',
        'modulos_habilitados' => 's',
    ];
    foreach ($map as $campo => $tipo) {
        if (isset($input[$campo]) && $input[$campo] !== '') {
            $campos[] = "`{$campo}` = ?";
            $tipos .= $tipo;
            $vals[] = trim($input[$campo]);
        }
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
    fechar_conexao($conexao);

    registrar_log('SUPERADMIN_TENANT_EDITADO', "Tenant ID={$id} editado", $_SESSION['usuario_nome'] ?? '');
    sa_ok(null, 'Condomínio atualizado com sucesso!');
}

// ─── STATUS DO TENANT ─────────────────────────────────────────────────────
if ($action === 'status_tenant') {
    $id     = (int)($_GET['id'] ?? $input['id'] ?? 0);
    $status = $input['status'] ?? '';
    if (!$id) sa_err('ID obrigatório');
    if (!in_array($status, ['ativo','inativo','suspenso'])) sa_err('Status inválido');

    $stmt = $conexao->prepare("UPDATE tenants SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();
    fechar_conexao($conexao);

    registrar_log('SUPERADMIN_TENANT_STATUS', "Tenant ID={$id} → {$status}", $_SESSION['usuario_nome'] ?? '');
    sa_ok(null, "Status alterado para '{$status}'");
}

// ─── LISTAR USUÁRIOS DE UM TENANT ─────────────────────────────────────────
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

// ─── CRIAR USUÁRIO EM UM TENANT ───────────────────────────────────────────
if ($action === 'criar_usuario') {
    $tenant_id_novo = (int)($input['tenant_id'] ?? 0);
    $nome           = trim($input['nome']       ?? '');
    $email          = strtolower(trim($input['email'] ?? ''));
    $senha          = $input['senha']           ?? '';
    $funcao         = trim($input['funcao']     ?? 'Operador');
    $permissao      = in_array($input['permissao'] ?? '', ['visualizador','operador','gerente','admin']) ? $input['permissao'] : 'operador';

    if (!$tenant_id_novo || empty($nome) || empty($email) || empty($senha)) {
        sa_err('Campos obrigatórios: tenant_id, nome, email, senha');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sa_err('E-mail inválido');
    if (strlen($senha) < 6) sa_err('Senha deve ter pelo menos 6 caracteres');

    // Verificar e-mail único
    $chk = $conexao->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $chk->bind_param('s', $email);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); fechar_conexao($conexao); sa_err('E-mail já cadastrado no sistema'); }
    $chk->close();

    $senha_hash = password_hash($senha, PASSWORD_BCRYPT);
    $stmt = $conexao->prepare(
        "INSERT INTO usuarios (tenant_id, nome, email, senha, funcao, permissao, ativo)
         VALUES (?, ?, ?, ?, ?, ?, 1)"
    );
    $stmt->bind_param('isssss', $tenant_id_novo, $nome, $email, $senha_hash, $funcao, $permissao);
    $stmt->execute();
    $novo_usuario_id = $conexao->insert_id;
    $stmt->close();

    // Criar vínculo usuario_tenant
    $stmt2 = $conexao->prepare(
        "INSERT INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo) VALUES (?, ?, ?, 1)"
    );
    $stmt2->bind_param('iis', $novo_usuario_id, $tenant_id_novo, $permissao);
    $stmt2->execute();
    $stmt2->close();

    fechar_conexao($conexao);
    registrar_log('SUPERADMIN_USUARIO_CRIADO', "Usuário {$email} criado no tenant {$tenant_id_novo}", $_SESSION['usuario_nome'] ?? '');
    sa_ok(['id' => $novo_usuario_id], 'Usuário criado com sucesso!');
}

// ─── VINCULAR USUÁRIO EXISTENTE A TENANT ──────────────────────────────────
if ($action === 'vincular_usuario') {
    $usuario_id_v  = (int)($input['usuario_id'] ?? 0);
    $tenant_id_v   = (int)($input['tenant_id']  ?? 0);
    $permissao_v   = in_array($input['permissao'] ?? '', ['visualizador','operador','gerente','admin']) ? $input['permissao'] : 'operador';

    if (!$usuario_id_v || !$tenant_id_v) sa_err('usuario_id e tenant_id são obrigatórios');

    $stmt = $conexao->prepare(
        "INSERT INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE permissao = VALUES(permissao), ativo = 1"
    );
    $stmt->bind_param('iis', $usuario_id_v, $tenant_id_v, $permissao_v);
    $stmt->execute();
    $stmt->close();
    fechar_conexao($conexao);

    registrar_log('SUPERADMIN_USUARIO_VINCULADO', "Usuário {$usuario_id_v} → tenant {$tenant_id_v}", $_SESSION['usuario_nome'] ?? '');
    sa_ok(null, 'Usuário vinculado com sucesso!');
}

// ─── DESVINCULAR USUÁRIO DE TENANT ────────────────────────────────────────
if ($action === 'desvincular_usuario') {
    $usuario_id_d = (int)($input['usuario_id'] ?? 0);
    $tenant_id_d  = (int)($input['tenant_id']  ?? 0);
    if (!$usuario_id_d || !$tenant_id_d) sa_err('usuario_id e tenant_id são obrigatórios');

    $stmt = $conexao->prepare("UPDATE usuario_tenant SET ativo = 0 WHERE usuario_id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $usuario_id_d, $tenant_id_d);
    $stmt->execute();
    $stmt->close();
    fechar_conexao($conexao);

    registrar_log('SUPERADMIN_USUARIO_DESVINCULADO', "Usuário {$usuario_id_d} removido do tenant {$tenant_id_d}", $_SESSION['usuario_nome'] ?? '');
    sa_ok(null, 'Vínculo removido com sucesso!');
}

// ─── RESETAR SENHA ────────────────────────────────────────────────────────
if ($action === 'resetar_senha') {
    $usuario_id_r = (int)($input['usuario_id'] ?? 0);
    $nova_senha   = $input['nova_senha'] ?? '';
    if (!$usuario_id_r || strlen($nova_senha) < 6) sa_err('usuario_id e nova_senha (mín. 6 chars) são obrigatórios');

    $hash = password_hash($nova_senha, PASSWORD_BCRYPT);
    $stmt = $conexao->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
    $stmt->bind_param('si', $hash, $usuario_id_r);
    $stmt->execute();
    $stmt->close();
    fechar_conexao($conexao);

    registrar_log('SUPERADMIN_SENHA_RESETADA', "Senha do usuário {$usuario_id_r} resetada", $_SESSION['usuario_nome'] ?? '');
    sa_ok(null, 'Senha alterada com sucesso!');
}

// ─── ONBOARDING COMPLETO ──────────────────────────────────────────────────
if ($action === 'onboarding') {
    // Dados do condomínio
    $slug          = strtolower(preg_replace('/[^a-z0-9\-]/', '', $input['slug'] ?? ''));
    $razao_social  = trim($input['razao_social']  ?? '');
    $nome_fantasia = trim($input['nome_fantasia'] ?? $razao_social);
    $cnpj          = preg_replace('/\D/', '', $input['cnpj'] ?? '');
    $email_cond    = trim($input['email_condominio'] ?? '');
    $telefone      = trim($input['telefone'] ?? '');
    $cidade        = trim($input['cidade']   ?? '');
    $estado        = trim($input['estado']   ?? '');
    $plano         = in_array($input['plano'] ?? '', ['basico','profissional','enterprise']) ? $input['plano'] : 'basico';

    // Dados do admin
    $admin_nome    = trim($input['admin_nome']  ?? '');
    $admin_email   = strtolower(trim($input['admin_email'] ?? ''));
    $admin_senha   = $input['admin_senha'] ?? '';

    if (empty($slug) || empty($razao_social) || empty($cnpj) || empty($email_cond) ||
        empty($admin_nome) || empty($admin_email) || empty($admin_senha)) {
        sa_err('Campos obrigatórios: slug, razao_social, cnpj, email_condominio, admin_nome, admin_email, admin_senha');
    }
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) sa_err('E-mail do admin inválido');
    if (strlen($admin_senha) < 6) sa_err('Senha do admin deve ter pelo menos 6 caracteres');

    // Verificar slug único
    $chk = $conexao->prepare("SELECT id FROM tenants WHERE slug = ? LIMIT 1");
    $chk->bind_param('s', $slug);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); fechar_conexao($conexao); sa_err("Slug '{$slug}' já está em uso"); }
    $chk->close();

    // Verificar e-mail do admin único
    $chk2 = $conexao->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $chk2->bind_param('s', $admin_email);
    $chk2->execute();
    $chk2->store_result();
    $admin_existe = $chk2->num_rows > 0;
    $admin_id_existente = null;
    if ($admin_existe) {
        $chk2->bind_result($admin_id_existente);
        $chk2->fetch();
    }
    $chk2->close();

    $conexao->begin_transaction();
    try {
        // 1. Criar tenant
        $stmt = $conexao->prepare(
            "INSERT INTO tenants (slug, razao_social, nome_fantasia, cnpj, email_principal, telefone, cidade, estado, plano, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')"
        );
        $stmt->bind_param('sssssssss', $slug, $razao_social, $nome_fantasia, $cnpj, $email_cond, $telefone, $cidade, $estado, $plano);
        $stmt->execute();
        $novo_tenant_id = $conexao->insert_id;
        $stmt->close();

        // 2. Criar ou reutilizar admin
        if (!$admin_existe) {
            $senha_hash = password_hash($admin_senha, PASSWORD_BCRYPT);
            $funcao_admin = 'Administrador';
            $perm_admin   = 'admin';
            $stmt2 = $conexao->prepare(
                "INSERT INTO usuarios (tenant_id, nome, email, senha, funcao, permissao, ativo)
                 VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            $stmt2->bind_param('isssss', $novo_tenant_id, $admin_nome, $admin_email, $senha_hash, $funcao_admin, $perm_admin);
            $stmt2->execute();
            $novo_admin_id = $conexao->insert_id;
            $stmt2->close();
        } else {
            $novo_admin_id = $admin_id_existente;
        }

        // 3. Criar vínculo admin × tenant
        $perm_admin = 'admin';
        $stmt3 = $conexao->prepare(
            "INSERT INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE permissao = 'admin', ativo = 1"
        );
        $stmt3->bind_param('iis', $novo_admin_id, $novo_tenant_id, $perm_admin);
        $stmt3->execute();
        $stmt3->close();

        $conexao->commit();
        fechar_conexao($conexao);

        registrar_log('SUPERADMIN_ONBOARDING', "Onboarding: {$slug} (tenant={$novo_tenant_id}, admin={$admin_email})", $_SESSION['usuario_nome'] ?? '');
        sa_ok([
            'tenant_id'  => $novo_tenant_id,
            'tenant_slug'=> $slug,
            'admin_id'   => $novo_admin_id,
            'admin_email'=> $admin_email,
            'url_acesso' => "https://{$slug}.erpcondominios.com.br"
        ], 'Condomínio criado com sucesso! Onboarding concluído.');

    } catch (Exception $e) {
        $conexao->rollback();
        fechar_conexao($conexao);
        error_log('[api_superadmin] Onboarding error: ' . $e->getMessage());
        sa_err('Erro ao criar condomínio. Tente novamente.');
    }
}

// ─── Ação não reconhecida ─────────────────────────────────────────────────
fechar_conexao($conexao);
sa_err("Ação '{$action}' não reconhecida", 400);
?>
