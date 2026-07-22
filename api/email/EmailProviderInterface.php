<?php
declare(strict_types=1);

/**
 * EmailProviderInterface
 *
 * Contrato comum para todos os provedores de e-mail transacional.
 * Implementações: BrevoProvider, ResendProvider, SmtpProvider.
 */
interface EmailProviderInterface
{
    /**
     * Envia um e-mail transacional.
     *
     * @param string $to           E-mail do destinatário
     * @param string $toName       Nome do destinatário
     * @param string $subject      Assunto
     * @param string $htmlBody     Corpo HTML
     * @param array  $attachments  Caminhos absolutos de anexos (opcional)
     *
     * @return array{
     *   success: bool,
     *   message_id: string|null,
     *   response_code: int|null,
     *   error: string|null
     * }
     */
    public function send(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        array  $attachments = []
    ): array;

    /**
     * Envia um e-mail de teste para verificar a configuração.
     *
     * @param string $to E-mail de destino
     * @return array{success: bool, message: string}
     */
    public function sendTest(string $to): array;

    /**
     * Valida a configuração do provider sem enviar nada.
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validateConfiguration(): array;
}
