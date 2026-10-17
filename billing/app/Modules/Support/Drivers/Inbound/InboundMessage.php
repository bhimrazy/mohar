<?php

namespace App\Modules\Support\Drivers\Inbound;

/**
 * Provider-neutral inbound email, produced by an InboundDriver.
 */
final readonly class InboundMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $references
     * @param  array<string, string>  $headers  lowercase header name => value
     * @param  list<array{name: string, content: string}>  $attachments  decoded file contents
     * @param  bool|null  $authenticated  provider's SPF/DKIM/DMARC verdict: true = pass, false = fail, null = unknown
     */
    public function __construct(
        public string $provider,
        public ?string $messageId,
        public string $fromEmail,
        public string $fromName,
        public array $to,
        public array $cc,
        public ?string $deliveredTo,
        public string $subject,
        public string $text,
        public array $headers = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?string $date = null,
        public array $attachments = [],
        public ?bool $authenticated = null,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Message-ID, or a stable hash of from+date+subject when the header is missing (spec §6.1).
     */
    public function dedupeKey(): string
    {
        $id = trim((string) $this->messageId);

        return $id !== '' ? mb_substr($id, 0, 255) : 'hash:'.sha1(strtolower($this->fromEmail).'|'.$this->date.'|'.$this->subject);
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter([...$this->to, ...$this->cc, $this->deliveredTo]))));
    }
}
