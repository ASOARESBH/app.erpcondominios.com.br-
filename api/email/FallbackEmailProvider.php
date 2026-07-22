<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/../email_error_logger.php';

/**
 * FallbackEmailProvider
 *
 * Cascata de envio: tenta cada provider na ordem dada.
 * Se um falhar, tenta o próximo e registra a tentativa.
 *
 * Fluxo padrão: BrevoProvider → ResendProvider → SmtpProvider
 */
class FallbackEmailProvider implements EmailProviderInterface
{
    /** @var EmailProviderInterface[] */
    private array $providers;
    private array $tentativas = [];

    /**
     * @param EmailProviderInterface[] $providers  Ordenados por prioridade (índice 0 = primário)
     */
    public function __construct(array $providers)
    {
        if (empty($providers)) {
            throw new \InvalidArgumentException('FallbackEmailProvider requer ao menos um provider.');
        }
        $this->providers = array_values($providers);
    }

    public function send(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        array  $attachments = []
    ): array {
        $this->tentativas = [];
        $inicio = microtime(true);

        foreach ($this->providers as $provider) {
            $classe = get_class($provider);
            $t0     = microtime(true);

            try {
                $result  = $provider->send($to, $toName, $subject, $htmlBody, $attachments);
                $elapsed = round(microtime(true) - $t0, 3);

                $this->tentativas[] = [
                    'provider' => $classe,
                    'success'  => $result['success'],
                    'elapsed'  => $elapsed,
                    'error'    => $result['error'] ?? null,
                ];

                if ($result['success']) {
                    email_error_log('INFO', "FallbackEmailProvider: sucesso via $classe", [
                        'to'         => $to,
                        'tentativas' => count($this->tentativas),
                        'total_s'    => round(microtime(true) - $inicio, 3),
                    ]);
                    $result['fallback_chain']    = $this->tentativas;
                    $result['provider_utilizado'] = $classe;
                    return $result;
                }

                email_error_log('WARNING', "FallbackEmailProvider: $classe falhou — tentando próximo", [
                    'to'    => $to,
                    'error' => $result['error'] ?? 'Desconhecido',
                ]);
            } catch (\Throwable $e) {
                $elapsed            = round(microtime(true) - $t0, 3);
                $this->tentativas[] = [
                    'provider' => $classe,
                    'success'  => false,
                    'elapsed'  => $elapsed,
                    'error'    => $e->getMessage(),
                ];
                email_error_log('WARNING', "FallbackEmailProvider: $classe lançou exceção", [
                    'to'    => $to,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        email_error_log('ERROR', 'FallbackEmailProvider: TODOS os providers falharam', [
            'to'         => $to,
            'tentativas' => $this->tentativas,
        ]);

        $erros = array_map(
            fn($t) => basename(str_replace('\\', '/', $t['provider'])) . ': ' . ($t['error'] ?? '?'),
            $this->tentativas
        );

        return [
            'success'           => false,
            'message_id'        => null,
            'response_code'     => null,
            'error'             => 'Todos os providers falharam: ' . implode('; ', $erros),
            'fallback_chain'    => $this->tentativas,
            'provider_utilizado' => null,
        ];
    }

    public function sendTest(string $to): array
    {
        $result = $this->send(
            $to,
            'Destinatário de Teste',
            'Teste — Sistema ERP — ' . date('d/m/Y H:i'),
            $this->testHtml($to)
        );

        $usado = '';
        foreach ($this->tentativas as $t) {
            if ($t['success']) { $usado = basename(str_replace('\\', '/', $t['provider'])); break; }
        }

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? "E-mail de teste enviado via $usado com fallback automático."
                : 'Todos os providers falharam: ' . ($result['error'] ?? ''),
            'chain'   => $this->tentativas,
        ];
    }

    public function validateConfiguration(): array
    {
        $errors = [];
        foreach ($this->providers as $provider) {
            $v = $provider->validateConfiguration();
            if (!$v['valid']) {
                $classe   = basename(str_replace('\\', '/', get_class($provider)));
                $errors[] = "$classe: " . implode(', ', $v['errors']);
            }
        }
        return ['valid' => count($errors) < count($this->providers), 'errors' => $errors];
    }

    public function getTentativas(): array
    {
        return $this->tentativas;
    }

    public function getProviders(): array
    {
        return $this->providers;
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
  <div class="hd"><h2 style="margin:0">✅ Teste com Fallback OK</h2></div>
  <div class="bd">
    <div class="badge">FallbackEmailProvider — Cascata automática</div>
    <p>Teste de envio com fallback automático (Brevo → Resend → SMTP) concluído.</p>
    <table>
      <tr><td>Destinatário</td><td>$to</td></tr>
      <tr><td>Data/Hora</td><td>$date</td></tr>
      <tr><td>Modo</td><td>Cascata com fallback</td></tr>
    </table>
  </div>
  <div class="ft">Sistema ERP Condomínio — E-mail automático, não responda.</div>
</div>
</body></html>
HTML;
    }
}
