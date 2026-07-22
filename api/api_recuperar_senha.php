<?php
/**
 * API: RECUPERAÇÃO DE SENHA DO MORADOR
 *
 * Gera senha temporária e envia por e-mail.
 * Rate limit: máximo 3 recuperações/hora por morador_id e por IP.
 *
 * POST /api/api_recuperar_senha.php
 * Body: { "cpf_email": "CPF ou e-mail do morador" }
 *
 * Resposta (sempre genérica para não vazar se cadastro existe):
 * { "sucesso": true, "mensagem": "Se os dados informados..." }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once 'config.php';

define('MSG_RECUPERACAO_GENERICA',
    'Se os dados informados estiverem cadastrados, você receberá instruções por e-mail em breve.'
);

$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$input = json_decode(file_get_contents('php://input'), true);
$cpf_email = trim($input['cpf_email'] ?? '');

// Entrada vazia → mensagem genérica (não informa erro)
if (empty($cpf_email)) {
    echo json_encode(['sucesso' => true, 'mensagem' => MSG_RECUPERACAO_GENERICA], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conexao = conectar_banco();

    // Garante que o schema necessário existe antes de qualquer operação
    _garantir_schema_recuperacao($conexao);

    // ── Detectar se é CPF ou e-mail ─────────────────────────────────────
    $cpf_numeros = preg_replace('/\D/', '', $cpf_email);
    $eh_cpf      = strlen($cpf_numeros) === 11;

    // ── Buscar morador ───────────────────────────────────────────────────
    if ($eh_cpf) {
        $stmt = $conexao->prepare(
            "SELECT id, nome, email FROM moradores WHERE cpf = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->bind_param('s', $cpf_numeros);
    } else {
        $email_lower = strtolower($cpf_email);
        if (!filter_var($email_lower, FILTER_VALIDATE_EMAIL)) {
            // Formato inválido — resposta genérica
            echo json_encode(['sucesso' => true, 'mensagem' => MSG_RECUPERACAO_GENERICA], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $conexao->prepare(
            "SELECT id, nome, email FROM moradores WHERE email = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->bind_param('s', $email_lower);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        $stmt->close();
        registrar_log('SENHA_RECUPERACAO_SOLICITADA',
            "Dados não encontrados na base: '{$cpf_email}' | IP: {$ip}"
        );
        echo json_encode(['sucesso' => true, 'mensagem' => MSG_RECUPERACAO_GENERICA], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $morador    = $res->fetch_assoc();
    $stmt->close();
    $morador_id = (int)$morador['id'];

    // ── Rate limit: máx 3 tentativas/hora por morador_id OU por IP ──────
    $hora_atras = date('Y-m-d H:i:s', time() - 3600);

    $stmt_rate = $conexao->prepare(
        "SELECT COUNT(*) AS total
         FROM senha_recuperacao_logs
         WHERE (morador_id = ? OR ip_solicitacao = ?) AND data_solicitacao >= ?"
    );
    $stmt_rate->bind_param('iss', $morador_id, $ip, $hora_atras);
    $stmt_rate->execute();
    $total_tentativas = (int)$stmt_rate->get_result()->fetch_assoc()['total'];
    $stmt_rate->close();

    if ($total_tentativas >= 3) {
        registrar_log('SENHA_RECUPERACAO_ABUSO',
            "Rate limit excedido: morador_id={$morador_id} | IP: {$ip}",
            $morador['nome']
        );
        echo json_encode(['sucesso' => true, 'mensagem' => MSG_RECUPERACAO_GENERICA], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Gerar senha temporária: 3 maiúsculas + 3 dígitos + 1 especial ───
    $senha_temporaria = _gerar_senha_temporaria();
    $senha_hash       = password_hash($senha_temporaria, PASSWORD_BCRYPT);

    // ── Atualizar morador: nova senha + flag senha_temporaria = 1 ────────
    $stmt_upd = $conexao->prepare(
        "UPDATE moradores SET senha = ?, senha_temporaria = 1, data_atualizacao = NOW() WHERE id = ?"
    );
    $stmt_upd->bind_param('si', $senha_hash, $morador_id);
    $stmt_upd->execute();
    $stmt_upd->close();

    // ── Registrar na tabela de logs de recuperação ────────────────────────
    $stmt_log = $conexao->prepare(
        "INSERT INTO senha_recuperacao_logs
             (morador_id, senha_temp_hash, ip_solicitacao, data_solicitacao)
         VALUES (?, ?, ?, NOW())"
    );
    $stmt_log->bind_param('iss', $morador_id, $senha_hash, $ip);
    $stmt_log->execute();
    $stmt_log->close();

    registrar_log('SENHA_RECUPERACAO_SOLICITADA',
        "Senha temporária gerada | morador: {$morador['email']} | IP: {$ip}",
        $morador['nome']
    );

    // ── Enviar e-mail ─────────────────────────────────────────────────────
    $email_ok = _enviar_email_recuperacao($conexao, $morador, $senha_temporaria);

    if ($email_ok) {
        registrar_log('SENHA_RECUPERACAO_EMAIL_ENVIADO',
            "E-mail de recuperação enviado para: {$morador['email']}",
            $morador['nome']
        );
    } else {
        registrar_log('SENHA_RECUPERACAO_EMAIL_FALHA',
            "Falha ao enviar e-mail de recuperação para: {$morador['email']}",
            $morador['nome']
        );
    }

    fechar_conexao($conexao);

    echo json_encode(['sucesso' => true, 'mensagem' => MSG_RECUPERACAO_GENERICA], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[api_recuperar_senha] Erro: ' . $e->getMessage());
    // Nunca retornar erro real ao cliente (anti-enumeração)
    echo json_encode(['sucesso' => true, 'mensagem' => MSG_RECUPERACAO_GENERICA], JSON_UNESCAPED_UNICODE);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Gera senha no formato: 3 maiúsculas + 3 dígitos + 1 especial
 * Exemplo: QWE789#
 */
