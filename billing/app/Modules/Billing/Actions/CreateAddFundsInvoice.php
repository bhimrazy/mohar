<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Core\CompanyClock;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Client;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;

/**
 * Client deposit into the credit balance (§4.5): an add_funds invoice that credits the ledger when paid.
 */
class CreateAddFundsInvoice
{
    public function __construct(
        private IssueInvoice $issue,
        private CreditLedgerService $credit,
        private BillingSettings $settings,
        private CompanyClock $clock,
    ) {}

    /**
     * Why add funds is unavailable for this client, or null when it is available.
     */
    public function unavailableReason(Client $client): ?string
    {
        if ($this->settings->bool('credit.require_accepted_order') && ! ClientFlag::query()->whereKey($client->id)->whereNotNull('first_order_accepted_at')->exists()) {
            return __('Adding funds becomes available after your first order has been accepted.');
        }

        return null;
    }

    public function handle(Client $client, int $amount, ?string $gateway = null): Invoice
    {
        if (($reason = $this->unavailableReason($client)) !== null) {
            throw new BillingException($reason);
        }

        $currency = $client->currency;
        $min = $this->settings->int('credit.min_deposit');
        $max = $this->settings->int('credit.max_deposit');

        if ($amount < $min || ($max > 0 && $amount > $max)) {
            throw new BillingException(__('Enter an amount between :min and :max.', ['min' => app(MoneyFormatter::class)->format(Money::of($min, $currency)), 'max' => app(MoneyFormatter::class)->format(Money::of($max, $currency))]));
        }

        $maxBalance = $this->settings->int('credit.max_balance');
        $pending = (int) Invoice::query()->where('client_id', $client->id)->where('kind', InvoiceKind::AddFunds)->where('status', InvoiceStatus::Unpaid)->sum('total');

        if ($maxBalance > 0 && $this->credit->balance($client->id, $currency)->amount + $pending + $amount > $maxBalance) {
            throw new BillingException(__('Your credit balance can be at most :max.', ['max' => app(MoneyFormatter::class)->format(Money::of($maxBalance, $currency))]));
        }

        return $this->issue->handle(new InvoiceDraft(
            clientId: $client->id,
            currency: $currency,
            lines: [new InvoiceLineData(__('Add funds to account credit'), $amount, taxable: $this->settings->bool('credit.tax_add_funds'), billableType: BillableType::AddFunds)],
            dueDate: $this->clock->today(),
            paymentGateway: $gateway,
        ), 'add_funds');
    }
}
