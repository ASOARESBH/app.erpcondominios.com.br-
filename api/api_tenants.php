<?php
/**
 * =====================================================
 * API: GERENCIAMENTO DE TENANTS (CONDOMÍNIOS)
 * =====================================================
 *
 * Permite criar, listar, editar e inativar condomínios.
 * Requer permissão 'admin' ou 'super_admin'.
 *
 * Ações disponíveis:
 *   GET  ?action=listar          — Lista todos os tenants
 *   GET  ?action=obter&id=X      — Retorna dados de um tenant
 *   POST ?action=criar           — Cria novo tenant/condomínio
 *   POST ?action=atualizar&id=X  — Atualiza dados do tenant
 *   POST ?action=trocar&id=X     — Troca o tenant ativo da sessão
 *   GET  ?action=meu_tenant      — Retorna dados do tenant da sessão atual
 *
 * @version 1.0.0 (Multi-Tenant)
 * @date 2026-07-22
 */

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// CORS dinâmico
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (
    preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin)
) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Ação pública: meu_tenant (não exige admin) ────────────────────────────
if ($action === 'meu_tenant') {
    $usuario = verificarAutenticacao(true);
    $tenant  = obterDadosTenant();
    echo json_encode([
        'sucesso' => true,
        'dados'   => $tenant
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Ação: trocar tenant ativo na sessão ───────────────────────────────────
if ($action === 'trocar') {
    verificarAutenticacao(true);
    $tenant_id_novo = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$tenant_id_novo) {
        retornarErro('ID do tenant é obrigatório');
    }

    $conexao = conectar_banco();
    $usuario_id = (int)$_SESSION['usuario_id'];

    // Verificar se o usuário tem acesso ao tenant solicitado
    $stmt = $conexao->prepare(
        "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.cnpj,
                t.plano, t.status, t.logo_url, t.email_principal,
                t.modulos_habilitados, ut.permissao
         FROM tenants t
         INNER JOIN usuario_tenant ut ON ut.tenant_id = t.id
         WHERE t.id = ? AND ut.usuario_id = ? AND t.status = 'ativo' AND ut.ativo = 1
         LIMIT 1"
    );
    $stmt->bind_param('ii', $tenant_id_novo, $usuario_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornarErro('Acesso negado a este condomínio.', 403);
    }

    $tenant = $res->fetch_assoc();
    $stmt->close();
    fechar_conexao($conexao);

    // Atualizar permissão do usuário para este tenant
    if (!empty($tenant['permissao'])) {
        $_SESSION['usuario_permissao'] = $tenant['permissao'];
    }

    // Injetar novo tenant na sessão
    injetarTenantNaSessao($tenant);
    session_regenerate_id(false);

    registrar_log('TROCAR_TENANT', "Tenant alterado para: {$tenant['slug']}", $_SESSION['usuario_nome'] ?? '');

    retornarSucesso('Condomínio alterado com sucesso!', [
        'tenant_id'   => $tenant['id'],
        'tenant_nome' => $tenant['nome_fantasia'] ?? $tenant['razao_social'],
        'tenant_slug' => $tenant['slug'],
        'permissao'   => $tenant['permissao'] ?? $_SESSION['usuario_permissao']
    ]);
}

// ── Demais ações exigem permissão admin ───────────────────────────────────
verificarAutenticacao(true, 'admin');
$conexao = conectar_banco();

switch ($action) {

    // ── LISTAR ──────────────────────────────────────────────────────────
    case 'listar':
        $stmt = $conexao->prepare(
            "SELECT id, slug, razao_social, nome_fantasia, cnpj, plano, status,
                    logo_url, email_principal, data_criacao
             FROM tenants
             ORDER BY nome_fantasia ASC"
        );
        $stmt->execute();
        $res  = $stmt->get_result();
        $list = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        fechar_conexao($conexao);
        echo json_encode(['sucesso' => true, 'dados' => $list, 'total' => count($list)], JSON_UNESCAPED_UNICODE);
        break;

    // ── OBTER ────────────────────────────────────────────────────────────
    case 'obter':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { retornarErro('ID obrigatório'); }
        $stmt = $conexao->prepare(
            "SELECT id, slug, razao_social, nome_fantasia, cnpj, plano, status,
                    logo_url, email_principal, telefone, cidade, estado,
                    modulos_habilitados, data_criacao
             FROM tenants WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res  = $stmt->get_result();
        if ($res->num_rows === 0) {
            $stmt->close();
            fechar_conexao($conexao);
            retornarErro('Condomínio não encontrado.', 404);
        }
        $tenant = $res->fetch_assoc();
        $stmt->close();
        fechar_conexao($conexao);
        echo json_encode(['sucesso' => true, 'dados' => $tenant], JSON_UNESCAPED_UNICODE);
        break;

    // ── CRIAR ────────────────────────────────────────────────────────────
    case 'criar':
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $slug          = strtolower(preg_replace('/[^a-z0-9\-]/', '', $input['slug'] ?? ''));
        $razao_social  = trim($input['razao_social']  ?? '');
        $nome_fantasia = trim($input['nome_fantasia'] ?? '');
        $cnpj          = trim($input['cnpj']          ?? '');
        $email         = trim($input['email_principal'] ?? '');
        $plano         = in_array($input['plano'] ?? '', ['basico','profissional','enterprise'])
                         ? $input['plano'] : 'basico';

        if (empty($slug) || empty($razao_social) || empty($cnpj) || empty($email)) {
            fechar_conexao($conexao);
            retornarErro('Campos obrigatórios: slug, razao_social, cnpj, email_principal');
        }

        // Verificar slug único
        $chk = $conexao->prepare("SELECT id FROM tenants WHERE slug = ? LIMIT 1");
        $chk->bind_param('s', $slug);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            fechar_conexao($conexao);
            retornarErro("O slug '{$slug}' já está em uso. Escolha outro.");
        }
        $chk->close();

        $stmt = $conexao->prepare(
            "INSERT INTO tenants (slug, razao_social, nome_fantasia, cnpj, email_principal, plano, status)
             VALUES (?, ?, ?, ?, ?, ?, 'ativo')"
        );
        $stmt->bind_param('ssssss', $slug, $razao_social, $nome_fantasia, $cnpj, $email, $plano);
        $stmt->execute();
        $novo_id = $conexao->insert_id;
        $stmt->close();
        fechar_conexao($conexao);

        registrar_log('TENANT_CRIADO', "Novo condomínio: {$slug} (ID={$novo_id})", $_SESSION['usuario_nome'] ?? '');
        retornarSucesso('Condomínio criado com sucesso!', ['id' => $novo_id, 'slug' => $slug], 201);
        break;

    // ── ATUALIZAR ────────────────────────────────────────────────────────
    case 'atualizar':
        $id    = (int)($_GET['id'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        if (!$id) { retornarErro('ID obrigatório'); }

        $razao_social  = trim($input['razao_social']  ?? '');
        $nome_fantasia = trim($input['nome_fantasia'] ?? '');
        $email         = trim($input['email_principal'] ?? '');
        $plano         = in_array($input['plano'] ?? '', ['basico','profissional','enterprise'])
                         ? $input['plano'] : null;
        $status        = in_array($input['status'] ?? '', ['ativo','inativo','suspenso'])
                         ? $input['status'] : null;

        $campos = [];
        $tipos  = '';
        $vals   = [];
        if ($razao_social)  { $campos[] = 'razao_social = ?';  $tipos .= 's'; $vals[] = $razao_social; }
        if ($nome_fantasia) { $campos[] = 'nome_fantasia = ?'; $tipos .= 's'; $vals[] = $nome_fantasia; }
        if ($email)         { $campos[] = 'email_principal = ?'; $tipos .= 's'; $vals[] = $email; }
        if ($plano)         { $campos[] = 'plano = ?';         $tipos .= 's'; $vals[] = $plano; }
        if ($status)        { $campos[] = 'status = ?';        $tipos .= 's'; $vals[] = $status; }

        if (empty($campos)) {
            fechar_conexao($conexao);
            retornarErro('Nenhum campo para atualizar.');
        }

        $sql  = "UPDATE tenants SET " . implode(', ', $campos) . " WHERE id = ?";
        $tipos .= 'i';
        $vals[] = $id;
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($tipos, ...$vals);
        $stmt->execute();
        $stmt->close();
        fechar_conexao($conexao);

        registrar_log('TENANT_ATUALIZADO', "Tenant ID={$id} atualizado", $_SESSION['usuario_nome'] ?? '');
        retornarSucesso('Condomínio atualizado com sucesso!');
        break;

    default:
        fechar_conexao($conexao);
        retornarErro('Ação inválida');
}
?>
