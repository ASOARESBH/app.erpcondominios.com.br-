<?php
/**
 * Recuperação de senha pública e segura.
 *
 * Suporta usuários internos do ERP e moradores, sem expor se uma conta existe.
 * O token é aleatório, armazenado somente em hash e expira em uma hora.
 */
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/EmailSender.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const RECUPERACAO_MENSAGEM_GENERICA = 'Se os dados informados estiverem cadastrados, você receberá instruções por e-mail em breve.';
const RECUPERACAO_DURACAO_MINUTOS = 60;
const RECUPERACAO_LIMITE_HORA = 3;

function recuperacao_json(bool $sucesso, string $mensagem, array $dados = []): void
{
    echo json_encode(array_merge(['sucesso' => $sucesso, 'mensagem' => $mensagem], $dados), JSON_UNESCAPED_UNICODE);
    exit;
}

function recuperacao_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Garante a estrutura usada pelo fluxo v2 antes de consultar ou gravar tokens.
 * A migração continua sendo recomendada no deploy; esta proteção evita que uma
 * instalação legada falhe silenciosamente quando a migração foi esquecida.
 */
function recuperacao_garantir_schema(mysqli $conexao): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `recuperacao_senha_tokens_v2` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NULL DEFAULT NULL,
    `tipo_conta` ENUM('usuario','morador') NOT NULL,
    `conta_id` INT(11) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `ip_solicitacao` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(512) NULL DEFAULT NULL,
    `solicitado_em` DATETIME NOT NULL,
    `expira_em` DATETIME NOT NULL,
    `usado_em` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_recuperacao_token_hash` (`token_hash`),
    KEY `idx_recuperacao_conta` (`tipo_conta`, `conta_id`, `usado_em`),
    KEY `idx_recuperacao_ip_data` (`ip_solicitacao`, `solicitado_em`),
    KEY `idx_recuperacao_expira` (`expira_em`),
    KEY `idx_recuperacao_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    if (!mysqli_query($conexao, $sql)) {
        throw new RuntimeException('Não foi possível preparar o armazenamento de recuperação.');
    }
}

function recuperacao_entrada(): array
{
    $json = json_decode((string)file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

function recuperacao_registrar(string $tipo, string $descricao, ?string $usuario = null): void
{
    if (function_exists('registrar_log')) {
        registrar_log($tipo, $descricao, $usuario);
    } else {
        error_log('[recuperacao_senha] ' . $tipo . ': ' . $descricao);
    }
}

function recuperacao_contas(mysqli $conexao, string $identificador): array
{
    $contas = [];
    $identificador = trim($identificador);
    $cpf = preg_replace('/\D/', '', $identificador);

    if (filter_var(strtolower($identificador), FILTER_VALIDATE_EMAIL)) {
        $email = strtolower($identificador);

        $stmt = $conexao->prepare('SELECT id, nome, email FROM usuarios WHERE email = ? AND ativo = 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $contas[] = [
                'tipo' => 'usuario',
                'id' => (int)$row['id'],
                'tenant_id' => null,
                'nome' => (string)$row['nome'],
                'email' => (string)$row['email'],
            ];
        }
        $stmt->close();

        $stmt = $conexao->prepare('SELECT id, tenant_id, nome, email FROM moradores WHERE email = ? AND ativo = 1');
        $stmt->bind_param('s', $email);
    } elseif (strlen($cpf) === 11) {
        $stmt = $conexao->prepare('SELECT id, tenant_id, nome, email FROM moradores WHERE cpf = ? AND ativo = 1');
        $stmt->bind_param('s', $cpf);
    } else {
        return [];
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $contas[] = [
                'tipo' => 'morador',
                'id' => (int)$row['id'],
                'tenant_id' => isset($row['tenant_id']) ? (int)$row['tenant_id'] : null,
                'nome' => (string)$row['nome'],
                'email' => (string)$row['email'],
            ];
        }
    }
    $stmt->close();

    return $contas;
}

