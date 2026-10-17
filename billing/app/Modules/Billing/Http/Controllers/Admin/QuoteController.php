<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Enums\QuoteStage;
use App\Modules\Billing\Http\Requests\Admin\QuoteRequest;
use App\Modules\Billing\Http\Resources\QuoteResource;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function __construct(private Quotes $quotes) {}

    public function index(Request $request): Response
    {
        $this->authorize('billing.quotes.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', Rule::enum(QuoteStage::class)],
            'client' => ['nullable', 'integer'],
        ]);

        $quotes = Quote::query()
            ->with('client:id,first_name,last_name,company_name')
            ->when($filters['search'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query->whereLike('number', "%{$term}%")->orWhereLike('subject', "%{$term}%")))
            ->when($filters['stage'] ?? null, fn ($query, $stage) => $query->where('stage', $stage))
            ->when($filters['client'] ?? null, fn ($query, $client) => $query->where('client_id', $client))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/billing/quotes/index', [
            'quotes' => QuoteResource::collection($quotes),
            'filters' => (object) array_filter($filters),
            'stages' => array_map(fn (QuoteStage $stage): array => ['value' => $stage->value, 'label' => ucfirst(str_replace('_', ' ', $stage->value))], QuoteStage::cases()),
            'can' => ['manage' => $request->user()?->hasPermission('billing.quotes.manage') ?? false],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('billing.quotes.manage');

        return Inertia::render('admin/billing/quotes/form', [
            'quote' => null,
            'defaults' => ['client_id' => $request->integer('client') ?: null],
            'clients' => $this->clients(),
        ]);
    }

    public function store(QuoteRequest $request): RedirectResponse
    {
        $quote = $this->quotes->save($request->quote());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quote created.')]);

        return to_route('admin.billing.quotes.show', $quote);
    }

    public function show(Request $request, Quote $quote): Response
    {
        $this->authorize('billing.quotes.view');

        return Inertia::render('admin/billing/quotes/show', [
            'quote' => new QuoteResource($quote->load(['client', 'items'])),
            'can' => ['manage' => $request->user()?->hasPermission('billing.quotes.manage') ?? false],
        ]);
    }

    public function edit(Quote $quote): Response
    {
        $this->authorize('billing.quotes.manage');

        return Inertia::render('admin/billing/quotes/form', [
            'quote' => new QuoteResource($quote->load('items')),
            'defaults' => ['client_id' => $quote->client_id],
            'clients' => $this->clients(),
        ]);
    }

    public function update(QuoteRequest $request, Quote $quote): RedirectResponse
    {
        $this->quotes->save($request->quote(), $quote);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quote saved.')]);

        return to_route('admin.billing.quotes.show', $quote);
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        $this->authorize('billing.quotes.manage');

        if ($quote->stage !== QuoteStage::Draft) {
            return back()->withErrors(['quote' => __('Only draft quotes can be deleted.')]);
        }

        $quote->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quote deleted.')]);

        return to_route('admin.billing.quotes.index');
    }

    public function deliver(Quote $quote): RedirectResponse
    {
        $this->authorize('billing.quotes.manage');
        $this->quotes->deliver($quote);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quote :number sent to the client.', ['number' => $quote->displayNumber()])]);

        return back();
    }

    public function convert(Quote $quote): RedirectResponse
    {
        $this->authorize('billing.quotes.manage');
        $invoice = $this->quotes->convert($quote);
        app(ActivityLogger::class)->log('Quote converted to invoice', $quote, $quote->client_id, ['invoice' => $invoice->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice :number created from the quote.', ['number' => $invoice->displayNumber()])]);

        return to_route('admin.billing.invoices.show', $invoice);
    }

    public function pdf(Quote $quote, InvoicePdf $pdf): HttpResponse
    {
        $this->authorize('billing.quotes.view');

        return response($pdf->renderQuote($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="quote-'.$quote->id.'.pdf"',
        ]);
    }

    /**
     * @return Collection<int, array{id: int, display_name: string, currency: string}>
     */
    private function clients(): Collection
    {
        return Client::query()->where('status', '!=', 'closed')->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(500)
            ->get(['id', 'first_name', 'last_name', 'company_name', 'currency'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'display_name' => $client->display_name, 'currency' => $client->currency]);
    }
}
