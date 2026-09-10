<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The 6-digit verification code emailed during the forgot-password flow. This
 * is the only email in the flow — no reset link is ever emailed. After the
 * code is confirmed the user is taken straight to the reset-password form.
 */
class PasswordResetCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $email,
        public string $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MOTASTE Password Reset Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.password-reset-code',
            with: [
                'code' => $this->code,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}