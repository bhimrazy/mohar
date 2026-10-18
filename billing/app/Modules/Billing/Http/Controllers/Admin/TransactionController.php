<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\RecordManualTransaction;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\RefundMode;
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
        ]);

        $transactions = Transaction::query()
            ->with(['client:id,first_name,last_name,company_name', 'invoice:id,number,proforma_number'])
            ->when($filters['search'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query->whereLike('gateway_txn_id', "%{$term}%")->orWhereLike('description', "%{$term}%")))
            ->when($filters['client'] ?? null, fn ($query, $client) => $query->where('client_id', $client))
            ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('gateway', $gateway))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('occurred_at', '<=', $to))
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
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund recorded.')]);

        return back();
    }
}
