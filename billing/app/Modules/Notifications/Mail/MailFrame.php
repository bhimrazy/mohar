<?php

namespace App\Modules\Notifications\Mail;

/**
 * What the system computes for an email around the operator's text (design §3, §9): the summary label, the amount
 * or status, download links, line items and why the recipient gets it. Built by MailFramer from the merge context
 * with secrets removed; never typed by the operator.
 */
final readonly class MailFrame
{
    /**
     * @param  list<array{label: string, url: string}>  $downloads
     * @param  array{heading: string, items: list<array{name: string, meta: string|null, amount: string}>, totals: list<array{label: string, amount: string, total: bool}>}|null  $lines
     * @param  list<array{0: string, 1: string}>  $facts  system facts shown after the operator's facts (label, value)
     */
    public function __construct(
        public ?string $label = null,
        public ?string $amount = null,
        public ?string $amountLabel = null,
        public ?string $statusTone = null,
        public ?string $statusLabel = null,
        public ?string $statusWhen = null,
        public bool $document = false,
        public array $downloads = [],
        public ?array $lines = null,
        public array $facts = [],
        public ?string $reason = null,
        public bool $security = false,
    ) {}

    public function hasStatus(): bool
    {
        return $this->statusLabel !== null && $this->statusLabel !== '';
    }
}
