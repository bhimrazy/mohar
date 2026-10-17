<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
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
        ]);

        return Inertia::render('admin/billing/payment-attempts/index', [
            'attempts' => PaymentAttempt::query()
                ->with('invoice:id,number,proforma_number')
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('gateway', $gateway))
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
                ]),
            'filters' => (object) array_filter($filters),
            'statuses' => array_map(fn (AttemptStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], AttemptStatus::cases()),
        ]);
    }

    public function check(PaymentAttempt $attempt, SettlePaymentAttempt $settle): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        $status = $settle->handle($attempt);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attempt :reference is :status.', ['reference' => $attempt->reference, 'status' => $status->value])]);

        return back();
    }
}
