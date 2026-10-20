<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hosted payment attempts: what the poller is watching and what needs staff review.
 */
class PaymentAttemptController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('billing.payments.manage');
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AttemptStatus::class)],
            'gateway' => ['nullable', 'string', 'max:50'],
            'late' => ['nullable', 'boolean'],
        ]);

        return Inertia::render('admin/billing/payment-attempts/index', [
            'attempts' => PaymentAttempt::query()
                ->with('invoice:id,number,proforma_number')
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('gateway', $gateway))
                ->when((bool) ($filters['late'] ?? false), fn ($query) => $query->whereNotNull('late_payment_at'))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (PaymentAttempt $attempt): array => [
                    'id' => $attempt->id,
                    'reference' => $attempt->reference,
                    'gateway' => $attempt->gateway,
                    'status' => $attempt->status->value,
                    'amount' => $attempt->amount,
                    'currency' => $attempt->currency,
                    'invoice_id' => $attempt->invoice_id,
                    'invoice_number' => $attempt->invoice?->displayNumber(),
                    'provider_txn_id' => $attempt->provider_txn_id,
                    'review_reason' => $attempt->review_reason,
                    'lookup_count' => $attempt->lookup_count,
                    'last_lookup_at' => $attempt->last_lookup_at?->toIso8601String(),
                    'expires_at' => $attempt->expires_at?->toIso8601String(),
                    'created_at' => $attempt->created_at?->toIso8601String(),
                    'late_payment_at' => $attempt->late_payment_at?->toIso8601String(),
                    'late_checked_at' => $attempt->late_checked_at?->toIso8601String(),
                ]),
            'filters' => (object) array_filter($filters),
            'statuses' => array_map(fn (AttemptStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], AttemptStatus::cases()),
        ]);
    }

    public function check(PaymentAttempt $attempt, SettlePaymentAttempt $settle): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        // Staff are waiting on this request: one quick provider try, like the customer's return.
        $status = $settle->handle($attempt, interactive: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attempt :reference is :status.', ['reference' => $attempt->reference, 'status' => $status->value])]);

        return back();
    }

    /**
     * Staff checked a late payment (refunded it at the provider, or kept it as credit): clear its to-do.
     */
    public function lateChecked(PaymentAttempt $attempt, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        abort_if($attempt->late_payment_at === null, 404);

        if ($attempt->late_checked_at === null) {
            $attempt->update(['late_checked_at' => now()]);
            $activity->log('Late payment checked', $attempt, $attempt->client_id, ['reference' => $attempt->reference, 'gateway' => $attempt->gateway], 'billing.payment.late_checked');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Late payment :reference marked as checked.', ['reference' => $attempt->reference])]);

        return back();
    }
}
