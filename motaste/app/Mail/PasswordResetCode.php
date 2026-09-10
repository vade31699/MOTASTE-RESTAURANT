<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The 6-digit verification code emailed during the forgot-password flow. The
 * reset link itself is NOT included — it is sent separately, only after this
 * code has been confirmed.
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