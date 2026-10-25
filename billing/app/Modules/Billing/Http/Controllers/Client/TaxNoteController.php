<?php

namespace App\Modules\Billing\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Services\DocumentDownloads;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The client's credit and debit notes (vat-register §8, AC 35): a list and each note's PDF. Every query is scoped to
 * the current client account, so another account's note answers 404. Clients never create notes.
 */
class TaxNoteController extends Controller
{
    public function __construct(private ClientContext $context) {}

    public function index(): Response
    {
        $this->context->authorize('billing');
        $notes = TaxNote::query()->where('client_id', $this->context->id())->orderByDesc('issue_date')->orderByDesc('id')->paginate(25);

        return Inertia::render('client/billing/notes/index', [
            'notes' => [...$notes->toArray(), 'data' => self::rows($notes->getCollection())],
        ]);
    }

    public function pdf(int $note, DocumentDownloads $downloads): SymfonyResponse
    {
        $this->context->authorize('billing');

        return $downloads->note(TaxNote::query()->where('client_id', $this->context->id())->findOrFail($note));
    }

    /**
     * What the client sees of each note: no staff names; the ids are only for its links.
     *
     * @param  Collection<int, TaxNote>  $notes
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $notes): array
    {
        return array_values($notes->map(fn (TaxNote $note): array => [
            'id' => $note->id, 'number' => $note->number, 'kind' => $note->kind, 'kind_label' => $note->kindLabel(),
            'invoice_id' => $note->invoice_id, 'invoice_number' => $note->invoice_number,
            'issue_date' => $note->issue_date->toDateString(), 'issue_date_bs' => TaxNote::bsLabel($note->bs_issue_date),
            'reason' => $note->reasonLabel(), 'reason_text' => $note->reason_text, 'total' => $note->total, 'currency' => $note->currency,
        ])->all());
    }
}
