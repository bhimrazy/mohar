<?php

namespace App\Modules\Billing\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\ManagePayMethods;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Http\Requests\Client\PayInvoiceRequest;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Support\ClientPaymentOptions;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pay several invoices at once (mass pay).
 */
class PaymentBatchController extends Controller
{
    public function __construct(private ClientContext $context) {}

    public function store(Request $request, PaymentBatches $batches): RedirectResponse
    {
        $this->context->authorize('billing');
        $data = $request->validate(['invoice_ids' => ['nullable', 'array', 'max:50'], 'invoice_ids.*' => ['integer']]);

        $ids = $data['invoice_ids'] ?? Invoice::query()->where('client_id', $this->context->id())->whereIn('status', InvoiceStatus::payable())->pluck('id')->all();
        $batch = $batches->create($this->context->id(), array_values(array_map('intval', $ids)));

        return to_route('client.invoices.batches.show', $batch->id);
    }

    public function show(int $batch, ClientPaymentOptions $options): Response
    {
        $this->context->authorize('billing');
        $batch = $this->find($batch);

        return Inertia::render('client/billing/invoices/batch', [
            'batch' => ['id' => $batch->id, 'total' => $batch->total, 'currency' => $batch->currency, 'status' => $batch->status],
            'invoices' => InvoiceResource::collection(Invoice::query()->where('client_id', $this->context->id())->whereIn('id', $batch->invoice_ids)->orderBy('due_date')->orderBy('id')->get()),
            'payment' => $batch->status === 'pending' ? $options->forClient($this->context->client(), $batch->currency) : null,
        ]);
    }

    public function pay(PayInvoiceRequest $request, int $batch, PaymentBatches $batches, ClientPaymentOptions $options, ManagePayMethods $payMethods): RedirectResponse
    {
        $this->context->authorize('billing');
        $batch = $this->find($batch);
        [$gateway, $token, $method] = $options->resolveForClient($this->context->client(), $request);

        $result = $batches->pay($batch, $gateway, $token, $method, $request->user()?->id);

        if (! $result->succeeded()) {
            return back()->withErrors(['payment' => ClientPaymentOptions::declineMessage($result->declineReason)]);
        }

        if ($method === null && $request->boolean('save_card')) {
            $payMethods->store($batch->client_id, $gateway, $token);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment received. Thank you.')]);

        return to_route('client.invoices.index');
    }

    private function find(int $id): PaymentBatch
    {
        return PaymentBatch::query()->where('client_id', $this->context->id())->findOrFail($id);
    }
}
