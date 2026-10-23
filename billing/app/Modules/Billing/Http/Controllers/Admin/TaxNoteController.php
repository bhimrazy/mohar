<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Data\NotePreview;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\Admin\TaxNoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\BikramSambatDate;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Staff issue credit and debit notes on a tax invoice, and reverse a credit note (vat-register §5.1, §5.5). The preview
 * is a read; the confirm carries its `version` and `digest`, and the issuer checks both again under the invoice lock.
 */
class TaxNoteController extends Controller
{
    public function __construct(private TaxNoteIssuer $issuer) {}

    /**
     * Whether staff can issue notes on this invoice: Nepal VAT mode on, and a numbered tax invoice. The mode setting
     * (`billing.nepal_vat.enabled`, off when unset) gets its definition and enable checks in VAT PR 3 (ADR 0024 §8).
     */
    public static function issuable(Invoice $invoice): bool
    {
        return app(BillingSettings::class)->bool('nepal_vat.enabled') && $invoice->numbered_at !== null && $invoice->kind !== InvoiceKind::AddFunds;
    }

    public function preview(TaxNoteRequest $request, Invoice $invoice): JsonResponse
    {
        $this->ensureIssuable($invoice);
        $preview = $this->issuer->preview($request->note($invoice));

        return response()->json($this->present($preview));
    }

    public function store(TaxNoteRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureIssuable($invoice);
        $note = $this->issuer->issue($request->note($invoice), $request->integer('version'), (string) $request->input('digest'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':kind note :number issued.', ['kind' => $note->kind === 'credit' ? __('Credit') : __('Debit'), 'number' => $note->number])]);

        return back();
    }

    private function ensureIssuable(Invoice $invoice): void
    {
        if (! self::issuable($invoice)) {
            throw new BillingException(__('Notes can be issued only on a numbered tax invoice while Nepal VAT mode is on.'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotePreview $preview): array
    {
        return [
            'kind' => $preview->kind, 'total' => $preview->total, 'taxable' => $preview->taxable, 'exempt' => $preview->exempt,
            'tax1' => $preview->tax1, 'tax2' => $preview->tax2, 'balance_after' => $preview->balanceAfter, 'settlement' => $preview->settlement,
            'to_credit' => $preview->toCredit, 'version' => $preview->version, 'digest' => $preview->digest,
            'lines' => array_map(fn (array $part): array => ['description' => $part['description'], 'net' => $part['net'], 'tax1' => $part['tax1'], 'tax2' => $part['tax2'], 'gross' => $part['gross']], $preview->parts),
        ];
    }

    /**
     * The invoice page's notes panel: the notes, each with the account credit it posted, and the reversal links.
     *
     * @return list<array<string, mixed>>
     */
    public static function panel(Invoice $invoice): array
    {
        $notes = TaxNote::query()->with('creator:id,name')->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $numbers = $notes->pluck('number', 'id');
        $reversedBy = $notes->whereNotNull('reverses_tax_note_id')->pluck('number', 'reverses_tax_note_id');
        $credit = $invoice->adjustments()->whereNotNull('tax_note_id')->where('reason', 'credit_note_to_credit')->get(['tax_note_id', 'kind', 'amount'])
            ->groupBy('tax_note_id')->map(fn ($rows): int => (int) $rows->sum('amount'));

        return array_values($notes->map(fn (TaxNote $note): array => [
            'id' => $note->id, 'number' => $note->number, 'kind' => $note->kind, 'issue_date' => $note->issue_date->toDateString(),
            'bs_issue_date' => (new BikramSambatDate(...array_map('intval', explode('-', (string) $note->bs_issue_date))))->format(), 'reason_code' => $note->reason_code, 'reason_text' => $note->reason_text, 'total' => $note->total,
            'tax' => $note->tax1_amount + $note->tax2_amount, 'to_credit' => $credit[$note->id] ?? 0, 'automatic' => $note->automatic,
            'issued_by' => $note->creator?->name, 'reverses' => $note->reverses_tax_note_id !== null ? $numbers[$note->reverses_tax_note_id] ?? null : null,
            'reversed_by' => $reversedBy[$note->id] ?? null,
        ])->all());
    }
}
