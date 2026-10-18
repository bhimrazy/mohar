<?php

namespace App\Modules\Billing\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Enums\QuoteStage;
use App\Modules\Billing\Http\Resources\QuoteResource;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function __construct(private ClientContext $context, private Quotes $quotes) {}

    public function index(): Response
    {
        $this->context->authorize('billing');

        return Inertia::render('client/billing/quotes/index', [
            'quotes' => QuoteResource::collection($this->scoped()->latest('id')->paginate(15)),
        ]);
    }

    public function show(int $quote, BillingSettings $settings): Response
    {
        $this->context->authorize('billing');

        return Inertia::render('client/billing/quotes/show', [
            'quote' => new QuoteResource($this->find($quote)->load('items')),
            'require_tos' => $settings->bool('quotes.require_tos'),
        ]);
    }

    public function accept(Request $request, int $quote, BillingSettings $settings): RedirectResponse
    {
        $this->context->authorize('billing');

        if ($settings->bool('quotes.require_tos')) {
            $request->validate(['accept_tos' => ['accepted']]);
        }

        $quote = $this->quotes->accept($this->find($quote));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quote accepted. Thank you.')]);

        return $quote->invoice_id !== null ? to_route('client.invoices.show', $quote->invoice_id) : back();
    }

    public function decline(int $quote): RedirectResponse
    {
        $this->context->authorize('billing');
        $this->quotes->decline($this->find($quote));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quote declined.')]);

        return back();
    }

    public function pdf(int $quote, InvoicePdf $pdf): HttpResponse
    {
        $this->context->authorize('billing');
        $quote = $this->find($quote);

        return response($pdf->renderQuote($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="quote-'.$quote->id.'.pdf"',
        ]);
    }

    /**
     * Clients never see drafts.
     *
     * @return Builder<Quote>
     */
    private function scoped(): Builder
    {
        return Quote::query()->where('client_id', $this->context->id())->where('stage', '!=', QuoteStage::Draft);
    }

    private function find(int $id): Quote
    {
        return $this->scoped()->findOrFail($id);
    }
}
