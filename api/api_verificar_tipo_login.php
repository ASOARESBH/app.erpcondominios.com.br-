<?php
/**
 * =====================================================
 * API: VERIFICAR TIPO DE LOGIN
 * =====================================================
 * 
 * Verifica se o email/senha pertencem a:
 *   - Apenas usuário ERP (tabela usuarios)
 *   - Apenas morador (tabela moradores)
 *   - Ambos (retorna 'ambos' para exibir popup de seleção)
 * 
 * Endpoint: POST /api/api_verificar_tipo_login.php
 * Body: { "email": "...", "senha": "..." }
 * 
 * Resposta:
 *   { "sucesso": true, "tipo": "erp|morador|ambos", "dados": {...} }
 * 
 * @author Sistema ERP Serra da Liberdade
 * @version 1.0
 * @date 2026-03-22
 */

// Configurações de sessão
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200);

session_start();

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

// Função auxiliar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    retornar_json(false, 'Método não permitido');
}

// Receber dados
$input = file_get_contents('php://input');
$dados = json_decode($input, true);

// Suporte a FormData também
if (!$dados) {
    $dados = $_POST;
}

// NOTA: trim() apenas no email (remover espaços acidentais do autocomplete mobile)
// NÃO aplicar trim() na senha — espaços fazem parte da senha
$email = isset($dados['email']) ? strtolower(trim($dados['email'])) : '';
$senha = isset($dados['senha']) ? $dados['senha'] : '';

// Validações básicas
if (empty($email) || empty($senha)) {
    registrar_log('LOGIN_FALHA', "Campo vazio: email='" . (empty($email)?'VAZIO':'ok') . "' senha='" . (empty($senha)?'VAZIA':'ok') . "' ua='" . ($_SERVER['HTTP_USER_AGENT']??'') . "'");
    retornar_json(false, 'E-mail e senha são obrigatórios');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    registrar_log('LOGIN_FALHA', "Email inválido: '{$email}' ua='" . ($_SERVER['HTTP_USER_AGENT']??'') . "'");
    retornar_json(false, 'E-mail inválido');
}

