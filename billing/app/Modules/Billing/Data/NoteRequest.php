<?php

namespace App\Modules\Billing\Data;

/**
 * What staff or automation ask the note issuer for. A credit note is allocated to lines, either named (`lines`: invoice
 * item id => gross to credit) or by one gross amount spread over the lines. A debit note is one gross amount with no
 * line. `reasonText` is shown to the client.
 */
final readonly class NoteRequest
{
    /**
     * @param  array<int, int>|null  $lines
     */
    private function __construct(
        public string $kind,
        public int $invoiceId,
        public string $reasonCode,
        public string $reasonText,
        public ?array $lines,
        public ?int $amount,
        public bool $taxable,
        public bool $automatic,
        public ?int $userId,
    ) {}

    /**
     * @param  array<int, int>  $lines  invoice item id => gross
     */
    public static function creditLines(int $invoiceId, array $lines, string $reasonCode, string $reasonText, bool $automatic = false, ?int $userId = null): self
    {
        return new self('credit', $invoiceId, $reasonCode, $reasonText, $lines, null, true, $automatic, $userId);
    }

    public static function creditAmount(int $invoiceId, int $gross, string $reasonCode, string $reasonText, bool $automatic = false, ?int $userId = null): self
    {
        return new self('credit', $invoiceId, $reasonCode, $reasonText, null, $gross, true, $automatic, $userId);
    }

    public static function debit(int $invoiceId, int $gross, bool $taxable, string $reasonCode, string $reasonText, bool $automatic = false, ?int $userId = null): self
    {
        return new self('debit', $invoiceId, $reasonCode, $reasonText, null, $gross, $taxable, $automatic, $userId);
    }
}
