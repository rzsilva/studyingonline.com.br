<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

interface Mailer
{
    public function send(string $to, string $subject, string $html): void;
}
