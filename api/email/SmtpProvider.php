<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/../email_error_logger.php';
require_once __DIR__ . '/../../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../../PHPMailer/SMTP.php';
require_once __DIR__ . '/../../PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * SmtpProvider
 *
 * Envio via SMTP usando PHPMailer.
 * Mantém toda a lógica de fallback Microsoft e diagnóstico
 * que existia no EmailSender original.
 */
class SmtpProvider implements EmailProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        array  $attachments = []
    ): array {
        error_log('PASSOU_AQUI_SMTP_SEND: host=' . ($this->config['smtp_host'] ?? 'N/A') . ' usuario=' . ($this->config['smtp_usuario'] ?? 'N/A'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->failure("E-mail destinatário inválido: $to");
        }

        $hosts     = $this->resolveHosts();
        $lastError = null;

        foreach ($hosts as $host) {
            $mail = null;
            try {
                $mail = $this->buildMailer($host);
                $mail->addAddress($to, $toName ?: $to);
                $mail->Subject = $subject;
                $mail->Body    = $htmlBody;
                $mail->AltBody = strip_tags($htmlBody);

                foreach ($attachments as $path) {
                    if (is_file($path)) {
                        $mail->addAttachment($path);
                    }
                }

                $mail->send();

                email_error_log('INFO', 'SmtpProvider::send OK', ['host' => $host, 'to' => $to]);

                return [
                    'success'       => true,
                    'message_id'    => null,
                    'response_code' => 250,
                    'error'         => null,
                ];
            } catch (PHPMailerException $e) {
                $lastError = ($mail !== null ? $mail->ErrorInfo : null) ?: $e->getMessage();
                email_error_log('ERROR', 'SmtpProvider::send falhou', ['host' => $host, 'error' => $lastError]);
                if ($mail !== null) {
                    $mail->smtpClose();
                }
            }
        }

        return $this->failure($lastError ?? 'Falha SMTP desconhecida');
    }

    public function sendTest(string $to): array
    {
        $result = $this->send(
            $to,
            'Destinatário de Teste',
            'Teste de Configuração SMTP — ' . date('d/m/Y H:i'),
            $this->testHtml($to)
        );

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? "E-mail de teste enviado com sucesso para $to via SMTP!"
                : 'Erro SMTP: ' . ($result['error'] ?? 'Erro desconhecido'),
        ];
    }

    public function validateConfiguration(): array
    {
        $errors   = [];
        $required = ['smtp_host', 'smtp_port', 'smtp_usuario', 'smtp_senha', 'smtp_de_email'];

        foreach ($required as $field) {
            if (empty($this->config[$field])) {
                $errors[] = "Campo obrigatório ausente: $field";
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function buildMailer(string $host): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host     = $host;
        $mail->SMTPAuth = true;
        $mail->AuthType = 'LOGIN';
        $mail->Username = $this->config['smtp_usuario'];
        $mail->Password = $this->config['smtp_senha'];
        $mail->Port     = (int) ($this->config['smtp_port'] ?? 587);
        $mail->Timeout  = (int) ($this->config['timeout']   ?? 30);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->setFrom(
            $this->config['smtp_de_email'],
            $this->config['smtp_de_nome'] ?? ''
        );

        $sec = $this->config['smtp_seguranca'] ?? 'tls';
        if ($sec === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($sec === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        return $mail;
    }

    private function resolveHosts(): array
    {
        $host  = trim((string) ($this->config['smtp_host'] ?? ''));
        $hosts = [$host];

        $user        = strtolower((string) ($this->config['smtp_usuario'] ?? ''));
        $isMicrosoft = str_contains($host, 'office365.com')
            || str_contains($host, 'outlook.com')
            || str_ends_with($user, '@outlook.com')
            || str_ends_with($user, '@hotmail.com')
            || str_ends_with($user, '@live.com');

        if ($isMicrosoft) {
            if (strcasecmp($host, 'smtp.office365.com') === 0) {
                $hosts[] = 'smtp-mail.outlook.com';
            } elseif (strcasecmp($host, 'smtp-mail.outlook.com') === 0) {
                $hosts[] = 'smtp.office365.com';
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private function failure(string $error, ?int $code = null): array
    {
        return ['success' => false, 'message_id' => null, 'response_code' => $code, 'error' => $error];
    }

    private function testHtml(string $to): string
    {
        $host = $this->config['smtp_host'] ?? 'N/A';
        $port = $this->config['smtp_port'] ?? 'N/A';
        $sec  = strtoupper($this->config['smtp_seguranca'] ?? 'N/A');
        $date = date('d/m/Y H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px}
  .box{max-width:580px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
  .hd{background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;padding:24px;text-align:center}
  .bd{padding:28px}
  .badge{display:inline-block;background:#dbeafe;color:#1e40af;padding:4px 12px;border-radius:20px;font-size:13px;margin-bottom:16px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  td{padding:8px 12px;border-bottom:1px solid #f1f5f9}
  td:first-child{color:#64748b;width:140px}
  .ft{text-align:center;padding:16px;font-size:12px;color:#94a3b8}
</style>
</head>
<body>
<div class="box">
  <div class="hd"><h2 style="margin:0">✅ Configuração SMTP OK</h2></div>
  <div class="bd">
    <div class="badge">SMTP Personalizado</div>
    <p>Sua configuração SMTP está funcionando corretamente.</p>
    <table>
      <tr><td>Destinatário</td><td>$to</td></tr>
      <tr><td>Data/Hora</td><td>$date</td></tr>
      <tr><td>Servidor</td><td>$host</td></tr>
      <tr><td>Porta</td><td>$port</td></tr>
      <tr><td>Segurança</td><td>$sec</td></tr>
    </table>
  </div>
  <div class="ft">Sistema ERP Condomínio — E-mail automático, não responda.</div>
</div>
</body></html>
HTML;
    }
}
