<?php

use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Modules\Billing\Actions\RefundAccountCredit;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/* Race tests for paying account credit out (ADR 0024 §5a): separate processes on committed data, so PostgreSQL. */

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

/**
 * Three clients with 10,000 of credit each, a payment of 10,000 each: a pending credit refund of 8,000 (callback against
 * release), two recorded refunds of 7,000 against one balance, and two gateway refunds of 8,000 against one payment.
 *
 * @return array{late: array{refund: int, client: int}, balance: int, payment: array{id: int, client: int}}
 */
function seedCreditRefundRaces(): array
{
    test()->useSeededDatabase('vat_credit_refund_races', function (): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        sandboxGateway();
        foreach (['a', 'b', 'c'] as $key) {
            $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => "credit-refund-race-$key@example.test"]);
            $payment = payInvoice(issueInvoice($client, [10_000]), 10_000, "ch_cr_$key", 'sandbox_card');
            app(CreditLedgerService::class)->credit($client->id, Money::of(10_000, 'NPR'), 'opening');

            if ($key === 'a') {
                app(GatewayManager::class)->register(new class extends SandboxCardDriver
                {
                    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
                    {
                        return new ChargeResult(ChargeResult::ERROR, declineReason: 'timeout');
                    }
                });
                app(RefundAccountCredit::class)->throughGateway($client, $payment, 8_000, null, null);
                app(GatewayManager::class)->register(new SandboxCardDriver);
            }
        }
    });

    $client = fn (string $key): int => (int) Client::query()->where('email', "credit-refund-race-$key@example.test")->value('id');

    return [
        'late' => ['refund' => (int) Transaction::query()->where('purpose', 'credit_refund')->value('id'), 'client' => $client('a')],
        'balance' => $client('b'),
        'payment' => ['id' => (int) Transaction::query()->where('client_id', $client('c'))->where('type', 'payment')->value('id'), 'client' => $client('c')],
    ];
}

function creditRefundRaceStart(): string
{
    return (string) ((int) (microtime(true) * 1000) + 2_500);
}

test('the provider callback against a release of one pending credit refund: paid out once, whichever comes first', function () {
    $ids = seedCreditRefundRaces()['late'];
    $database = DB::connection()->getDatabaseName();
    $start = creditRefundRaceStart();

    $results = runRaceWorkers([
        'callback' => ['settle', $database, (string) $ids['refund'], $start],
        'release' => ['release', $database, (string) $ids['refund'], $start],
    ], script: __DIR__.'/Support/credit-refund-worker.php');

    // Callback first: settled, the release only tells staff. Release first: the callback finds the failed row and pays it after a decline.
    expect($results['callback']['status'])->toBe(200)->and($results['callback']['outcome'])->toBe('settled')->and($results['release']['outcome'])->toBe('released')
        ->and(Transaction::query()->findOrFail($ids['refund'])->status)->toBe(TransactionStatus::Succeeded)
        ->and(app(CreditLedgerService::class)->balance($ids['client'], 'NPR')->amount)->toBe(2_000)
        ->and(CreditLedgerEntry::query()->where('client_id', $ids['client'])->whereIn('reason', ['credit_refunded', 'credit_refund_released'])->sum('amount'))->toEqual(-8_000);
});

test('two refunds made outside against one balance: one is paid, the other is refused', function () {
    $client = seedCreditRefundRaces()['balance'];
    $database = DB::connection()->getDatabaseName();
    $start = creditRefundRaceStart();

    $results = runRaceWorkers([
        'first' => ['recorded', $database, (string) $client, $start, '7000'],
        'second' => ['recorded', $database, (string) $client, $start, '7000'],
    ], script: __DIR__.'/Support/credit-refund-worker.php');

    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['BillingException', 'refunded'])
        ->and(app(CreditLedgerService::class)->balance($client, 'NPR')->amount)->toBe(3_000);
});

test('two gateway credit refunds against one payment: only what the payment still holds is paid out', function () {
    $ids = seedCreditRefundRaces()['payment'];
    $database = DB::connection()->getDatabaseName();
    $start = creditRefundRaceStart();

    $results = runRaceWorkers([
        'first' => ['gateway', $database, (string) $ids['id'], $start, '8000'],
        'second' => ['gateway', $database, (string) $ids['id'], $start, '8000'],
    ], script: __DIR__.'/Support/credit-refund-worker.php');

    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['BillingException', 'refunded'])
        ->and(Transaction::query()->findOrFail($ids['id'])->refundable_remaining)->toBe(2_000)
        ->and(app(CreditLedgerService::class)->balance($ids['client'], 'NPR')->amount)->toBe(2_000);
});
