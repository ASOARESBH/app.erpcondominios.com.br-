<?php
declare(strict_types=1);

/**
 * EmailSender
 *
 * Fachada central de envio de e-mails do ERP.
 * Delega o transporte ao provider configurado (Brevo, Resend ou SMTP)
 * via EmailProviderFactory — mantendo 100% das assinaturas públicas
 * originais para compatibilidade com todos os módulos existentes.
 *
 * Fluxo: ERP → EmailSender → EmailProviderFactory → [BrevoProvider | ResendProvider | SmtpProvider]
 */

require_once __DIR__ . '/email_error_logger.php';
require_once __DIR__ . '/email/EmailCrypto.php';
require_once __DIR__ . '/email/EmailProviderInterface.php';
require_once __DIR__ . '/email/BrevoProvider.php';
require_once __DIR__ . '/email/ResendProvider.php';
require_once __DIR__ . '/email/SmtpProvider.php';
require_once __DIR__ . '/email/EmailProviderFactory.php';

class EmailSender
{
    private $conexao;
    private bool $debug;
    private EmailProviderInterface $provider;
    private array $config;

    public function __construct($conexao, bool $debug = false)
    {
        $this->conexao = $conexao;
        $this->debug   = $debug;
        $this->carregarConfiguracao();
    }

    // ─────────────────────────────────────────────────────────
    // API PÚBLICA (preservada — não alterar assinaturas)
    // ─────────────────────────────────────────────────────────

    /**
     * Envia um e-mail genérico.
     *
     * @param string $destinatario   E-mail do destinatário
     * @param string $assunto        Assunto
     * @param string $corpo          Corpo HTML
     * @param string $nomeDestinatario Nome (opcional)
     * @param array  $anexos         Caminhos de arquivos (opcional)
     * @return bool true em caso de sucesso
     * @throws RuntimeException em caso de falha
     */
    public function enviar(
        string $destinatario,
        string $assunto,
        string $corpo,
        string $nomeDestinatario = '',
        array  $anexos = []
    ): bool {
        $result = $this->provider->send($destinatario, $nomeDestinatario, $assunto, $corpo, $anexos);

        $this->registrarLog(
            $destinatario,
            $assunto,
            $result['success'] ? 'enviado' : 'erro',
            $result['error'],
            $result['message_id']    ?? null,
            $result['response_code'] ?? null
        );

        if (!$result['success']) {
            $erro = $result['error'] ?? 'Falha desconhecida no envio';
            email_error_log('ERROR', 'EmailSender::enviar falhou', [
                'provider'     => $this->config['email_provider'] ?? 'desconhecido',
                'destinatario' => $destinatario,
                'assunto'      => $assunto,
                'error'        => $erro,
            ]);
            throw new \RuntimeException("Erro ao enviar e-mail: $erro");
        }

        return true;
    }

    /**
     * Envia e-mail de recuperação de senha.
     */
    public function enviarRecuperacaoSenha(
        string $destinatario,
        string $nomeDestinatario,
        string $token,
        ?int   $moradorId = null
    ): bool {
        [$assunto, $corpo] = $this->resolverTemplateRecuperacao($nomeDestinatario, $token);

        $enviado = $this->enviar($destinatario, $assunto, $corpo, $nomeDestinatario);

        if ($moradorId !== null) {
            $status = $enviado ? 'enviado' : 'erro';
            $dest   = mysqli_real_escape_string($this->conexao, $destinatario);
            $ass    = mysqli_real_escape_string($this->conexao, $assunto);
            mysqli_query($this->conexao,
                "INSERT INTO email_log (morador_id, destinatario, assunto, tipo, status)
                 VALUES ($moradorId, '$dest', '$ass', 'recuperacao_senha', '$status')"
            );
        }

        return $enviado;
    }

    /**
     * Envia e-mail de teste para verificar a configuração ativa.
     */
    public function enviarTeste(string $destinatario): bool
    {
        $result = $this->provider->sendTest($destinatario);

        $this->registrarLog(
            $destinatario,
            'Teste de Configuração',
            $result['success'] ? 'enviado' : 'erro',
            $result['success'] ? null : $result['message']
        );

        if (!$result['success']) {
            throw new \RuntimeException($result['message']);
        }

        return true;
    }

