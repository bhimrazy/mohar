<?php

namespace App\Modules\Billing\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Customer's browser coming back from a hosted payment page (GET or POST). The parameters are hints only: we load
 * the attempt by reference and gateway and settle it from an authoritative lookup().
 */
class GatewayReturnController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayManager $gateways, SettlePaymentAttempt $settle): RedirectResponse
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

        // At most one provider lookup per attempt per minute from this anonymous route; otherwise reuse the last result.
        $status = $attempt->last_lookup_at !== null && $attempt->last_lookup_at->gt(now()->subMinute())
            ? $attempt->status
            : $settle->handle($attempt);

        Inertia::flash('toast', match ($status) {
            AttemptStatus::Succeeded => ['type' => 'success', 'message' => __('Payment received. Thank you.')],
            AttemptStatus::Failed => ['type' => 'error', 'message' => __('The payment didn\'t go through. Try again or use another method.')],
            AttemptStatus::Expired => ['type' => 'warning', 'message' => __('The payment session expired. Start a new payment.')],
            default => ['type' => 'info', 'message' => __('Your payment is being confirmed. We\'ll update the invoice shortly.')],
        });

        return $attempt->invoice_id !== null ? to_route('client.invoices.show', $attempt->invoice_id) : to_route('client.invoices.index');
    }
}
