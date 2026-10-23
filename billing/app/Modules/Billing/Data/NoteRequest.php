<?php

namespace App\Modules\Billing\Data;

/**
 * What staff or automation ask the note issuer for. A credit note is allocated to lines, either named (`lines`: invoice
 * item id => gross to credit) or by one gross amount spread over the lines. A debit note is one gross amount with no
 * line; a reversal is a debit note that repeats one credit note's lines (`reverses`). `reasonText` is shown to the client.
 *
 * `settlement` is where the part of a credit note above the open balance goes: nowhere (`balance`, refused when there
 * is such a part) or account credit (`credit`). `source` names who issued an automatic note (the activity entry).
 */
final readonly class NoteRequest
{
    public const SETTLEMENTS = ['balance', 'credit'];

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
        public string $settlement = 'balance',
        public ?int $reverses = null,
        public ?string $source = null,
    ) {}

    /**
     * @param  array<int, int>  $lines  invoice item id => gross
     */
    public static function creditLines(int $invoiceId, array $lines, string $reasonCode, string $reasonText, bool $automatic = false, ?int $userId = null, string $settlement = 'balance', ?string $source = null): self
    {
        return new self('credit', $invoiceId, $reasonCode, $reasonText, $lines, null, true, $automatic, $userId, $settlement, source: $source);
    }

    public static function creditAmount(int $invoiceId, int $gross, string $reasonCode, string $reasonText, bool $automatic = false, ?int $userId = null, string $settlement = 'balance', ?string $source = null): self
    {
        return new self('credit', $invoiceId, $reasonCode, $reasonText, null, $gross, true, $automatic, $userId, $settlement, source: $source);
    }

    public static function debit(int $invoiceId, int $gross, bool $taxable, string $reasonCode, string $reasonText, bool $automatic = false, ?int $userId = null, ?string $source = null): self
    {
        return new self('debit', $invoiceId, $reasonCode, $reasonText, null, $gross, $taxable, $automatic, $userId, source: $source);
    }

    /** A debit note that reverses credit note `$creditNoteId` of the invoice, line for line. */
    public static function reversal(int $invoiceId, int $creditNoteId, string $reasonText, ?int $userId = null): self
    {
        return new self('debit', $invoiceId, 'reverses_credit_note', $reasonText, null, null, true, false, $userId, reverses: $creditNoteId);
    }
}
