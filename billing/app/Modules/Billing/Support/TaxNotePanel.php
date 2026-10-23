<?php

namespace App\Modules\Billing\Support;

use App\Contracts\Core\CompanyClock;
use App\Models\User;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\TaxNoteIssuer;

/**
 * The admin invoice page's credit and debit notes (vat-register §8): the notes, what can still be credited, why an
 * action is blocked, and the "Credited" label (§5.2). Notes are seen with `billing.tax_notes.view` and issued with
 * `billing.tax_notes.issue`.
 */
class TaxNotePanel
{
    public function __construct(private NepalVatMode $mode, private TaxNoteIssuer $issuer, private CompanyClock $clock) {}

    /**
     * Whether staff can issue notes on this invoice: Nepal VAT mode in effect (switched on and its start day reached), and a
     * numbered tax invoice with its snapshot.
     */
    public function issuable(Invoice $invoice): bool
    {
        return $this->mode->inEffect($this->clock->now()) && $invoice->numbered_at !== null && $invoice->fiscal_total !== null && $invoice->kind !== InvoiceKind::AddFunds;
    }

    /**
     * @return array<string, mixed>
     */
    public function props(Invoice $invoice, ?User $user): array
    {
        $enabled = $this->issuable($invoice);
        $notes = $invoice->numbered_at !== null ? $this->notes($invoice) : [];

        return [
            // Null hides the panel for staff who may not see notes.
            'tax_notes' => ($user?->hasPermission('billing.tax_notes.view') ?? false) ? $notes : null,
            'notes_enabled' => $enabled,
            'creditable' => $enabled ? ['total' => $this->issuer->creditable($invoice), 'lines' => $this->issuer->creditableLines($invoice)] : null,
            'note_blocked' => $enabled ? ['credit' => $this->issuer->creditBlocked($invoice), 'debit' => $this->issuer->debitBlocked($invoice)] : null,
            'credit_label' => $this->creditLabel($invoice, $notes),
        ];
    }

    /**
     * "Credited" when nothing is left to credit, "Partly credited" when unreversed credit notes took part of the value.
     *
     * @param  list<array<string, mixed>>  $notes
     */
    private function creditLabel(Invoice $invoice, array $notes): ?string
    {
        $credited = collect($notes)->filter(fn (array $note): bool => $note['kind'] === 'credit' && $note['reversed_by'] === null)->sum('total');

        if ($credited <= 0) {
            return null;
        }

        return $this->issuer->creditable($invoice) === 0 ? __('Credited') : __('Partly credited');
    }

    /**
     * Each note with the account credit it posted or took back, and the reversal links.
     *
     * @return list<array<string, mixed>>
     */
    private function notes(Invoice $invoice): array
    {
        $notes = TaxNote::query()->with('creator:id,name')->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $numbers = $notes->pluck('number', 'id');
        $reversedBy = $notes->whereNotNull('reverses_tax_note_id')->pluck('number', 'reverses_tax_note_id');
        $credit = $invoice->adjustments()->whereNotNull('tax_note_id')->where('reason', 'credit_note_to_credit')->get(['tax_note_id', 'amount'])
            ->groupBy('tax_note_id')->map(fn ($rows): int => (int) $rows->sum('amount'));

        return array_values($notes->map(fn (TaxNote $note): array => [
            'id' => $note->id, 'number' => $note->number, 'kind' => $note->kind, 'issue_date' => $note->issue_date->toDateString(),
            'bs_issue_date' => (new BikramSambatDate(...array_map('intval', explode('-', $note->bs_issue_date))))->format(),
            'reason_code' => $note->reason_code, 'reason_text' => $note->reason_text, 'total' => $note->total, 'tax' => $note->tax1_amount + $note->tax2_amount,
            'to_credit' => $credit[$note->id] ?? 0, 'automatic' => $note->automatic, 'issued_by' => $note->creator?->name,
            'reverses' => $note->reverses_tax_note_id !== null ? $numbers[$note->reverses_tax_note_id] ?? null : null,
            'reversed_by' => $reversedBy[$note->id] ?? null,
        ])->all());
    }
}
