<?php
declare(strict_types=1);

/**
 * EmailCrypto
 *
 * Criptografia AES-256-CBC para API Keys de provedores de e-mail.
 * A chave é derivada das credenciais do banco (única por instalação).
 *
 * Compatível com retroatividade: valores em texto puro (instalações
 * antigas) são retornados sem erro — apenas os novos são criptografados.
 */
class EmailCrypto
{
    private const CIPHER    = 'aes-256-cbc';
    private const SEPARATOR = '::ENC::';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key    = self::deriveKey();
        $ivLen  = openssl_cipher_iv_length(self::CIPHER);
        $iv     = openssl_random_pseudo_bytes($ivLen);
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new RuntimeException('Falha ao criptografar a API Key. Verifique se OpenSSL está habilitado.');
        }

        return base64_encode($iv . self::SEPARATOR . $cipher);
    }

    public static function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        $decoded = base64_decode($ciphertext, true);

        // Retrocompatibilidade: valor em texto puro (não criptografado)
        if ($decoded === false || strpos($decoded, self::SEPARATOR) === false) {
            return $ciphertext;
        }

        $key   = self::deriveKey();
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv    = substr($decoded, 0, $ivLen);

        // Posição após IV + SEPARATOR
        $cipherStart = $ivLen + strlen(self::SEPARATOR);
        $cipher      = substr($decoded, $cipherStart);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            // Pode ser uma chave em base64 puro (não nosso formato) — devolve como está
            return $ciphertext;
        }

        return $plain;
    }

    /**
     * Verifica se um valor já está no formato criptografado desta classe.
     */
    public static function isEncrypted(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $decoded = base64_decode($value, true);
        return $decoded !== false && strpos($decoded, self::SEPARATOR) !== false;
    }

    /**
     * Mascara uma API Key para exibição no frontend (ex: "sk-••••••••••••abcd").
     */
    public static function mask(string $plainKey): string
    {
        $len = strlen($plainKey);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        return str_repeat('•', max(8, $len - 4)) . substr($plainKey, -4);
    }

    // Deriva uma chave AES-256 a partir das credenciais do banco (únicas por instalação)
    private static function deriveKey(): string
    {
        $seed = (defined('DB_PASS') ? DB_PASS : '')
              . (defined('DB_NAME') ? DB_NAME : '')
              . 'erp-email-crypto-v1';

        return hash('sha256', $seed, true);
    }
}
