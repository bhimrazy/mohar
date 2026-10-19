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
    /** @var array<string, string> */
    public const AUTOMATIC_HEADERS = ['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All'];

    /** Templates that carry a message a person wrote (a staff reply): not automatic mail. */
    public const PERSON_WRITTEN = ['ticket.reply', 'ticket.opened_by_staff'];

    public static function isAutomatic(?string $templateKey): bool
    {
        return $templateKey !== null && ! in_array($templateKey, self::PERSON_WRITTEN, true);
    }

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
        public bool $automatic = true,
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
     * Correlates provider reports with the log row. System mail is marked automatic (RFC 3834), so autoresponders
     * and out-of-office replies leave it alone; a message staff wrote by hand is not.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            ...($this->messageUuid !== null ? ['X-Message-Ref' => $this->messageUuid] : []),
            ...($this->automatic ? self::AUTOMATIC_HEADERS : []),
        ]);
    }
}
