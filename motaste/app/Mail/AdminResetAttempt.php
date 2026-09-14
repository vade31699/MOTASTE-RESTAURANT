<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Security notice sent to the Admin when someone tries to start a password
 * reset with the admin address on the staff recovery page.
 *
 * The staff flow is staff-only — it only ever looks at the `staff` table — so
 * a request with the admin address is either a mistyped email or someone
 * probing for the admin account. Either way the Admin is the only one who can
 * tell, so they get told.
 */
class AdminResetAttempt extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $occurredAt,
        public string $ipAddress,
        public string $userAgent,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MOTASTE Notification: Password recovery attempted with the admin email',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.admin-reset-attempt',
            with: [
                'ipAddress' => $this->ipAddress,
                'occurredAt' => $this->occurredAt,
                'userAgent' => $this->userAgent,
            ],
        );
    }
}
