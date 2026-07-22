<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/../email_error_logger.php';

/**
 * ResendProvider
 *
 * Envio transacional via API REST oficial do Resend.
 * Endpoint: POST https://api.resend.com/emails
 *
 * Docs: https://resend.com/docs/api-reference/emails/send-email
 */
class ResendProvider implements EmailProviderInterface
{
    private const API_URL = 'https://api.resend.com/emails';
    private const TIMEOUT = 30;

    private string $apiKey;
    private string $senderEmail;
    private string $senderName;

    public function __construct(string $apiKey, string $senderEmail, string $senderName)
    {
        $this->apiKey      = $apiKey;
        $this->senderEmail = $senderEmail;
        $this->senderName  = $senderName;
    }

    public function send(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        array  $attachments = []
    ): array {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->failure("E-mail destinatário inválido: $to");
        }

        $from = $this->senderName !== ''
            ? "{$this->senderName} <{$this->senderEmail}>"
            : $this->senderEmail;

        $payload = [
            'from'    => $from,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $htmlBody,
            'text'    => strip_tags($htmlBody),
        ];

        if (!empty($attachments)) {
            $payload['attachments'] = $this->buildAttachments($attachments);
        }

        $result = $this->request(self::API_URL, $payload);

        email_error_log(
            $result['success'] ? 'INFO' : 'ERROR',
            'ResendProvider::send ' . ($result['success'] ? 'OK' : 'FALHOU'),
            ['to' => $to, 'subject' => $subject, 'response_code' => $result['response_code'], 'error' => $result['error']]
        );

        return $result;
    }

    public function sendTest(string $to): array
    {
        $result = $this->send(
            $to,
            'Destinatário de Teste',
            'Teste de Configuração — Resend API — ' . date('d/m/Y H:i'),
            $this->testHtml($to)
        );

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? "E-mail de teste enviado com sucesso para $to via Resend!"
                : 'Erro Resend: ' . ($result['error'] ?? 'Erro desconhecido'),
        ];
    }

    public function validateConfiguration(): array
    {
        $errors = [];

        if (empty($this->apiKey)) {
            $errors[] = 'API Key do Resend é obrigatória';
        }
        if (empty($this->senderEmail) || !filter_var($this->senderEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail remetente inválido ou ausente';
        }
        if (empty($this->senderName)) {
            $errors[] = 'Nome do remetente é obrigatório';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function request(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body     = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $ch       = null; // libera o handle (curl_close depreciado no PHP 8.x)

        if ($curlErr) {
            return $this->failure("cURL error: $curlErr", 0);
        }

        $json      = json_decode($body, true) ?? [];
        $success   = $httpCode >= 200 && $httpCode < 300;
        $messageId = $json['id'] ?? null;
        $error     = $success ? null : ($json['message'] ?? ($json['name'] ?? "HTTP $httpCode — $body"));

        return [
            'success'       => $success,
            'message_id'    => $messageId,
            'response_code' => $httpCode,
            'error'         => $error,
        ];
    }

    private function buildAttachments(array $paths): array
    {
        $list = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $list[] = [
                'filename' => basename($path),
                'content'  => base64_encode((string) file_get_contents($path)),
            ];
        }
        return $list;
    }

    private function failure(string $error, ?int $code = null): array
    {
        return ['success' => false, 'message_id' => null, 'response_code' => $code, 'error' => $error];
    }

    private function testHtml(string $to): string
    {
        $date = date('d/m/Y H:i:s');
        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px}
  .box{max-width:580px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
  .hd{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;padding:24px;text-align:center}
  .bd{padding:28px}
  .badge{display:inline-block;background:#ede9fe;color:#5b21b6;padding:4px 12px;border-radius:20px;font-size:13px;margin-bottom:16px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  td{padding:8px 12px;border-bottom:1px solid #f1f5f9}
  td:first-child{color:#64748b;width:140px}
  .ft{text-align:center;padding:16px;font-size:12px;color:#94a3b8}
</style>
</head>
<body>
<div class="box">
  <div class="hd"><h2 style="margin:0">✅ Configuração Resend OK</h2></div>
  <div class="bd">
    <div class="badge">API REST • Resend</div>
    <p>Sua integração com a <strong>API do Resend</strong> está funcionando corretamente.</p>
    <table>
      <tr><td>Destinatário</td><td>$to</td></tr>
      <tr><td>Data/Hora</td><td>$date</td></tr>
      <tr><td>Provider</td><td>Resend API</td></tr>
      <tr><td>Endpoint</td><td>api.resend.com/emails</td></tr>
    </table>
  </div>
  <div class="ft">Sistema ERP Condomínio — E-mail automático, não responda.</div>
</div>
</body></html>
HTML;
    }
}
