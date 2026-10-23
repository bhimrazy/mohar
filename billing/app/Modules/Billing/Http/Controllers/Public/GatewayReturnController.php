<?php

namespace App\Modules\Billing\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\PaymentReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Customer's browser coming back from a hosted payment page (GET or POST). The parameters are hints only: we load
 * the attempt by reference and gateway and settle it from an authoritative lookup().
 */
class GatewayReturnController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayManager $gateways, SettlePaymentAttempt $settle, PaymentReconciler $reconciler): RedirectResponse
    {
        $driver = $gateways->hosted($gateway);
        abort_if($driver === null || ! $gateways->isActive($gateway), 404);

        $hint = $driver->parseReturn($gateways->config($gateway), $request);
        $attempt = $hint->reference !== null
            ? PaymentAttempt::query()->where('gateway', $gateway)->where('reference', $hint->reference)->first()
            : null;

        if ($attempt === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We couldn\'t match this payment. If you were charged, contact us.')]);

            return to_route('client.invoices.index');
        }

        // A closed attempt is looked up again (for a late payment) only inside the late window, and not once the
        // provider said its session can never be paid: this route is anonymous.
        if ($attempt->status->isClosed() && ($attempt->lookups_closed_at !== null || ! $reconciler->inLateWindow($attempt))) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('This payment session has ended. Start the payment again.')]);

            return $attempt->invoice_id !== null ? to_route('client.invoices.show', $attempt->invoice_id) : to_route('client.invoices.index');
        }

        // At most one provider lookup per attempt per minute from this anonymous route; otherwise reuse the last result.
        // A cancel return for a still-pending attempt may look again after ten seconds, so a poll made while the
        // customer was on the provider's page doesn't keep a cancelled payment open. The cancel flag is only a hint;
        // the lookup decides.
        // The customer is waiting, so the lookup is a single quick try: anything inconclusive is left to the poller.
        $cancelReturn = $request->boolean('cancelled');
        $floor = $cancelReturn && $attempt->status === AttemptStatus::Pending ? now()->subSeconds(10) : now()->subMinute();
        $status = $attempt->last_lookup_at !== null && $attempt->last_lookup_at->gt($floor)
            ? $attempt->status
            : $settle->handle($attempt, interactive: true, cancelReturn: $cancelReturn, returnHint: $hint);

        Inertia::flash('toast', match ($status) {
            AttemptStatus::Succeeded => ['type' => 'success', 'message' => __('Payment received. Thank you.')],
            AttemptStatus::Failed => $cancelReturn
                ? ['type' => 'info', 'message' => __('Payment cancelled. You can try again or choose another way to pay.')]
                : ['type' => 'error', 'message' => __('The payment didn\'t go through. Try again or use another method.')],
            AttemptStatus::Expired => ['type' => 'warning', 'message' => __('The payment session expired. Start a new payment.')],
            default => ['type' => 'info', 'message' => __('Your payment is being confirmed. We\'ll update the invoice shortly.')],
        });

        return $attempt->invoice_id !== null ? to_route('client.invoices.show', $attempt->invoice_id) : to_route('client.invoices.index');
    }
}
