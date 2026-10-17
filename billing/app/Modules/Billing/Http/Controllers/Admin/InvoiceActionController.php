<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Events\Billing\InvoicePaymentReminder;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\ApplyCredit;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\CancelInvoiceItems;
use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Actions\MarkInvoicePaid;
use App\Modules\Billing\Actions\PublishInvoice;
use App\Modules\Billing\Actions\RemoveCredit;
use App\Modules\Billing\Actions\ReopenInvoice;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\Admin\AmountRequest;
use App\Modules\Billing\Http\Requests\Admin\RecordPaymentRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Core\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff actions on a single invoice and bulk actions on the list.
 */
class InvoiceActionController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function publish(Invoice $invoice, PublishInvoice $publish): RedirectResponse
    {
        $this->authorize('billing.invoices.manage');
        $invoice = $publish->handle($invoice);
        $this->activity->log('Invoice published', $invoice, $invoice->client_id);

        return $this->done(__('Invoice :number published.', ['number' => $invoice->displayNumber()]));
    }

    public function cancel(Request $request, Invoice $invoice, CancelInvoice $cancel): RedirectResponse
    {
        $this->authorize('billing.invoices.manage');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $cancel->handle($invoice, $request->user()?->id, $note);
        $this->activity->log('Invoice cancelled', $invoice, $invoice->client_id);

        return $this->done(__('Invoice :number cancelled.', ['number' => $invoice->displayNumber()]));
    }

    public function reopen(Request $request, Invoice $invoice, ReopenInvoice $reopen): RedirectResponse
    {
        $this->authorize('billing.invoices.manage');
        $reopen->handle($invoice, $request->user()?->id);

        return $this->done(__('Invoice :number reopened.', ['number' => $invoice->displayNumber()]));
    }

    public function remind(Invoice $invoice): RedirectResponse
    {
        $this->authorize('billing.invoices.manage');

        if (! $invoice->status->isPayable()) {
            throw new BillingException(__('Reminders can only be sent for unpaid invoices.'));
        }

        InvoicePaymentReminder::dispatch($invoice->id, $invoice->client_id, $invoice->isOverdue() ? 'overdue_1' : 'due');
        $this->activity->log('Payment reminder sent', $invoice, $invoice->client_id);

        return $this->done(__('Reminder for invoice :number sent.', ['number' => $invoice->displayNumber()]));
    }

    public function recordPayment(RecordPaymentRequest $request, Invoice $invoice, ApplyPayment $apply): RedirectResponse
    {
        if (! $invoice->status->isPayable()) {
            throw new BillingException(__('Payments can only be recorded on unpaid invoices.'));
        }

        $apply->handle($invoice, new PaymentData(
            amount: $request->minor('amount', $invoice->currency),
            gateway: $request->string('gateway')->toString(),
            gatewayTxnId: $request->validated('gateway_txn_id'),
            fee: $request->minor('fee', $invoice->currency),
            createdBy: $request->user()?->id,
            occurredAt: $request->filled('occurred_at') ? CarbonImmutable::parse($request->string('occurred_at')->toString()) : null,
            description: $request->validated('description'),
        ));
        $this->activity->log('Payment recorded', $invoice, $invoice->client_id, ['amount' => $request->minor('amount')]);

        return $this->done(__('Payment recorded on invoice :number.', ['number' => $invoice->displayNumber()]));
    }

    public function markPaid(Request $request, Invoice $invoice, MarkInvoicePaid $markPaid): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        $markPaid->handle($invoice, $request->user()?->id);
        $this->activity->log('Invoice marked as paid', $invoice, $invoice->client_id);

        return $this->done(__('Invoice :number marked as paid.', ['number' => $invoice->displayNumber()]));
    }

    public function capture(Request $request, Invoice $invoice, CaptureInvoice $capture): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        $data = $request->validate(['pay_method_id' => ['required', 'integer']]);
        $method = PayMethod::query()->where('client_id', $invoice->client_id)->usable()->whereKey($data['pay_method_id'])->firstOrFail();

        $result = $capture->handle($invoice, $method->gateway, $method->token, $method, $request->user()?->id);

        if (! $result->succeeded() && ! $result->isPending()) {
            throw new BillingException(__('Capture failed: :reason.', ['reason' => str_replace('_', ' ', $result->declineReason ?? $result->status)]));
        }

        return $this->done($result->isPending()
            ? __('The payment is pending settlement at the gateway.')
            : __('Payment captured for invoice :number.', ['number' => $invoice->displayNumber()]));
    }

    public function cancelLines(Request $request, Invoice $invoice, CancelInvoiceItems $cancel): RedirectResponse
    {
        $this->authorize('billing.invoices.manage');
        $data = $request->validate(['items' => ['required', 'array', 'min:1'], 'items.*' => ['integer']]);
        $cancel->handle($invoice, array_values(array_map('intval', $data['items'])), $request->user()?->id);

        return $this->done(__('Selected lines cancelled.'));
    }

    public function applyCredit(AmountRequest $request, Invoice $invoice, ApplyCredit $apply): RedirectResponse
    {
        $apply->handle($invoice, $request->minor('amount', $invoice->currency), $request->user()?->id);

        return $this->done(__('Credit applied to invoice :number.', ['number' => $invoice->displayNumber()]));
    }

    public function removeCredit(AmountRequest $request, Invoice $invoice, RemoveCredit $remove): RedirectResponse
    {
        $remove->handle($invoice, $request->minor('amount', $invoice->currency), $request->user()?->id);

        return $this->done(__('Credit removed from invoice :number.', ['number' => $invoice->displayNumber()]));
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): StreamedResponse
    {
        $this->authorize('billing.invoices.view');

        return Storage::disk('objects')->download($pdf->ensure($invoice), $pdf->filename($invoice), ['Content-Type' => 'application/pdf']);
    }

    /**
     * Bulk mark paid / cancel / send reminder from the invoice list.
     */
    public function bulk(Request $request, MarkInvoicePaid $markPaid, CancelInvoice $cancel): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['mark_paid', 'cancel', 'remind'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $this->authorize($data['action'] === 'mark_paid' ? 'billing.payments.manage' : 'billing.invoices.manage');

        $done = 0;

        foreach (Invoice::query()->whereIn('id', $data['ids'])->whereIn('status', InvoiceStatus::payable())->orderBy('id')->get() as $invoice) {
            try {
                match ($data['action']) {
                    'mark_paid' => $markPaid->handle($invoice, $request->user()?->id),
                    'cancel' => $cancel->handle($invoice, $request->user()?->id),
                    default => InvoicePaymentReminder::dispatch($invoice->id, $invoice->client_id, $invoice->isOverdue() ? 'overdue_1' : 'due'),
                };
                $done++;
            } catch (BillingException) {
                continue;
            }
        }

        return $this->done(trans_choice('{0} No invoices were changed.|{1} 1 invoice updated.|[2,*] :count invoices updated.', $done, ['count' => $done]));
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
