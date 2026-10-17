<?php

namespace App\Modules\Billing\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\ApplyCredit;
use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Actions\ManagePayMethods;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Http\Requests\Client\AmountRequest;
use App\Modules\Billing\Http\Requests\Client\PayInvoiceRequest;
use App\Modules\Billing\Http\Resources\AdjustmentResource;
use App\Modules\Billing\Http\Resources\InvoiceItemResource;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\TransactionResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\ClientPaymentOptions;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client-area invoices. Every query is scoped to the current client account (never trust ids alone).
 */
class InvoiceController extends Controller
{
    public function __construct(private ClientContext $context) {}

    public function index(Request $request, CreditLedgerService $credit, BillingSettings $settings): Response
    {
        $this->context->authorize('billing');
        $client = $this->context->client();
        $status = $request->string('status')->toString();

        $invoices = $this->invoices()
            ->when($status === 'unpaid', fn ($query) => $query->whereIn('status', InvoiceStatus::payable()))
            ->when($status === 'paid', fn ($query) => $query->where('status', InvoiceStatus::Paid))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $unpaid = $this->invoices()->whereIn('status', InvoiceStatus::payable());

        return Inertia::render('client/billing/invoices/index', [
            'invoices' => InvoiceResource::collection($invoices),
            'filters' => (object) array_filter(['status' => $status]),
            'summary' => [
                'unpaid_count' => (clone $unpaid)->count(),
                'unpaid_total' => (int) (clone $unpaid)->sum('balance'),
                'overdue_count' => (clone $unpaid)->overdue()->count(),
                'currency' => $client->currency,
                'credit' => $credit->balance($client->id, $client->currency)->amount,
            ],
            'mass_pay' => $settings->bool('mass_pay_enabled'),
        ]);
    }

    public function show(int $invoice, ClientPaymentOptions $options): Response
    {
        $this->context->authorize('billing');
        $invoice = $this->find($invoice);
        $invoice->load(['items', 'adjustments']);

        return Inertia::render('client/billing/invoices/show', [
            'invoice' => new InvoiceResource($invoice),
            'items' => InvoiceItemResource::collection($invoice->items),
            'adjustments' => AdjustmentResource::collection($invoice->adjustments),
            'transactions' => TransactionResource::collection($invoice->transactions()->get()),
            'payment' => $invoice->status === InvoiceStatus::Unpaid && $invoice->balance > 0 ? $options->forInvoice($invoice) : null,
        ]);
    }

    public function pdf(int $invoice, InvoicePdf $pdf): StreamedResponse
    {
        $this->context->authorize('billing');
        $invoice = $this->find($invoice);

        return Storage::disk('objects')->download($pdf->ensure($invoice), $pdf->filename($invoice), ['Content-Type' => 'application/pdf']);
    }

    public function pay(PayInvoiceRequest $request, int $invoice, CaptureInvoice $capture, ManagePayMethods $payMethods, ClientPaymentOptions $options): RedirectResponse
    {
        $this->context->authorize('billing');
        $invoice = $this->find($invoice);
        [$gateway, $token, $method] = $options->resolve($invoice, $request);

        $result = $capture->handle($invoice, $gateway, $token, $method, $request->user()?->id);

        if (! $result->succeeded() && ! $result->isPending()) {
            return back()->withErrors(['payment' => ClientPaymentOptions::declineMessage($result->declineReason)]);
        }

        if ($method === null && $request->boolean('save_card')) {
            $payMethods->store($invoice->client_id, $gateway, $token);
        }

        Inertia::flash('toast', $result->isPending()
            ? ['type' => 'info', 'message' => __('Your payment is being processed. We\'ll update the invoice once it clears.')]
            : ['type' => 'success', 'message' => __('Payment received. Thank you.')]);

        return to_route('client.invoices.show', $invoice->id);
    }

    public function applyCredit(AmountRequest $request, int $invoice, ApplyCredit $apply): RedirectResponse
    {
        $this->context->authorize('billing');
        $invoice = $this->find($invoice);
        $apply->handle($invoice, $request->minor('amount', $invoice->currency));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credit applied to invoice :number.', ['number' => $invoice->displayNumber()])]);

        return back();
    }

    /**
     * @return Builder<Invoice>
     */
    private function invoices(): Builder
    {
        return Invoice::query()->where('client_id', $this->context->id())->where('status', '!=', InvoiceStatus::Draft);
    }

    private function find(int $id): Invoice
    {
        return $this->invoices()->findOrFail($id);
    }
}
