<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\RecordManualTransaction;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Http\Requests\Admin\ManualTransactionRequest;
use App\Modules\Billing\Http\Requests\Admin\RefundRequest;
use App\Modules\Billing\Http\Resources\TransactionResource;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(Request $request, GatewayManager $gateways): Response
    {
        $this->authorize('billing.transactions.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'client' => ['nullable', 'integer'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'review' => ['nullable', 'boolean'],
            'waiting' => ['nullable', 'boolean'],
        ]);

        $transactions = (($filters['review'] ?? false) ? Transaction::toReview() : (($filters['waiting'] ?? false) ? Transaction::refundsAwaitingProvider(0) : Transaction::query()))
            ->with(['client:id,first_name,last_name,company_name', 'invoice:id,number,proforma_number'])
            ->when($filters['search'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query->whereLike('gateway_txn_id', "%{$term}%")->orWhereLike('description', "%{$term}%")))
            ->when($filters['client'] ?? null, fn ($query, $client) => $query->where('client_id', $client))
            ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('gateway', $gateway))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('occurred_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('occurred_at', '<', CarbonImmutable::parse($to)->addDay()->startOfDay()))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $client = isset($filters['client']) ? Client::query()->find($filters['client']) : null;

        return Inertia::render('admin/billing/transactions/index', [
            'transactions' => TransactionResource::collection($transactions),
            'filters' => (object) array_filter($filters),
            'types' => array_map(fn (TransactionType $type): array => ['value' => $type->value, 'label' => ucfirst(str_replace('_', ' ', $type->value))], TransactionType::cases()),
            'gateways' => array_values(array_map(fn ($driver): array => ['value' => $driver->key(), 'label' => $gateways->gateway($driver->key())->display_name], $gateways->drivers())),
            'client' => $client?->only(['id', 'display_name', 'currency']),
            'refund_modes' => array_map(fn (RefundMode $mode): string => $mode->value, RefundMode::cases()),
            'can' => [
                'manage' => $request->user()?->hasPermission('billing.transactions.manage') ?? false,
                'refunds' => $request->user()?->hasPermission('billing.refunds.manage') ?? false,
                'review' => $request->user()?->hasPermission('billing.payments.manage') ?? false,
            ],
        ]);
    }

    public function store(ManualTransactionRequest $request, RecordManualTransaction $record): RedirectResponse
    {
        $client = Client::query()->findOrFail($request->integer('client_id'));

        $transactions = $record->handle(
            $client,
            TransactionType::from($request->string('type')->toString()),
            $request->minor('amount', $client->currency),
            $request->string('gateway')->toString(),
            $request->validated('gateway_txn_id'),
            $request->validated('description'),
            CarbonImmutable::parse($request->string('occurred_at')->toString()),
            array_values(array_map('intval', $request->validated('invoice_ids', []))),
            $request->minor('fee', $client->currency),
            $request->user()?->id,
        );

        $this->activity->log('Manual transaction recorded', $transactions[0] ?? null, $client->id, [
            'type' => $request->string('type')->toString(), 'amount' => $request->minor('amount', $client->currency), 'transactions' => array_map(fn ($transaction) => $transaction->id, $transactions),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction recorded.')]);

        return back();
    }

    public function refund(RefundRequest $request, Transaction $transaction, RefundTransaction $refund): RedirectResponse
    {
        $refundRow = $refund->handle(
            $transaction,
            $request->minor('amount', $transaction->currency),
            RefundMode::from($request->string('mode')->toString()),
            $request->boolean('reverse_effects'),
            $request->user()?->id,
            reason: $request->validated('description'),
        );

        $this->activity->log('Refund issued', $refundRow, $transaction->client_id, [
            'payment' => $transaction->id, 'amount' => $refundRow->amount_out, 'mode' => $request->string('mode')->toString(), 'reverse_effects' => $request->boolean('reverse_effects'),
        ]);

        if ($refundRow->status === TransactionStatus::Pending) {
            // The provider hasn't confirmed it and may already have refunded: no retry, the callback settles it.
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The refund is being confirmed with the provider.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund recorded.')]);

        return back();
    }

    /**
     * Ask the provider about a refund that is waiting for it. Its answer settles or releases the same refund row.
     */
    public function checkRefund(Transaction $transaction, RefundTransaction $refund): RedirectResponse
    {
        $this->authorize('billing.refunds.manage');
        abort_unless($transaction->type === TransactionType::Refund && $transaction->status === TransactionStatus::Pending, 404);

        $row = $refund->check($transaction);
        $this->activity->log('Refund checked with the provider', $row, $row->client_id, ['status' => $row->status->value, 'reference' => $row->gateway_txn_id]);

        Inertia::flash('toast', match ($row->status) {
            TransactionStatus::Succeeded => ['type' => 'success', 'message' => __('The provider confirmed the refund.')],
            TransactionStatus::Failed => ['type' => 'success', 'message' => __('The provider has no such refund. The amount can be refunded again.')],
            default => ['type' => 'info', 'message' => __('The provider has not confirmed the refund yet. Try again in a few minutes.')],
        });

        return back();
    }

    /**
     * Staff settle a waiting refund by hand, for a provider that can't be asked: it was made there (with the
     * provider's reference, which the books keep), or it was not.
     */
    public function resolveRefund(Request $request, Transaction $transaction, RefundTransaction $refund): RedirectResponse
    {
        $this->authorize('billing.refunds.manage');
        abort_unless($transaction->type === TransactionType::Refund && $transaction->status === TransactionStatus::Pending, 404);
        $data = $request->validate([
            'outcome' => ['required', 'in:confirmed,not_refunded'],
            'provider_reference' => ['required_if:outcome,confirmed', 'nullable', 'string', 'max:191'],
        ]);
        $confirmed = $data['outcome'] === 'confirmed';

        $row = $refund->resolve($transaction, $confirmed, $data['provider_reference'] ?? null);
        $this->activity->log($confirmed ? 'Refund confirmed at the provider by staff' : 'Refund released by staff', $row, $row->client_id, [
            'refund' => $row->id, 'amount' => $row->amount_out, 'gateway' => $row->gateway, 'status' => $row->status->value,
            'provider_reference' => $confirmed ? $row->gateway_txn_id : null,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $confirmed ? __('Refund recorded as made at the provider.') : __('Refund released. The amount can be refunded again.')]);

        return back();
    }

    /**
     * Staff dealt with a chargeback or provider refund that nothing could absorb (e.g. contested the dispute).
     */
    public function reviewed(Transaction $transaction): RedirectResponse
    {
        $this->authorize('billing.payments.manage');
        abort_if($transaction->review_amount === null, 404);

        if (Transaction::toReview()->whereKey($transaction->id)->update(['reviewed_at' => now()]) === 1) {
            $this->activity->log('Gateway reversal reviewed', $transaction, $transaction->client_id, [
                'type' => $transaction->type->value, 'gateway' => $transaction->gateway, 'reference' => $transaction->gateway_txn_id, 'review_amount' => $transaction->review_amount,
            ], 'billing.reversal.reviewed');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked as reviewed.')]);

        return back();
    }
}
