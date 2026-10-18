<?php

namespace App\Modules\Billing\Http\Controllers\Client;

use App\Data\Billing\FormPost;
use App\Data\Billing\QrPayload;
use App\Data\Billing\RedirectInstruction;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\ApplyCredit;
use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Actions\ManagePayMethods;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Http\Requests\Client\AmountRequest;
use App\Modules\Billing\Http\Requests\Client\PayInvoiceRequest;
use App\Modules\Billing\Http\Resources\AdjustmentResource;
use App\Modules\Billing\Http\Resources\InvoiceItemResource;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\TransactionResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\ClientPaymentOptions;
use App\Modules\Billing\Support\PaymentInProgress;
use App\Modules\Core\Http\Middleware\SecurityHeaders;
use App\Modules\Core\Support\ClientContext;
use App\Support\Billing\SandboxPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client-area invoices. Every query is scoped to the current client account (never trust ids alone).
 */
class InvoiceController extends Controller
{
    /** Session slots holding provider forms, one per attempt reference (several tabs may pay at once). */
    private const HOSTED_FORM_SESSION = 'billing.hosted_form.';

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

    public function show(int $invoice, ClientPaymentOptions $options, PaymentInProgress $inProgress): Response
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
            'payment_in_progress' => $invoice->status->isPayable() ? $inProgress->forInvoice($invoice) : null,
        ]);
    }

    public function pdf(int $invoice, InvoicePdf $pdf): StreamedResponse
    {
        $this->context->authorize('billing');
        $invoice = $this->find($invoice);

        return Storage::disk('objects')->download($pdf->ensure($invoice), $pdf->filename($invoice), ['Content-Type' => 'application/pdf']);
    }

    public function pay(PayInvoiceRequest $request, int $invoice, CaptureInvoice $capture, ManagePayMethods $payMethods, ClientPaymentOptions $options, StartHostedPayment $start, PaymentInProgress $inProgress): RedirectResponse|SymfonyResponse
    {
        $this->context->authorize('billing');
        $invoice = $this->find($invoice);
        $inProgress->assertCanStart($invoice);

        if ($options->kind($request->string('gateway')->toString()) === 'redirect') {
            return $this->startHosted($invoice, $request->string('gateway')->toString(), $start, $request);
        }

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
     * Hosted gateways: store the attempt and hand the customer to the provider (redirect, auto-posted form or QR).
     */
    private function startHosted(Invoice $invoice, string $gateway, StartHostedPayment $start, Request $request): SymfonyResponse
    {
        [$attempt, $instruction] = $start->handle($invoice, $gateway);

        if ($instruction instanceof RedirectInstruction) {
            return Inertia::location($instruction->url);
        }

        if ($instruction instanceof FormPost) {
            // The SPA document's CSP only allows forms to post to this site (form-action 'self'), so the auto-posted
            // form needs a full page load whose CSP adds exactly the provider's origin (see checkout()).
            $request->session()->put(self::HOSTED_FORM_SESSION.$attempt->reference, ['action' => $instruction->action, 'fields' => $instruction->fields]);

            return Inertia::location(route('client.invoices.checkout', [$invoice->id, $attempt->reference]));
        }

        return $this->renderCheckout($invoice, $attempt, $gateway, null, $instruction)->toResponse($request);
    }

    /**
     * Full-page checkout that auto-posts the provider form of an open attempt of this client's invoice. The form is
     * served once (the slot is cleared), so a refresh never re-posts the same signed form.
     */
    public function checkout(Request $request, int $invoice, string $reference, GatewayManager $gateways): SymfonyResponse
    {
        $this->context->authorize('billing');
        $invoice = $this->find($invoice);
        $attempt = PaymentAttempt::query()
            ->where('client_id', $this->context->id())
            ->where('invoice_id', $invoice->id)
            ->where('reference', $reference)
            ->where('status', AttemptStatus::Pending)
            ->first();
        $form = $request->session()->pull(self::HOSTED_FORM_SESSION.$reference);

        // An attempt opened in sandbox mode before test money was switched off (production) fails closed: its form
        // would send the customer to the provider's test site.
        if ($attempt !== null && ! SandboxPolicy::allowed() && $gateways->isSandbox($attempt->gateway)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This payment method is no longer available. Choose another way to pay.')]);

            return to_route('client.invoices.show', $invoice->id);
        }

        if ($attempt === null || ($attempt->expires_at !== null && $attempt->expires_at->isPast()) || ! is_array($form)
            || ! is_string($form['action'] ?? null) || ! is_array($form['fields'] ?? null)) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('This payment session has ended. Start the payment again.')]);

            return to_route('client.invoices.show', $invoice->id);
        }

        SecurityHeaders::allowFormAction($request, $form['action']);

        /** @var array<string, string> $fields */
        $fields = $form['fields'];

        return $this->renderCheckout($invoice, $attempt, $attempt->gateway, new FormPost($form['action'], $fields), null)->toResponse($request);
    }

    private function renderCheckout(Invoice $invoice, PaymentAttempt $attempt, string $gateway, ?FormPost $form, ?QrPayload $qr): Response
    {
        return Inertia::render('client/billing/invoices/checkout', [
            'invoice' => ['id' => $invoice->id, 'number' => $invoice->displayNumber()],
            'reference' => $attempt->reference,
            'form' => $form !== null ? ['action' => $form->action, 'fields' => (object) $form->fields] : null,
            'qr' => $qr !== null ? ['payload' => $qr->payload, 'expires_at' => $qr->expiresAt->toIso8601String()] : null,
            'status_url' => route('billing.gateways.return', [$gateway, 'ref' => $attempt->reference]),
        ]);
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
