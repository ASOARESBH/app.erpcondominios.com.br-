<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/EmailCrypto.php';
require_once __DIR__ . '/BrevoProvider.php';
require_once __DIR__ . '/ResendProvider.php';
require_once __DIR__ . '/SmtpProvider.php';
require_once __DIR__ . '/FallbackEmailProvider.php';

/**
 * EmailProviderFactory
 *
 * Lê a coluna `email_provider` da configuração e instancia
 * o provider correto. Suporta retrocompatibilidade com
 * instalações que já têm SMTP configurado.
 *
 * CAMPO AUTORITATIVO: `email_provider` (não `provedor`).
 *   - `provedor`      → preset SMTP histórico (gmail, outlook, brevo-relay, etc.)
 *   - `email_provider`→ transporte real: 'brevo'|'resend'|'smtp'
 */
class EmailProviderFactory
{
    /**
     * Instancia o provider a partir de um array de configuração
     * (linha da tabela configuracao_smtp).
     */
    public static function fromConfig(array $config): EmailProviderInterface
    {
        $provider = $config['email_provider'] ?? 'smtp';

        error_log('PASSOU_AQUI_4: EmailProviderFactory::fromConfig email_provider=' . $provider
            . ' has_api_key=' . (!empty($config['api_key']) ? 'SIM' : 'NAO')
            . ' smtp_host=' . ($config['smtp_host'] ?? ''));

        if (function_exists('email_error_log')) {
            email_error_log('INFO', 'EmailProviderFactory::fromConfig', [
                'email_provider' => $provider,
                'provedor'       => $config['provedor'] ?? '—',
                'has_api_key'    => !empty($config['api_key']),
                'smtp_host'      => $config['smtp_host'] ?? '',
            ]);
        }

        switch ($provider) {
            case 'brevo':
                $apiKey = self::decryptKey($config['api_key'] ?? '');
                if (empty($apiKey)) {
                    // API Key ausente — log de alerta claro
                    if (function_exists('email_error_log')) {
                        email_error_log('WARNING', 'BrevoProvider: api_key vazia — verifique a configuração no painel', []);
                    }
                }
                return new BrevoProvider(
                    $apiKey,
                    $config['sender_email'] ?? $config['smtp_de_email'] ?? '',
                    $config['sender_name']  ?? $config['smtp_de_nome']  ?? ''
                );

            case 'resend':
                $apiKey = self::decryptKey($config['api_key'] ?? '');
                if (empty($apiKey)) {
                    if (function_exists('email_error_log')) {
                        email_error_log('WARNING', 'ResendProvider: api_key vazia — verifique a configuração no painel', []);
                    }
                }
                return new ResendProvider(
                    $apiKey,
                    $config['sender_email'] ?? $config['smtp_de_email'] ?? '',
                    $config['sender_name']  ?? $config['smtp_de_nome']  ?? ''
                );

            case 'smtp':
            default:
                if ($provider !== 'smtp' && function_exists('email_error_log')) {
                    email_error_log('WARNING', "EmailProviderFactory: provider '$provider' desconhecido, usando SmtpProvider", []);
                }
                return new SmtpProvider($config);
        }
    }

    /**
     * Instancia o provider diretamente a partir do banco de dados.
     *
     * @param \mysqli $db Conexão MySQL ativa
     */
    public static function fromDatabase(\mysqli $db): EmailProviderInterface
    {
        $res = mysqli_query($db, "SELECT * FROM configuracao_smtp WHERE smtp_ativo = 1 ORDER BY id DESC LIMIT 1");

        if (!$res || mysqli_num_rows($res) === 0) {
            throw new \RuntimeException('Nenhuma configuração de e-mail ativa encontrada no banco de dados');
        }

        return self::fromConfig(mysqli_fetch_assoc($res));
    }

    /**
     * Monta FallbackEmailProvider a partir da tabela email_providers.
     * Tenta provedores em ordem de prioridade (1, 2, 3...).
     * Fallback para fromDatabase() se a tabela não existir.
     *
     * @param \mysqli $db Conexão MySQL ativa
     */
    public static function fromDatabaseWithFallback(\mysqli $db): EmailProviderInterface
    {
        $res = mysqli_query(
            $db,
            "SELECT * FROM email_providers WHERE ativo = 1 ORDER BY prioridade ASC LIMIT 3"
        );

        if (!$res || mysqli_num_rows($res) === 0) {
            // Tabela não existe ou vazia — usar provider único padrão
            return self::fromDatabase($db);
        }

        $providers = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $config = [
                'email_provider' => $row['provider'],
                'api_key'        => $row['api_key']       ?? '',
                'sender_email'   => $row['sender_email']  ?? '',
                'sender_name'    => $row['sender_name']   ?? '',
                'smtp_host'      => $row['smtp_host']     ?? '',
                'smtp_port'      => $row['smtp_port']     ?? 587,
                'smtp_usuario'   => $row['smtp_user']     ?? '',
                'smtp_senha'     => $row['smtp_password'] ?? '',
                'smtp_de_email'  => $row['sender_email']  ?? '',
                'smtp_de_nome'   => $row['sender_name']   ?? '',
                'smtp_seguranca' => 'tls',
            ];
            $providers[] = self::fromConfig($config);
        }

        if (count($providers) === 1) {
            return $providers[0];
        }

        return new FallbackEmailProvider($providers);
    }

    private static function decryptKey(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return EmailCrypto::decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
