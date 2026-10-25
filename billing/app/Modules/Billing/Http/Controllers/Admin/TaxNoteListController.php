<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Events\Billing\TaxNoteResendRequested;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Support\TaxNoteList;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing → Credit notes (vat-register §8): every credit and debit note with filters, the note itself, and a resend of
 * its email. Seen with `billing.tax_notes.view`; a resend needs `billing.tax_notes.resend`. The list reads `tax_notes`
 * through its `issue_date`, `bs_period` and `client_id` indexes and always pages.
 */
class TaxNoteListController extends Controller
{
    public function __construct(private TaxNoteList $list) {}

    public function index(Request $request): Response
    {
        $this->authorize('billing.tax_notes.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', 'in:credit,debit'],
            'reason' => ['nullable', 'string', 'max:30'],
            'settlement' => ['nullable', 'in:'.implode(',', array_keys(TaxNoteList::SETTLEMENTS))],
            'automatic' => ['nullable', 'in:0,1'],
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'client' => ['nullable', 'integer'],
        ]);

        $notes = $this->list->filtered(TaxNote::query(), $filters)
            ->with('client:id,first_name,last_name,company_name')
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $client = isset($filters['client']) ? Client::query()->find((int) $filters['client']) : null;
        $user = $request->user();

        return Inertia::render('admin/billing/notes/index', [
            'notes' => $this->page($notes),
            'filters' => (object) array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'kinds' => [['value' => 'credit', 'label' => __('Credit notes')], ['value' => 'debit', 'label' => __('Debit notes')]],
            'reasons' => TaxNote::reasonOptions(),
            'settlements' => array_map(fn (string $label, string $value): array => ['value' => $value, 'label' => __($label)], TaxNoteList::SETTLEMENTS, array_keys(TaxNoteList::SETTLEMENTS)),
            'periods' => TaxNote::query()->select('bs_period')->distinct()->orderByDesc('bs_period')->limit(36)->pluck('bs_period')
                ->map(fn (string $period): array => ['value' => $period, 'label' => TaxNote::bsMonthLabel($period)])->all(),
            'client' => $client === null ? null : ['id' => $client->id, 'display_name' => $client->display_name],
            'can' => ['resend' => $user?->hasPermission('billing.tax_notes.resend') ?? false],
        ]);
    }

    public function show(Request $request, TaxNote $taxNote): Response
    {
        $this->authorize('billing.tax_notes.view');
        $taxNote->load(['client:id,first_name,last_name,company_name', 'creator:id,name', 'lines']);

        return Inertia::render('admin/billing/notes/show', [
            'note' => [
                ...$this->row($taxNote, $this->list->settlements([$taxNote->id])),
                'bs_issue_date' => TaxNote::bsLabel($taxNote->bs_issue_date), 'reason_text' => $taxNote->reason_text,
                'taxable' => $taxNote->taxable_amount, 'exempt' => $taxNote->exempt_amount, 'tax' => $taxNote->tax1_amount + $taxNote->tax2_amount,
                'issued_by' => $taxNote->automatic ? null : $taxNote->creator?->name, 'print_count' => $taxNote->print_count,
                'reverses' => $taxNote->reverses_tax_note_id === null ? null : TaxNote::query()->whereKey($taxNote->reverses_tax_note_id)->value('number'),
                'lines' => $taxNote->lines->map(fn ($line): array => ['id' => $line->id, 'description' => $line->description, 'quantity' => $line->quantity, 'amount' => $line->amount, 'tax' => $line->tax1_amount + $line->tax2_amount])->all(),
            ],
            'can' => ['resend' => $request->user()?->hasPermission('billing.tax_notes.resend') ?? false],
        ]);
    }

    /** The same email again, with the PDF attached when the setting says so; the original is untouched. */
    public function resend(TaxNote $taxNote, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('billing.tax_notes.resend');

        event(new TaxNoteResendRequested($taxNote->id, $taxNote->invoice_id, $taxNote->client_id));
        $activity->log(__('Resend of :kind :number requested', ['kind' => $taxNote->kindLabel(), 'number' => $taxNote->number]), $taxNote, $taxNote->client_id,
            ['number' => $taxNote->number], 'billing.tax_note.resend_requested');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Resend of :number requested. The email log shows when it is sent.', ['number' => $taxNote->number])]);

        return back();
    }

    /**
     * @param  LengthAwarePaginator<int, TaxNote>  $notes
     * @return array<string, mixed>
     */
    private function page(LengthAwarePaginator $notes): array
    {
        $settlements = $this->list->settlements($notes->getCollection()->map(fn (TaxNote $note): int => $note->id)->all());

        return [...$notes->toArray(), 'data' => $notes->getCollection()->map(fn (TaxNote $note): array => $this->row($note, $settlements))->values()->all()];
    }

    /**
     * @param  array<int, list<string>>  $settlements
     * @return array<string, mixed>
     */
    private function row(TaxNote $note, array $settlements): array
    {
        return [
            'id' => $note->id, 'number' => $note->number, 'kind' => $note->kind, 'kind_label' => $note->kindLabel(),
            'invoice_id' => $note->invoice_id, 'invoice_number' => $note->invoice_number,
            'client' => ['id' => $note->client->id, 'display_name' => $note->client->display_name],
            'issue_date' => $note->issue_date->toDateString(), 'issue_date_bs' => TaxNote::bsLabel($note->bs_issue_date),
            'reason' => $note->reasonLabel(), 'total' => $note->total, 'currency' => $note->currency,
            'settlement' => $settlements[$note->id] ?? [], 'automatic' => $note->automatic,
        ];
    }
}
