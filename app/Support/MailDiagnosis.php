<?php

namespace App\Support;

/**
 * Diagnóstico de correo saliente (SMTP / mailer).
 */
final class MailDiagnosis
{
    public static function isConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return false;
        }

        if ($mailer === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');

            return $host !== '' && $host !== '127.0.0.1' && $host !== 'localhost';
        }

        return true;
    }

    public static function message(): string
    {
        $mailer = (string) config('mail.default');
        if (self::isConfigured()) {
            return 'Correo saliente configurado (mailer: '.$mailer.').';
        }

        return 'SMTP / correo saliente no configurado (mailer actual: '.$mailer.'). '
            .'La funcionalidad está implementada; configurá MAIL_MAILER=smtp y credenciales en .env para envío real. '
            .'Mientras tanto los enlaces pueden registrarse en el canal log.';
    }
}