function _gerar_senha_temporaria(): string
{
    // Exclui caracteres visualmente ambíguos (I, O, 0, 1)
    $maiusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digitos    = '23456789';
    $especiais  = '@#$!';

    $s = '';
    for ($i = 0; $i < 3; $i++) {
        $s .= $maiusculas[random_int(0, strlen($maiusculas) - 1)];
    }
    for ($i = 0; $i < 3; $i++) {
        $s .= $digitos[random_int(0, strlen($digitos) - 1)];
    }
    $s .= $especiais[random_int(0, strlen($especiais) - 1)];

    return $s;
}

/**
 * Envia e-mail com a senha temporária via EmailSender (Brevo → Resend → SMTP).
 */
function _enviar_email_recuperacao($conexao, array $morador, string $senha): bool
{
    try {
        require_once __DIR__ . '/EmailSender.php';

        $sender = new EmailSender($conexao);
        $nome   = htmlspecialchars($morador['nome'], ENT_QUOTES, 'UTF-8');
        $proto  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url    = "{$proto}://{$host}/frontend/login.html";

        $corpo = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">

    <div style="background:linear-gradient(135deg,#2563eb 0%,#1e40af 100%);padding:30px 32px;text-align:center;">
      <h1 style="color:#ffffff;margin:0;font-size:1.25rem;font-weight:700;letter-spacing:-.01em;">
        🔑 Recuperação de Senha
      </h1>
    </div>

    <div style="padding:32px;">
      <p style="color:#1e293b;font-size:1rem;margin-bottom:12px;">Olá, <strong>{$nome}</strong>!</p>
      <p style="color:#475569;font-size:.93rem;line-height:1.65;margin-bottom:24px;">
        Recebemos uma solicitação de recuperação de senha para o seu acesso ao
        <strong>Portal do Morador</strong>. Sua senha temporária está abaixo:
      </p>

      <div style="background:#f8fafc;border:2px dashed #2563eb;border-radius:12px;text-align:center;padding:22px 16px;margin-bottom:24px;">
        <code style="font-size:2rem;font-weight:800;color:#1e40af;letter-spacing:.14em;font-family:monospace;">{$senha}</code>
      </div>

      <div style="background:#fef3c7;border-left:4px solid #f59e0b;border-radius:6px;padding:12px 16px;margin-bottom:24px;">
        <p style="margin:0;color:#92400e;font-size:.88rem;line-height:1.5;">
          ⚠️ <strong>Atenção:</strong> Ao entrar com esta senha temporária, o sistema vai
          solicitar que você crie uma nova senha imediatamente.
        </p>
      </div>

      <div style="text-align:center;margin-bottom:28px;">
        <a href="{$url}"
           style="display:inline-block;background:#2563eb;color:#ffffff;padding:13px 32px;
                  border-radius:9px;text-decoration:none;font-weight:700;font-size:.95rem;">
          Acessar o Portal do Morador
        </a>
      </div>

      <p style="color:#94a3b8;font-size:.8rem;border-top:1px solid #e2e8f0;padding-top:16px;margin:0;line-height:1.55;">
        Se você <strong>não solicitou</strong> esta recuperação, ignore este e-mail.
        Sua senha atual permanecerá válida até que alguém utilize a senha temporária acima.
        Nesse caso, entre em contato com a administração do condomínio.
      </p>
    </div>
  </div>
</body>
</html>
HTML;

        return $sender->enviar(
            $morador['email'],
            'Sua senha temporária — Portal do Morador',
            $corpo,
            $morador['nome']
        );

    } catch (Exception $e) {
        error_log('[api_recuperar_senha] Falha no e-mail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Garante que a coluna senha_temporaria e a tabela senha_recuperacao_logs existem.
 * Executado uma vez por chamada, com overhead mínimo (SHOW COLUMNS é indexado).
 */
function _garantir_schema_recuperacao($conexao): void
{
    // Coluna na tabela moradores
    $col = $conexao->query("SHOW COLUMNS FROM moradores LIKE 'senha_temporaria'");
    if ($col && $col->num_rows === 0) {
        $conexao->query(
            "ALTER TABLE moradores ADD COLUMN senha_temporaria TINYINT(1) NOT NULL DEFAULT 0"
        );
    }

    // Tabela de logs
    $conexao->query("
        CREATE TABLE IF NOT EXISTS senha_recuperacao_logs (
            id               INT          AUTO_INCREMENT PRIMARY KEY,
            morador_id       INT          NOT NULL,
            senha_temp_hash  VARCHAR(255) NOT NULL,
            ip_solicitacao   VARCHAR(45)  NOT NULL,
            data_solicitacao DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            utilizada        TINYINT(1)   NOT NULL DEFAULT 0,
            data_utilizacao  DATETIME     NULL,
            INDEX idx_morador (morador_id),
            INDEX idx_ip      (ip_solicitacao),
            INDEX idx_data    (data_solicitacao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
