<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * An already-rendered email (EmailComposer did the work); this only hands it to the transport.
 */
class TemplatedMail extends Mailable
{
    /**
     * @param  string|null  $htmlBody  null for plain-text-only templates
     */
    public function __construct(
        public string $subjectLine,
        public ?string $htmlBody,
        public string $plainText,
        public ?string $fromEmail = null,
        public ?string $fromName = null,
        public ?string $replyToEmail = null,
        public ?string $messageUuid = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromEmail !== null ? new Address($this->fromEmail, $this->fromName) : null,
            replyTo: $this->replyToEmail !== null ? [new Address($this->replyToEmail)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'notifications::mail.plain',
            with: ['plainText' => $this->plainText],
            htmlString: $this->htmlBody,
        );
    }

    /**
     * Correlates provider reports with the log row.
     */
    public function headers(): Headers
    {
        return new Headers(text: $this->messageUuid !== null ? ['X-Message-Ref' => $this->messageUuid] : []);
    }
}
