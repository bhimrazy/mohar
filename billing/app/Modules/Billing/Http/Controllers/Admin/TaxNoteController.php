<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Data\NotePreview;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\Admin\TaxNoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Services\DocumentDownloads;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\TaxNotePanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff issue credit and debit notes on a tax invoice, and reverse a credit note (vat-register §5.1, §5.5). The preview
 * is a read; the confirm carries its `version` and `digest`, and the issuer checks both again under the invoice lock.
 */
class TaxNoteController extends Controller
{
    public function __construct(private TaxNoteIssuer $issuer, private TaxNotePanel $panel) {}

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

    /** The note's PDF; each download is a print (DocumentDownloads). */
    public function pdf(TaxNote $taxNote, DocumentDownloads $downloads): Response
    {
        $this->authorize('billing.tax_notes.view');

        return $downloads->note($taxNote);
    }

    private function ensureIssuable(Invoice $invoice): void
    {
        if (($reason = $this->panel->unavailable($invoice)) !== null) {
            throw new BillingException($reason);
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
            'to_credit' => $preview->toCredit, 'due_date' => $preview->dueDate, 'credit_after' => $preview->creditAfter, 'version' => $preview->version, 'digest' => $preview->digest,
            'lines' => array_map(fn (array $part): array => ['description' => $part['description'], 'net' => $part['net'], 'tax1' => $part['tax1'], 'tax2' => $part['tax2'], 'gross' => $part['gross']], $preview->parts),
        ];
    }
}
