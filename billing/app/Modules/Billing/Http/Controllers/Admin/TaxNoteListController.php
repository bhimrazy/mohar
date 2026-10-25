<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Events\Billing\TaxNoteResent;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing → Credit notes (vat-register §8): every credit and debit note with filters, the note itself, and a resend of
 * its email. Seen with `billing.tax_notes.view`; a resend needs `billing.tax_notes.resend`. The list reads `tax_notes`
 * through its `issue_date`, `bs_period` and `client_id` indexes and always pages.
 */
class TaxNoteListController extends Controller
{
    private const SETTLEMENTS = ['balance' => 'Invoice balance', 'credit' => 'Account credit', 'refund' => 'Refund to the payer'];

    public function index(Request $request): Response
    {
        $this->authorize('billing.tax_notes.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', 'in:credit,debit'],
            'reason' => ['nullable', 'string', 'max:30'],
            'settlement' => ['nullable', 'in:'.implode(',', array_keys(self::SETTLEMENTS))],
            'automatic' => ['nullable', 'in:0,1'],
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'client' => ['nullable', 'integer'],
        ]);

        $notes = $this->filtered(TaxNote::query(), $filters)
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
            'settlements' => array_map(fn (string $label, string $value): array => ['value' => $value, 'label' => __($label)], self::SETTLEMENTS, array_keys(self::SETTLEMENTS)),
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
                ...$this->row($taxNote, $this->settlements([$taxNote->id])),
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

        event(new TaxNoteResent($taxNote->id, $taxNote->kind, $taxNote->invoice_id, $taxNote->client_id));
        $activity->log(__('Resend of :kind :number requested', ['kind' => $taxNote->kindLabel(), 'number' => $taxNote->number]), $taxNote, $taxNote->client_id,
            ['number' => $taxNote->number], 'billing.tax_note.resend_requested');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Resend of :number requested. The email log shows when it is sent.', ['number' => $taxNote->number])]);

        return back();
    }

    /**
     * @param  Builder<TaxNote>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<TaxNote>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        $term = trim((string) ($filters['search'] ?? ''));
        $term = addcslashes($term, '%_\\');

        return $query
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereLike('number', "{$term}%")->orWhereLike('invoice_number', "{$term}%")))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['reason'] ?? null, fn (Builder $query, string $reason) => $query->where('reason_code', $reason))
            ->when(($filters['automatic'] ?? null) !== null, fn (Builder $query) => $query->where('automatic', $filters['automatic'] === '1'))
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $query->where('bs_period', $period))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('issue_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('issue_date', '<=', $to))
            ->when($filters['client'] ?? null, fn (Builder $query, int|string $client) => $query->where('client_id', (int) $client))
            ->when($filters['settlement'] ?? null, fn (Builder $query, string $settlement) => match ($settlement) {
                'refund' => $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('transactions')->whereColumn('transactions.tax_note_id', 'tax_notes.id')),
                'credit' => $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoice_adjustments')->whereColumn('invoice_adjustments.tax_note_id', 'tax_notes.id')->where('reason', 'credit_note_to_credit')),
                default => $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoice_adjustments')->whereColumn('invoice_adjustments.tax_note_id', 'tax_notes.id')->whereIn('reason', ['credit_note', 'debit_note'])),
            });
    }

    /**
     * @param  LengthAwarePaginator<int, TaxNote>  $notes
     * @return array<string, mixed>
     */
    private function page(LengthAwarePaginator $notes): array
    {
        $settlements = $this->settlements($notes->getCollection()->map(fn (TaxNote $note): int => $note->id)->values()->all());

        return [...$notes->toArray(), 'data' => $notes->getCollection()->map(fn (TaxNote $note): array => $this->row($note, $settlements))->values()->all()];
    }

    /**
     * How each note was settled: against the invoice balance, to account credit, refunded to the payer. A note can be more than one.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function settlements(array $ids): array
    {
        $found = array_fill_keys($ids, []);

        foreach (DB::table('invoice_adjustments')->whereIn('tax_note_id', $ids)->get(['tax_note_id', 'reason']) as $row) {
            $kind = $row->reason === 'credit_note_to_credit' ? 'credit' : 'balance';
            $found[(int) $row->tax_note_id][$kind] = $kind;
        }

        foreach (DB::table('transactions')->whereIn('tax_note_id', $ids)->distinct()->pluck('tax_note_id') as $id) {
            $found[(int) $id]['refund'] = 'refund';
        }

        return array_map(fn (array $kinds): array => array_values(array_map(fn (string $kind): string => (string) __(self::SETTLEMENTS[$kind]), $kinds)), $found);
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