function recuperacao_excedeu_limite(mysqli $conexao, array $conta, string $ip): bool
{
    $inicio = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $conexao->prepare(
        'SELECT COUNT(*) AS total
         FROM recuperacao_senha_tokens_v2
         WHERE solicitado_em >= ?
           AND (ip_solicitacao = ? OR (tipo_conta = ? AND conta_id = ?))'
    );
    $stmt->bind_param('sssi', $inicio, $ip, $conta['tipo'], $conta['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) >= RECUPERACAO_LIMITE_HORA;
}

function recuperacao_gerar_token(mysqli $conexao, array $conta, string $ip): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $agora = date('Y-m-d H:i:s');
    $expira = date('Y-m-d H:i:s', time() + (RECUPERACAO_DURACAO_MINUTOS * 60));
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

    $stmt = $conexao->prepare(
        'UPDATE recuperacao_senha_tokens_v2
         SET usado_em = NOW()
         WHERE tipo_conta = ? AND conta_id = ? AND usado_em IS NULL'
    );
    $stmt->bind_param('si', $conta['tipo'], $conta['id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexao->prepare(
        'INSERT INTO recuperacao_senha_tokens_v2
         (tenant_id, tipo_conta, conta_id, email, token_hash, ip_solicitacao, user_agent, solicitado_em, expira_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $tenantId = $conta['tenant_id'];
    $stmt->bind_param(
        'isissssss',
        $tenantId,
        $conta['tipo'],
        $conta['id'],
        $conta['email'],
        $hash,
        $ip,
        $ua,
        $agora,
        $expira
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Não foi possível registrar o token de recuperação.');
    }
    $stmt->close();

    return $token;
}

function recuperacao_link(string $token): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'app.erpcondominios.com.br';
    if (!preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
        $host = 'app.erpcondominios.com.br';
    }
    return 'https://' . $host . '/frontend/redefinir_senha.html?token=' . rawurlencode($token);
}

function recuperacao_email_html(array $conta, string $link): string
{
    $nome = htmlspecialchars($conta['nome'], ENT_QUOTES, 'UTF-8');
    $linkEscapado = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="pt-BR"><body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,sans-serif;color:#1e293b">'
        . '<div style="max-width:580px;margin:auto;background:#fff;border-radius:12px;overflow:hidden">'
        . '<div style="background:#2563eb;color:#fff;padding:26px;text-align:center"><h1 style="font-size:20px;margin:0">Recuperação de senha</h1></div>'
        . '<div style="padding:28px"><p>Olá, <strong>' . $nome . '</strong>.</p>'
        . '<p>Recebemos uma solicitação para redefinir a senha da sua conta no ERP Condomínio.</p>'
        . '<p style="text-align:center;margin:28px 0"><a href="' . $linkEscapado . '" style="display:inline-block;padding:13px 24px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold">Redefinir senha</a></p>'
        . '<p style="font-size:13px;color:#64748b">Este link expira em ' . RECUPERACAO_DURACAO_MINUTOS . ' minutos e só pode ser usado uma vez. Se você não fez a solicitação, ignore esta mensagem.</p>'
        . '</div></div></body></html>';
}

function recuperacao_solicitar(mysqli $conexao): void
{
    $dados = recuperacao_entrada();
    $identificador = trim((string)($dados['cpf_email'] ?? $dados['email'] ?? $dados['cpf'] ?? ''));
    $ip = recuperacao_ip();

    if ($identificador === '') {
        recuperacao_json(true, RECUPERACAO_MENSAGEM_GENERICA);
    }

    recuperacao_garantir_schema($conexao);
    $contas = recuperacao_contas($conexao, $identificador);
    if (!$contas) {
        recuperacao_registrar('SENHA_RECUPERACAO_SOLICITADA', 'Solicitação sem conta elegível; IP=' . $ip);
        recuperacao_json(true, RECUPERACAO_MENSAGEM_GENERICA);
    }

    $sender = null;
    foreach ($contas as $conta) {
        if (recuperacao_excedeu_limite($conexao, $conta, $ip)) {
            recuperacao_registrar('SENHA_RECUPERACAO_LIMITE', 'Limite atingido; tipo=' . $conta['tipo'] . '; conta=' . $conta['id'] . '; IP=' . $ip);
            continue;
        }

        try {
            $token = recuperacao_gerar_token($conexao, $conta, $ip);
            if ($sender === null) {
                $sender = new EmailSender($conexao);
            }
            $sender->enviar(
                $conta['email'],
                'Redefinição de senha — ERP Condomínio',
                recuperacao_email_html($conta, recuperacao_link($token)),
                $conta['nome'],
                [],
                'sistema.reset_senha',
                $conta['tenant_id'] !== null ? (int)$conta['tenant_id'] : null
            );
            recuperacao_registrar('SENHA_RECUPERACAO_EMAIL_ENVIADO', 'Token enviado; tipo=' . $conta['tipo'] . '; conta=' . $conta['id'] . '; IP=' . $ip, $conta['nome']);
        } catch (Throwable $e) {
            error_log('[api_recuperar_senha] envio falhou: ' . $e->getMessage());
            recuperacao_registrar('SENHA_RECUPERACAO_EMAIL_FALHA', 'Falha de envio; tipo=' . $conta['tipo'] . '; conta=' . $conta['id'] . '; IP=' . $ip, $conta['nome']);
        }
    }

    recuperacao_json(true, RECUPERACAO_MENSAGEM_GENERICA);
}

function recuperacao_localizar_token(mysqli $conexao, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = $conexao->prepare(
        'SELECT id, tipo_conta, conta_id, tenant_id
         FROM recuperacao_senha_tokens_v2
         WHERE token_hash = ? AND usado_em IS NULL AND expira_em > NOW()
         LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->num_rows === 1 ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function recuperacao_validar(mysqli $conexao): void
{
    $dados = recuperacao_entrada();
    $token = (string)($dados['token'] ?? $_GET['token'] ?? '');
    recuperacao_json(true, 'Consulta concluída.', ['valido' => recuperacao_localizar_token($conexao, $token) !== null]);
}

function recuperacao_validar_senha(string $senha): ?string
{
    if (strlen($senha) < 10) {
        return 'A nova senha deve ter ao menos 10 caracteres.';
    }
    if (!preg_match('/[a-z]/', $senha) || !preg_match('/[A-Z]/', $senha) || !preg_match('/\d/', $senha)) {
        return 'A nova senha deve conter letras maiúsculas, minúsculas e números.';
    }
    return null;
}

function recuperacao_redefinir(mysqli $conexao): void
{
    $dados = recuperacao_entrada();
    $token = (string)($dados['token'] ?? '');
    $senha = (string)($dados['senha'] ?? '');
    $erroSenha = recuperacao_validar_senha($senha);
    if ($erroSenha !== null) {
        recuperacao_json(false, $erroSenha);
    }

    $registro = recuperacao_localizar_token($conexao, $token);
    if ($registro === null) {
        recuperacao_json(false, 'O link de recuperação é inválido ou expirou.');
    }

    $hashSenha = password_hash($senha, PASSWORD_BCRYPT);
    $conexao->begin_transaction();
    try {
        if ($registro['tipo_conta'] === 'usuario') {
            $stmt = $conexao->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND ativo = 1');
        } else {
            $stmt = $conexao->prepare('UPDATE moradores SET senha = ?, senha_temporaria = 0 WHERE id = ? AND ativo = 1');
        }
        $contaId = (int)$registro['conta_id'];
        $stmt->bind_param('si', $hashSenha, $contaId);
        $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        if ($afetadas !== 1) {
            throw new RuntimeException('A conta não está disponível para redefinição.');
        }

        $stmt = $conexao->prepare('UPDATE recuperacao_senha_tokens_v2 SET usado_em = NOW() WHERE id = ? AND usado_em IS NULL');
        $tokenId = (int)$registro['id'];
        $stmt->bind_param('i', $tokenId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('O token já foi utilizado.');
        }
        $stmt->close();
        $conexao->commit();

        recuperacao_registrar('SENHA_REDEFINIDA', 'Senha redefinida; tipo=' . $registro['tipo_conta'] . '; conta=' . $contaId . '; IP=' . recuperacao_ip());
        recuperacao_json(true, 'Senha redefinida com sucesso. Faça login com a nova senha.');
    } catch (Throwable $e) {
        $conexao->rollback();
        error_log('[api_recuperar_senha] redefinição falhou: ' . $e->getMessage());
        recuperacao_json(false, 'Não foi possível redefinir a senha. Solicite um novo link.');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        recuperacao_json(false, 'Método não permitido.');
    }

    $conexao = conectar_banco();
    $dados = recuperacao_entrada();
    $acao = (string)($dados['acao'] ?? $_GET['acao'] ?? 'solicitar');

    if ($acao === 'solicitar') {
        recuperacao_solicitar($conexao);
    }
    if ($acao === 'validar_token') {
        recuperacao_validar($conexao);
    }
    if ($acao === 'redefinir') {
        recuperacao_redefinir($conexao);
    }

    recuperacao_json(false, 'Ação inválida.');
} catch (Throwable $e) {
    error_log('[api_recuperar_senha] erro interno: ' . $e->getMessage());
    // A solicitação é pública: manter a mensagem neutra, mas sinalizar erro
    // para que a interface não confirme sucesso quando o envio não ocorreu.
    http_response_code(500);
    recuperacao_json(false, 'Não foi possível processar a solicitação neste momento. Tente novamente mais tarde.');
}
