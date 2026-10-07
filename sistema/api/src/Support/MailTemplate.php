<?php

declare(strict_types=1);

namespace App\Support;

/** Layout HTML único dos e-mails (antes Geral.ConstroiHtmlEmail). Conteúdo do usuário sempre escapado com e(). */
final class MailTemplate
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function render(string $titulo, string $corpoHtml, string $instituicao, ?string $email, ?string $telefone): string
    {
        $rodape = implode(' · ', array_filter([self::e($instituicao), self::e($email), self::e($telefone)]));
        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px">'
            . '<tr><td style="padding:24px 32px;border-bottom:1px solid #e2e8f0"><h1 style="margin:0;font-size:18px;color:#0f172a">'
            . self::e($titulo) . '</h1></td></tr>'
            . '<tr><td style="padding:24px 32px;font-size:15px;line-height:1.6;color:#334155">' . $corpoHtml . '</td></tr>'
            . '<tr><td style="padding:16px 32px;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0">' . $rodape . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
