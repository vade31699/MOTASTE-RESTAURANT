<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SMTP email delivery for system notifications (verification codes, invites).
 * Ported from the legacy public/api/_email_auth_helpers.php sendSystemEmail().
 */
class EmailService
{
    public static function send(string $to, string $subject, string $body): array
    {
        $to = trim($to);
        if ($to === '') {
            return ['success' => false, 'error' => 'Recipient email is required'];
        }

        $smtpHost = trim((string) config('mail.mailers.smtp.host', ''));
        $smtpPort = (string) config('mail.mailers.smtp.port', '');
        $smtpUser = trim((string) config('mail.mailers.smtp.username', ''));
        $smtpPass = (string) config('mail.mailers.smtp.password', '');
        $smtpScheme = trim((string) config('mail.mailers.smtp.scheme', ''));

        // Cloud envs can lag behind config cache; pull direct env values when config is empty.
        if ($smtpHost === '') {
            $smtpHost = trim((string) (env('MAIL_HOST') ?: ''));
        }
        if ($smtpPort === '') {
            $smtpPort = (string) (env('MAIL_PORT') ?: '');
        }
        if ($smtpUser === '') {
            $smtpUser = trim((string) (env('MAIL_USERNAME') ?: ''));
        }
        if ($smtpPass === '') {
            $smtpPass = (string) (env('MAIL_PASSWORD') ?: '');
        }
        if ($smtpScheme === '') {
            $smtpScheme = trim((string) (env('MAIL_SCHEME') ?: ''));
        }

        config([
            'mail.mailers.smtp.host' => $smtpHost,
            'mail.mailers.smtp.port' => $smtpPort,
            'mail.mailers.smtp.username' => $smtpUser,
            'mail.mailers.smtp.password' => $smtpPass,
        ]);
        if ($smtpScheme !== '') {
            config(['mail.mailers.smtp.scheme' => $smtpScheme]);
        }

        $missing = [];
        if ($smtpHost === '') {
            $missing[] = 'MAIL_HOST';
        }
        if ($smtpPort === '') {
            $missing[] = 'MAIL_PORT';
        }
        if ($smtpUser === '') {
            $missing[] = 'MAIL_USERNAME';
        }
        if ($smtpPass === '') {
            $missing[] = 'MAIL_PASSWORD';
        }

        if ($missing) {
            return [
                'success' => false,
                'driver' => 'smtp',
                'delivered' => false,
                'error' => 'SMTP configuration is incomplete in deployment environment. Missing: '.implode(', ', $missing),
            ];
        }

        // Laravel expects smtp/smtps schemes. For Gmail on port 587, smtp enables STARTTLS.
        if ($smtpScheme === '' && stripos($smtpHost, 'gmail.com') !== false && $smtpPort === '587') {
            config(['mail.mailers.smtp.scheme' => 'smtp']);
            $smtpScheme = 'smtp';
        }

        try {
            // Always send through SMTP to avoid log/array default transports.
            Mail::mailer('smtp')->raw($body, function ($message) use ($to, $subject): void {
                $message->to($to)->subject($subject);
            });

            return ['success' => true, 'driver' => 'smtp', 'delivered' => true];
        } catch (\Throwable $mailError) {
            Log::error('SMTP email send failed', [
                'to' => $to,
                'subject' => $subject,
                'host' => $smtpHost,
                'port' => $smtpPort,
                'scheme' => $smtpScheme,
                'username' => $smtpUser,
                'error' => $mailError->getMessage(),
            ]);

            return [
                'success' => false,
                'driver' => 'smtp',
                'delivered' => false,
                'error' => 'SMTP send failed: '.$mailError->getMessage(),
            ];
        }
    }
}
