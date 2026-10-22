<?php

use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;

require_once __DIR__.'/helpers.php';

/**
 * #218: account credit is posted only in the currency of the client row the ledger locks. Admin credit adjustments
 * and manual payments without invoices price the amount from a client read before that lock, so a currency change
 * landing in between is refused instead of booking credit in the old currency.
 */
beforeEach(function () {
    Currency::factory()->npr()->create();
    $this->client = billingClient(['currency' => 'NPR']);
});

/**
 * A ledger that commits a competing currency change just before it takes the client lock (ADR 0021 §4).
 */
function ledgerChangingCurrencyFirst(string $currency): void
{
    app()->instance(CreditLedgerService::class, new class($currency) extends CreditLedgerService
    {
        public function __construct(private string $currency) {}

        public function post(int $clientId, Money $amount, CreditReason $reason, ?int $invoiceId = null, ?int $transactionId = null, ?string $description = null, ?int $staffId = null, bool $allowNegative = false, ?string $gateway = null, ?string $externalRef = null): CreditLedgerEntry
        {
            Client::query()->whereKey($clientId)->update(['currency' => $this->currency]);

            return parent::post($clientId, $amount, $reason, $invoiceId, $transactionId, $description, $staffId, $allowNegative, $gateway, $externalRef);
        }
    });
}

test('the ledger refuses an amount in another currency than the client', function () {
    expect(fn () => app(CreditLedgerService::class)->post($this->client->id, Money::of(1_000, 'USD'), CreditReason::Manual))
        ->toThrow(BillingException::class, 'Account credit must be in the client currency (NPR).');

    app(CreditLedgerService::class)->post($this->client->id, Money::of(1_000, 'NPR'), CreditReason::Manual);

    expect(CreditLedgerEntry::query()->where('client_id', $this->client->id)->pluck('currency')->all())->toBe(['NPR']);
});

test('a staff credit adjustment priced before a currency change is refused', function () {
    actingAsStaff(['billing.credit.manage', 'billing.credit.adjust']);
    confirmPassword();
    ledgerChangingCurrencyFirst('USD');

    $this->post(route('admin.billing.credit.store'), ['client_id' => $this->client->id, 'direction' => 'add', 'amount' => '25.00', 'description' => 'Goodwill'])
        ->assertSessionHasErrors(['billing' => 'Account credit must be in the client currency (USD).']);

    expect(CreditLedgerEntry::query()->where('client_id', $this->client->id)->exists())->toBeFalse();
});

test('a manual payment without invoices priced before a currency change is refused with its transaction', function () {
    actingAsStaff(['billing.transactions.view', 'billing.transactions.manage']);
    confirmPassword();
    ledgerChangingCurrencyFirst('USD');

    $this->post(route('admin.billing.transactions.store'), [
        'client_id' => $this->client->id, 'type' => 'manual_income', 'amount' => '20.00', 'gateway' => 'bank_transfer',
        'occurred_at' => now()->toDateString(),
    ])->assertSessionHasErrors(['billing' => 'Account credit must be in the client currency (USD).']);

    expect(Transaction::query()->where('client_id', $this->client->id)->exists())->toBeFalse()
        ->and(CreditLedgerEntry::query()->where('client_id', $this->client->id)->exists())->toBeFalse();
});

test('a manual payment without invoices in the client currency still becomes credit', function () {
    actingAsStaff(['billing.transactions.view', 'billing.transactions.manage']);
    confirmPassword();

    $this->post(route('admin.billing.transactions.store'), [
        'client_id' => $this->client->id, 'type' => 'manual_income', 'amount' => '20.00', 'gateway' => 'bank_transfer',
        'occurred_at' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect(CreditLedgerEntry::query()->where('client_id', $this->client->id)->sole())->currency->toBe('NPR')->amount->toBe(2_000);
});
