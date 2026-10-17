<?php

namespace App\Modules\Billing\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Http\Requests\Client\AmountRequest;
use App\Modules\Billing\Http\Resources\CreditEntryResource;
use App\Modules\Billing\Http\Resources\TransactionResource;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credit balance, credit history, transactions and add funds.
 */
class CreditController extends Controller
{
    public function __construct(private ClientContext $context) {}

    public function index(CreditLedgerService $ledger, CreateAddFundsInvoice $addFunds, BillingSettings $settings): Response
    {
        $this->context->authorize('billing');
        $client = $this->context->client();

        return Inertia::render('client/billing/credit/index', [
            'balance' => $ledger->balance($client->id, $client->currency)->amount,
            'currency' => $client->currency,
            'entries' => CreditEntryResource::collection(CreditLedgerEntry::query()->where('client_id', $client->id)->orderByDesc('id')->paginate(15, pageName: 'credit_page')->withQueryString()),
            'transactions' => TransactionResource::collection(Transaction::query()->where('client_id', $client->id)
                ->with('invoice:id,number,proforma_number')
                ->whereIn('type', [TransactionType::Payment, TransactionType::Refund, TransactionType::ManualIncome, TransactionType::Chargeback])
                ->where(fn ($query) => $query->where('amount_in', '>', 0)->orWhere('amount_out', '>', 0))
                ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(10, pageName: 'transactions_page')->withQueryString()),
            'add_funds' => [
                'unavailable_reason' => $addFunds->unavailableReason($client),
                'min' => $settings->int('credit.min_deposit'),
                'max' => $settings->int('credit.max_deposit'),
                'max_balance' => $settings->int('credit.max_balance'),
            ],
        ]);
    }

    public function addFunds(AmountRequest $request, CreateAddFundsInvoice $addFunds): RedirectResponse
    {
        $this->context->authorize('billing');
        $client = $this->context->client();
        $invoice = $addFunds->handle($client, $request->minor('amount', $client->currency), $request->validated('gateway'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice :number created. Pay it to add the funds.', ['number' => $invoice->displayNumber()])]);

        return to_route('client.invoices.show', $invoice->id);
    }
}
