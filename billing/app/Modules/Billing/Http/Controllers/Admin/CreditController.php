<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Contracts\Billing\GatewayDriver;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\RefundAccountCredit;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Http\Requests\Admin\CreditAdjustmentRequest;
use App\Modules\Billing\Http\Requests\Admin\CreditRefundRequest;
use App\Modules\Billing\Http\Requests\Admin\InvoicingPreferenceRequest;
use App\Modules\Billing\Http\Resources\CreditEntryResource;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credit management per client: balance, ledger and manual adjustments.
 */
class CreditController extends Controller
{
    public function index(Request $request, CreditLedgerService $ledger, GatewayManager $gateways): Response
    {
        $this->authorize('billing.credit.manage');

        $client = $request->filled('client') ? Client::query()->find($request->integer('client')) : null;

        $owing = $request->boolean('owing');

        $entries = ($owing ? CreditLedgerEntry::owing() : CreditLedgerEntry::query())
            ->with(['client:id,first_name,last_name,company_name', 'staff:id,name'])
            ->when($client, fn ($query) => $query->where('client_id', $client?->id))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/billing/credit/index', [
            'can' => [
                'adjust' => $request->user()?->hasPermission('billing.credit.adjust') ?? false,
                'refund' => $request->user()?->hasPermission('billing.credit.refund') ?? false,
                'refund_gateway' => ($request->user()?->hasPermission('billing.credit.refund') ?? false) && $request->user()->hasPermission('billing.refunds.manage'),
            ],
            'refund_methods' => $this->gatewayNames($gateways)->map(fn (string $name, string $key): array => ['value' => $key, 'label' => $name])->values()->all(),
            'refundable_payments' => $client === null ? [] : $this->refundablePayments($client, $gateways, $this->gatewayNames($gateways)),
            'owing' => $owing,
            'client' => $client === null ? null : [
                'id' => $client->id,
                'display_name' => $client->display_name,
                'currency' => $client->currency,
                'balance' => $ledger->balance($client->id, $client->currency)->amount,
                'separate_invoices' => ClientFlag::query()->whereKey($client->id)->value('separate_invoices'),
            ],
            'entries' => CreditEntryResource::collection($entries),
        ]);
    }

    /**
     * Per-client invoicing preference (separate invoice per renewal item, or the default).
     */
    public function preferences(InvoicingPreferenceRequest $request, Client $client, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();

        ClientFlag::query()->updateOrCreate(['client_id' => $client->id], ['separate_invoices' => $data['separate_invoices'] ?? null]);
        $activity->log('Invoicing preference changed', $client, $client->id, ['separate_invoices' => $data['separate_invoices'] ?? null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoicing preference saved.')]);

        return back();
    }

    public function store(CreditAdjustmentRequest $request, CreditLedgerService $ledger, ActivityLogger $activity): RedirectResponse
    {
        $client = Client::query()->findOrFail($request->integer('client_id'));
        $amount = Money::of($request->minor('amount', $client->currency), $client->currency);

        $entry = $ledger->post(
            $client->id,
            $request->string('direction')->toString() === 'remove' ? $amount->negate() : $amount,
            CreditReason::Manual,
            description: $request->string('description')->toString(),
            staffId: $request->user()?->id,
        );

        $activity->log('Credit balance adjusted', $entry, $client->id, ['amount' => $entry->amount, 'balance_after' => $entry->balance_after, 'reason' => $entry->description]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credit balance updated.')]);

        return back();
    }

    /**
     * Display name of each registered gateway by key, from one query for the saved ones.
     *
     * @return Collection<string, string>
     */
    private function gatewayNames(GatewayManager $gateways): Collection
    {
        $saved = Gateway::query()->whereIn('key', array_keys($gateways->drivers()))->pluck('display_name', 'key');

        return collect($gateways->drivers())->map(fn (GatewayDriver $driver, string $key): string => (string) ($saved[$key] ?? $driver->name()));
    }

    /**
     * Payments of this client the gateway can still refund, newest first, for the gateway variant of Refund credit.
     *
     * @param  Collection<string, string>  $names
     * @return list<array{id: int, label: string, gateway: string, refundable: int, currency: string}>
     */
    private function refundablePayments(Client $client, GatewayManager $gateways, Collection $names): array
    {
        return array_values(Transaction::query()->where('client_id', $client->id)->where('type', TransactionType::Payment)->where('status', TransactionStatus::Succeeded)
            ->where('refundable_remaining', '>', 0)->where('currency', $client->currency)->whereNotNull('gateway_txn_id')
            ->with('invoice:id,number,proforma_number')->latest('id')->limit(50)->get(['id', 'invoice_id', 'gateway', 'gateway_txn_id', 'refundable_remaining', 'currency', 'occurred_at'])
            ->filter(fn (Transaction $payment): bool => isset($gateways->drivers()[$payment->gateway]) && $gateways->driver($payment->gateway)->supports(GatewayDriver::REFUND))
            ->map(fn (Transaction $payment): array => [
                'id' => $payment->id,
                'label' => implode(' · ', array_filter([$names->get($payment->gateway, $payment->gateway), $payment->occurred_at->format('Y-m-d'), $payment->invoice?->displayNumber()])),
                'gateway' => $payment->gateway,
                'refundable' => $payment->refundable_remaining,
                'currency' => $payment->currency,
            ])->all());
    }

    /** Pay account credit out. The balance, the payment and the provider are checked by the action, under lock. */
    public function refund(CreditRefundRequest $request, RefundAccountCredit $refund, ActivityLogger $activity): RedirectResponse
    {
        $client = Client::query()->findOrFail($request->integer('client_id'));
        $amount = $request->minor('amount', $client->currency);
        $note = $request->validated('description');
        $gateway = $request->string('mode')->toString() === 'gateway';

        $row = $gateway
            ? $refund->throughGateway($client, Transaction::query()->findOrFail($request->integer('payment_id')), $amount, $note, $request->user()?->id)
            : $refund->recorded($client, $amount, $request->string('method')->toString(), $request->string('reference')->toString(), $note, $request->user()?->id);

        $activity->log('Account credit refunded', $row, $client->id, [
            'refund' => $row->id, 'amount' => $row->amount_out, 'mode' => $gateway ? 'gateway' : 'recorded', 'gateway' => $row->gateway, 'status' => $row->status->value,
            'payment' => $row->parent_transaction_id, 'reference' => $row->gateway_txn_id,
        ]);

        Inertia::flash('toast', $row->status === TransactionStatus::Pending
            ? ['type' => 'info', 'message' => __('The refund is being confirmed with the provider.')]
            : ['type' => 'success', 'message' => __('Credit refunded.')]);

        return back();
    }
}