try {
    $conexao = conectar_banco();

    $encontrou_erp     = false;
    $encontrou_morador = false;
    $dados_erp         = null;
    $dados_morador     = null;

    // ─── 1. Verificar tabela USUARIOS (ERP) ───────────────────────────
    $stmt = $conexao->prepare(
        "SELECT id, nome, email, senha, funcao, departamento, permissao, ativo, COALESCE(sessao_inativa,0) AS sessao_inativa
         FROM usuarios WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $usuario = $res->fetch_assoc();
        $stmt->close();

        // Verificar se conta está inativa ANTES de verificar senha
        if ((int)$usuario['ativo'] === 0) {
            registrar_log('LOGIN_BLOQUEADO', "Tentativa de login em conta inativa: {$email} | IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''), $usuario['nome']);
            retornar_json(false, 'Sua conta está desativada. Entre em contato com o administrador do sistema.');
        }

        if ($usuario['ativo'] == 1) {
            $senha_valida = password_verify($senha, $usuario['senha']);
            if ($senha_valida) {
                $encontrou_erp = true;
                $dados_erp = [
                    'id'           => $usuario['id'],
                    'nome'         => $usuario['nome'],
                    'email'        => $usuario['email'],
                    'funcao'       => $usuario['funcao'],
                    'departamento' => $usuario['departamento'],
                    'permissao'    => $usuario['permissao'],
                    'sessao_inativa' => (int)($usuario['sessao_inativa'] ?? 0)
                ];
            }
        }
    } else {
        $stmt->close();
    }

    // ─── 2. Verificar tabela MORADORES (Portal) ────────────────────────
    $stmt = $conexao->prepare(
        "SELECT id, nome, email, senha, cpf, unidade, telefone, celular, ativo
         FROM moradores WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $morador = $res->fetch_assoc();
        $stmt->close();

        if ($morador['ativo'] == 1) {
            $senha_valida_morador = false;

            // Suporte a BCRYPT
            if (strpos($morador['senha'], '$2y$') === 0) {
                $senha_valida_morador = password_verify($senha, $morador['senha']);
            }
            // Suporte a SHA1 (senhas legadas)
            if (!$senha_valida_morador && strlen($morador['senha']) === 40) {
                $senha_valida_morador = (sha1($senha) === $morador['senha']);
                // Atualizar automaticamente para BCRYPT
                if ($senha_valida_morador) {
                    $nova_senha = password_hash($senha, PASSWORD_BCRYPT);
                    $stmt_upd = $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ?");
                    $stmt_upd->bind_param('si', $nova_senha, $morador['id']);
                    $stmt_upd->execute();
                    $stmt_upd->close();
                    registrar_log('SENHA_ATUALIZADA', "Senha SHA1→BCRYPT: {$morador['nome']}", $morador['nome']);
                }
            }
            // Suporte a texto plano (senhas muito antigas)
            if (!$senha_valida_morador) {
                $senha_valida_morador = ($senha === $morador['senha']);
            }

            if ($senha_valida_morador) {
                $encontrou_morador = true;
                $dados_morador = [
                    'id'      => $morador['id'],
                    'nome'    => $morador['nome'],
                    'email'   => $morador['email'],
                    'cpf'     => $morador['cpf'],
                    'unidade' => $morador['unidade']
                ];
            }
        }
    } else {
        $stmt->close();
    }

    fechar_conexao($conexao);

    // ─── 3. Determinar resultado ───────────────────────────────────────
    if (!$encontrou_erp && !$encontrou_morador) {
        // Log detalhado para debug mobile vs desktop
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido';
        $is_mobile = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'SIM' : 'NAO';
        $prefixo_hash_morador = isset($morador['senha']) ? substr($morador['senha'] ?? '', 0, 7) : 'N/A';
        registrar_log('LOGIN_FALHA', "Login invalido: {$email} | mobile={$is_mobile} | erp={$encontrou_erp} | morador={$encontrou_morador} | hash_prefix={$prefixo_hash_morador} | ua={$ua}");
        retornar_json(false, 'E-mail ou senha incorretos!');
    }

    // Ler tipo_escolhido (vem quando usuário escolheu no popup)
    $tipo_escolhido = isset($dados['tipo_escolhido']) ? trim($dados['tipo_escolhido']) : '';

    if ($encontrou_erp && $encontrou_morador && empty($tipo_escolhido)) {
        // Email existe em ambas as tabelas → exibir popup de seleção
        registrar_log('LOGIN_AMBOS', "Email em ambas as tabelas: {$email}");
        echo json_encode([
            'sucesso'  => true,
            'tipo'     => 'ambos',
            'mensagem' => 'Múltiplos perfis encontrados.',
            'dados'    => [
                'nome_erp'     => $dados_erp['nome'],
                'nome_morador' => $dados_morador['nome']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Resolver tipo final quando há escolha ou apenas um perfil
    $tipo_final = '';
    if ($encontrou_erp && $encontrou_morador) {
        // Usuário escolheu via popup
        $tipo_final = ($tipo_escolhido === 'portal') ? 'morador' : 'erp';
    } elseif ($encontrou_erp) {
        $tipo_final = 'erp';
    } else {
        $tipo_final = 'morador';
    }

    if ($tipo_final === 'erp') {
        // ── Autenticar como ERP ──────────────────────────────────────────
        $_SESSION['usuario_id']           = $dados_erp['id'];
        $_SESSION['usuario_nome']         = $dados_erp['nome'];
        $_SESSION['usuario_email']        = $dados_erp['email'];
        $_SESSION['usuario_funcao']       = $dados_erp['funcao'];
        $_SESSION['usuario_departamento'] = $dados_erp['departamento'];
        $_SESSION['usuario_permissao']    = $dados_erp['permissao'];
        $_SESSION['usuario_logado']       = true;
        $_SESSION['login_timestamp']      = time();
        $_SESSION['sessao_inativa']       = (int)($dados_erp['sessao_inativa'] ?? 0);
        $_SESSION['tipo_usuario']         = 'erp';
        session_regenerate_id(true);

        registrar_log('LOGIN_ERP_SUCESSO', "Login ERP: {$email}", $dados_erp['nome']);
        echo json_encode([
            'sucesso'  => true,
            'tipo'     => 'erp',
            'mensagem' => 'Login realizado com sucesso!',
            'dados'    => [
                'nome'      => $dados_erp['nome'],
                'email'     => $dados_erp['email'],
                'permissao' => $dados_erp['permissao'],
                'funcao'    => $dados_erp['funcao'],
                'redirect'  => '/frontend/layout-base.html?page=dashboard'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($tipo_final === 'morador') {
        // ── Autenticar como Morador ──────────────────────────────────────
        $conexao2  = conectar_banco();
        $token     = bin2hex(random_bytes(32));
        $expiracao = date('Y-m-d H:i:s', strtotime('+7 days'));
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Verificar flag de senha temporária (coluna pode não existir em instalações antigas)
        $senha_temporaria_flag = 0;
        try {
            $stmt_tmp = $conexao2->prepare(
                "SELECT senha_temporaria FROM moradores WHERE id = ? LIMIT 1"
            );
            $stmt_tmp->bind_param('i', $dados_morador['id']);
            $stmt_tmp->execute();
            $res_tmp = $stmt_tmp->get_result();
            if ($row_tmp = $res_tmp->fetch_assoc()) {
                $senha_temporaria_flag = (int)($row_tmp['senha_temporaria'] ?? 0);
            }
            $stmt_tmp->close();
        } catch (Exception $e) {
            // Coluna ainda não existe — flag fica 0
        }

        // Verificar se tabela sessoes_portal existe
        $tabela_existe = false;
        $chk = $conexao2->query("SHOW TABLES LIKE 'sessoes_portal'");
        if ($chk && $chk->num_rows > 0) {
            $tabela_existe = true;
        }

        if ($tabela_existe) {
            // Limpar tokens antigos
            $stmt_del = $conexao2->prepare("DELETE FROM sessoes_portal WHERE morador_id = ?");
            $stmt_del->bind_param('i', $dados_morador['id']);
            $stmt_del->execute();
            $stmt_del->close();

            // Inserir novo token
            $stmt_ins = $conexao2->prepare(
                "INSERT INTO sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt_ins->bind_param('issss', $dados_morador['id'], $token, $ip, $ua, $expiracao);
            $stmt_ins->execute();
            $stmt_ins->close();
        }

        // Atualizar último acesso
        $stmt_upd = $conexao2->prepare("UPDATE moradores SET ultimo_acesso = NOW(), data_atualizacao = NOW() WHERE id = ?");
        $stmt_upd->bind_param('i', $dados_morador['id']);
        $stmt_upd->execute();
        $stmt_upd->close();

        fechar_conexao($conexao2);

        // Sessão PHP (compatibilidade)
        $_SESSION['morador_id']      = $dados_morador['id'];
        $_SESSION['morador_nome']    = $dados_morador['nome'];
        $_SESSION['morador_email']   = $dados_morador['email'];
        $_SESSION['morador_cpf']     = $dados_morador['cpf'];
        $_SESSION['morador_unidade'] = $dados_morador['unidade'];
        $_SESSION['morador_logado']  = true;
        $_SESSION['login_timestamp'] = time();
        $_SESSION['tipo_usuario']    = 'morador';

        $log_tipo = $senha_temporaria_flag ? 'SENHA_TEMPORARIA_LOGIN' : 'LOGIN_MORADOR_SUCESSO';
        registrar_log($log_tipo, "Login Portal: {$email}", $dados_morador['nome']);
        echo json_encode([
            'sucesso'  => true,
            'tipo'     => 'morador',
            'mensagem' => 'Login realizado com sucesso!',
            'dados'    => [
                'token'             => $token,
                'morador_id'        => $dados_morador['id'],
                'nome'              => $dados_morador['nome'],
                'email'             => $dados_morador['email'],
                'unidade'           => $dados_morador['unidade'],
                'senha_temporaria'  => $senha_temporaria_flag,
                'redirect'          => '/frontend/portal_morador.html'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

} catch (Exception $e) {
    error_log('[api_verificar_tipo_login] Erro: ' . $e->getMessage());
    retornar_json(false, 'Erro ao processar login. Tente novamente.');
}
?>
