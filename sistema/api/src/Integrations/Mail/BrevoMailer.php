<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use Psr\Log\LoggerInterface;

/** Envio transacional via API HTTP da Brevo (mesmo provedor do legado, Geral.SendBrevo). */
final class BrevoMailer implements Mailer
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $from,
        private readonly string $fromName,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $to, string $subject, string $html): void
    {
        if ($this->apiKey === '') {
            $this->logger->warning('BREVO_API_KEY não configurada; e-mail não enviado.', ['subject' => $subject]);
            return;
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'sender'      => ['email' => $this->from, 'name' => $this->fromName],
                'to'          => [['email' => $to]],
                'subject'     => $subject,
                'htmlContent' => $html,
            ]),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $this->logger->error('Falha ao enviar e-mail pela Brevo.', ['status' => $status, 'body' => $body]);
        }
    }
}