    /**
     * Retorna informações da configuração ativa (sem credenciais).
     */
    public function getConfiguracao(): array
    {
        return [
            'provider'     => $this->config['email_provider'] ?? 'smtp',
            'host'         => $this->config['smtp_host']      ?? null,
            'port'         => $this->config['smtp_port']      ?? null,
            'usuario'      => $this->config['smtp_usuario']   ?? null,
            'de_email'     => $this->config['smtp_de_email']  ?? null,
            'de_nome'      => $this->config['smtp_de_nome']   ?? null,
            'seguranca'    => $this->config['smtp_seguranca'] ?? null,
            'sender_email' => $this->config['sender_email']   ?? null,
            'sender_name'  => $this->config['sender_name']    ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // PRIVADOS
    // ─────────────────────────────────────────────────────────

    private function carregarConfiguracao(): void
    {
        $res = mysqli_query(
            $this->conexao,
            "SELECT * FROM configuracao_smtp WHERE smtp_ativo = 1 ORDER BY id DESC LIMIT 1"
        );

        if (!$res || mysqli_num_rows($res) === 0) {
            throw new \RuntimeException('Nenhuma configuração de e-mail ativa encontrada no banco de dados');
        }

        $this->config   = mysqli_fetch_assoc($res);
        error_log('PASSOU_AQUI_3: EmailSender::carregarConfiguracao email_provider=' . ($this->config['email_provider'] ?? 'NULL')
            . ' api_key_tem=' . (!empty($this->config['api_key']) ? 'SIM' : 'NAO')
            . ' smtp_host=' . ($this->config['smtp_host'] ?? ''));
        $this->provider = EmailProviderFactory::fromConfig($this->config);
        error_log('PASSOU_AQUI_3B: Provider instanciado: ' . get_class($this->provider));
    }

    private function resolverTemplateRecuperacao(string $nome, string $token): array
    {
        $res = mysqli_query(
            $this->conexao,
            "SELECT * FROM email_templates WHERE tipo = 'recuperacao_senha' AND ativo = 1 LIMIT 1"
        );

        if ($res && mysqli_num_rows($res) > 0) {
            $tpl    = mysqli_fetch_assoc($res);
            $assunto = $tpl['assunto'];
            $corpo   = $tpl['corpo'];
        } else {
            $assunto = 'Recuperação de Senha - ERP Condomínio';
            $corpo   = $this->templateRecuperacaoSenhaPadrao();
        }

        $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir       = dirname($_SERVER['PHP_SELF'] ?? '/');
        $link      = "$protocolo://$host$dir/redefinir_senha.html?token=$token";

        $vars = [
            '{{nome}}'             => $nome,
            '{{link_recuperacao}}' => $link,
            '{{token}}'            => $token,
            '{{NOME_MORADOR}}'     => $nome,
            '{{LINK_RECUPERACAO}}' => $link,
            '{{TEMPO_EXPIRACAO}}'  => '24 horas',
            '{{ANO}}'              => date('Y'),
        ];

        return [$assunto, str_replace(array_keys($vars), array_values($vars), $corpo)];
    }

    private function registrarLog(
        string  $destinatario,
        string  $assunto,
        string  $status,
        ?string $erro        = null,
        ?string $messageId   = null,
        ?int    $responseCode = null
    ): void {
        $provider  = $this->config['email_provider'] ?? 'smtp';
        $d  = mysqli_real_escape_string($this->conexao, $destinatario);
        $a  = mysqli_real_escape_string($this->conexao, $assunto);
        $s  = mysqli_real_escape_string($this->conexao, $status);
        $p  = mysqli_real_escape_string($this->conexao, $provider);
        $e  = $erro       !== null ? "'" . mysqli_real_escape_string($this->conexao, $erro)      . "'" : 'NULL';
        $m  = $messageId  !== null ? "'" . mysqli_real_escape_string($this->conexao, $messageId) . "'" : 'NULL';
        $rc = $responseCode !== null ? (int) $responseCode : 'NULL';

        // Tenta gravar com colunas novas; faz fallback para colunas básicas se a migração ainda não rodou
        $sql = "INSERT INTO email_log (destinatario, assunto, tipo, status, erro_mensagem, provider, message_id, response_code)
                VALUES ('$d', '$a', 'outro', '$s', $e, '$p', $m, $rc)";

        if (!mysqli_query($this->conexao, $sql)) {
            mysqli_query(
                $this->conexao,
                "INSERT INTO email_log (destinatario, assunto, tipo, status, erro_mensagem)
                 VALUES ('$d', '$a', 'outro', '$s', $e)"
            );
        }
    }

    private function templateRecuperacaoSenhaPadrao(): string
    {
        return '<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;line-height:1.6;color:#333;margin:0;padding:0}
  .c{max-width:600px;margin:0 auto;padding:20px}
  .hd{background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;padding:30px 20px;text-align:center;border-radius:8px 8px 0 0}
  .bd{background:#f8fafc;padding:30px 20px;border-radius:0 0 8px 8px}
  .btn{display:inline-block;padding:12px 30px;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;margin:20px 0}
  .warn{background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:20px 0;border-radius:4px}
  .ft{text-align:center;margin-top:20px;font-size:12px;color:#64748b;padding:20px}
</style>
</head>
<body>
  <div class="c">
    <div class="hd"><h1>🔐 Recuperação de Senha</h1></div>
    <div class="bd">
      <p>Olá, <strong>{{nome}}</strong>!</p>
      <p>Recebemos uma solicitação de recuperação de senha para sua conta.</p>
      <div style="text-align:center"><a href="{{link_recuperacao}}" class="btn">Redefinir Senha</a></div>
      <div class="warn">
        <p><strong>⚠️ Importante:</strong></p>
        <ul>
          <li>Este link é válido por <strong>24 horas</strong></li>
          <li>Pode ser usado apenas <strong>uma vez</strong></li>
          <li>Se você não solicitou isso, ignore este e-mail</li>
        </ul>
      </div>
      <p style="word-break:break-all;background:#e2e8f0;padding:10px;border-radius:4px;font-size:12px">{{link_recuperacao}}</p>
    </div>
    <div class="ft">
      <p>ERP Condomínio — ERP Condomínio — Gestão Inteligente</p>
      <p>Este é um e-mail automático, não responda.</p>
    </div>
  </div>
</body>
</html>';
    }
}
