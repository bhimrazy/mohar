<?php

use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Modules\Billing\Actions\RefundAccountCredit;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

test('P2 lock order, widened window: credit refund settle (payment, client, refund) against release (refund, payment)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pgsql');
    }
    test()->useSeededDatabase('pr464_deadlock', function (): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        sandboxGateway();
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'dl@example.test']);
        $payment = payInvoice(issueInvoice($client, [10_000]), 10_000, 'ch_dl', 'sandbox_card');
        app(CreditLedgerService::class)->credit($client->id, Money::of(10_000, 'NPR'), 'opening');
        app(GatewayManager::class)->register(new class extends SandboxCardDriver
        {
            public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
            {
                return new ChargeResult(ChargeResult::ERROR, declineReason: 'timeout');
            }
        });
        app(RefundAccountCredit::class)->throughGateway($client, $payment, 8_000, null, null);
    });
    $refund = (int) Transaction::query()->where('purpose', 'credit_refund')->value('id');
    $start = (string) ((int) (microtime(true) * 1000) + 2_500);
    $db = DB::connection()->getDatabaseName();

    $results = runRaceWorkers([
        'callback' => ['settle', $db, (string) $refund, $start],
        'release' => ['release', $db, (string) $refund, $start],
    ], script: __DIR__.'/Support/credit-refund-deadlock-worker.php');

    // Both finish: the callback waits for the lock the release holds (refund row first, then payment), it is never chosen as a deadlock victim.
    expect($results['callback']['outcome'])->toBe('settled')->and($results['callback']['status'])->toBe(200)
        ->and($results['release']['outcome'])->toBe('released');
});
