<?php
declare(strict_types=1);

/**
 * Criptografia AES-256-CBC para credenciais de provedores de e-mail.
 *
 * A chave principal deve vir de ERP_EMAIL_CRYPTO_KEY, definido exclusivamente
 * no arquivo externo de configuração. A derivação v1 pela senha do banco é
 * mantida apenas para leitura temporária de valores legados durante a rotação.
 */
class EmailCrypto
{
    private const CIPHER = 'aes-256-cbc';
    private const SEPARATOR = '::ENC::';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::activeKey();
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new RuntimeException('Falha ao criptografar a credencial do provedor de e-mail.');
        }

        return base64_encode($iv . self::SEPARATOR . $cipher);
    }

    public static function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strpos($decoded, self::SEPARATOR) === false) {
            // Retrocompatibilidade: instalações antigas podem ter texto puro.
            return $ciphertext;
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($decoded, 0, $ivLen);
        $cipher = substr($decoded, $ivLen + strlen(self::SEPARATOR));

        foreach (self::decryptionKeys() as $key) {
            $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) {
                return $plain;
            }
        }

        // Mantém o comportamento legado de não devolver detalhes internos.
        return $ciphertext;
    }

    public static function isEncrypted(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $decoded = base64_decode($value, true);
        return $decoded !== false && strpos($decoded, self::SEPARATOR) !== false;
    }

    public static function mask(string $plainKey): string
    {
        $len = strlen($plainKey);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        return str_repeat('•', max(8, $len - 4)) . substr($plainKey, -4);
    }

    private static function activeKey(): string
    {
        $secret = defined('ERP_EMAIL_CRYPTO_KEY') ? trim((string) ERP_EMAIL_CRYPTO_KEY) : '';
        if ($secret !== '') {
            return hash('sha256', 'erp-email-crypto-v2|' . $secret, true);
        }

        error_log('[ERP_EMAIL_CRYPTO] ERP_EMAIL_CRYPTO_KEY ausente; usando compatibilidade temporária v1.');
        return self::legacyDatabaseKey();
    }

    private static function decryptionKeys(): array
    {
        $keys = [self::activeKey(), self::legacyDatabaseKey()];
        $unique = [];
        foreach ($keys as $key) {
            $unique[bin2hex($key)] = $key;
        }
        return array_values($unique);
    }

    private static function legacyDatabaseKey(): string
    {
        $seed = (defined('DB_PASS') ? DB_PASS : '')
            . (defined('DB_NAME') ? DB_NAME : '')
            . 'erp-email-crypto-v1';
        return hash('sha256', $seed, true);
    }
}
