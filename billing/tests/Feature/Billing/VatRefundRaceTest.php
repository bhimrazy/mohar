<?php

use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Models\User;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/*
| Race tests for the refund reservation (VAT PR 4, ADR 0024 §4, §5): two processes on committed data, so PostgreSQL.
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

/**
 * Three paid numbered invoices of 11,300 for one client: a pending refund of 8,000 (callback against late answer), two
 * payments of one invoice after a note used part of the cap, and a pending refund against a staff note to credit.
 *
 * @return array{late: array{refund: int, payment: int}, cap: array{invoice: int, a: int, b: int}, deadlock: array{refund: int, invoice: int}, release: array{refund: int, invoice: int, payment: int}}
 */
function seedRefundRaces(): array
{
    test()->useSeededDatabase('vat_refund_races', function (): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
        useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
        sandboxGateway();
        User::factory()->staff(['billing.*'])->create();
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'vat-refund-races@example.test']);
        $staff = User::query()->where('is_staff', true)->value('id');
        $pendingRefund = function (Transaction $payment, int $amount) use ($staff): Transaction {
            app(GatewayManager::class)->register(new class extends SandboxCardDriver
            {
                public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
                {
                    return new ChargeResult(ChargeResult::ERROR, declineReason: 'timeout');
                }
            });
            $row = app(RefundTransaction::class)->handle($payment, $amount, RefundMode::Gateway, false, $staff, reason: 'Seeded');
            app(GatewayManager::class)->register(new SandboxCardDriver);

            return $row;
        };

        $late = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_late', 'sandbox_card');
        $pendingRefund($late, 8_000);

        // Before the note below puts account credit in the client's hands, which a payment would then use.
        $deadlock = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_dead', 'sandbox_card');
        $pendingRefund($deadlock, 4_000);

        $release = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_rel', 'sandbox_card');
        $pendingRefund($release, 6_000);

        $cap = issueInvoice($client, [10_000]);
        payInvoice($cap, 6_000, 'ch_a', 'sandbox_card');
        payInvoice($cap->fresh(), 5_300, 'ch_b', 'sandbox_card');
        $issuer = app(TaxNoteIssuer::class);
        $request = NoteRequest::creditAmount($cap->id, 5_000, 'goodwill', 'Used part of the cap', settlement: 'credit');
        $issuer->issue($request, ($preview = $issuer->preview($request))->version, $preview->digest);

    });

    $client = Client::query()->where('email', 'vat-refund-races@example.test')->value('id');
    $invoices = Invoice::query()->where('client_id', $client)->orderBy('id')->pluck('id')->all();
    $payments = fn (int $invoice) => Transaction::query()->where('invoice_id', $invoice)->where('type', 'payment')->orderBy('id')->pluck('id')->all();
    $pending = fn (int $invoice) => Transaction::query()->where('invoice_id', $invoice)->where('type', 'refund')->value('id');

    return [
        'late' => ['refund' => $pending($invoices[0]), 'payment' => $payments($invoices[0])[0]],
        'cap' => ['invoice' => $invoices[3], 'a' => $payments($invoices[3])[0], 'b' => $payments($invoices[3])[1]],
        'release' => ['refund' => $pending($invoices[2]), 'invoice' => $invoices[2], 'payment' => $payments($invoices[2])[0]],
        'deadlock' => ['refund' => $pending($invoices[1]), 'invoice' => $invoices[1]],
    ];
}

function refundRaceStart(): string
{
    return (string) ((int) (microtime(true) * 1000) + 2_500);
}

test('the provider callback and the request\'s late ERROR for one reservation: one note, one refund, the amount stays reserved (ADR 0024 §4)', function () {
    $ids = seedRefundRaces()['late'];
    $database = DB::connection()->getDatabaseName();
    $start = refundRaceStart();

    runRaceWorkers([
        'callback' => ['settle', $database, (string) $ids['refund'], $start],
        'late' => ['late-error', $database, (string) $ids['refund'], $start],
    ], script: __DIR__.'/Support/refund-worker.php');

    expect(TaxNote::query()->where('total', 8_000)->count())->toBe(1)
        ->and(Transaction::query()->findOrFail($ids['refund']))->status->toBe(TransactionStatus::Succeeded)
        ->and(DB::table('tax_note_reservations')->where('transaction_id', $ids['refund'])->value('status'))->toBe('finalised')
        ->and(Transaction::query()->where('parent_transaction_id', $ids['payment'])->where('type', 'refund')->count())->toBe(1)
        ->and(Transaction::query()->findOrFail($ids['payment'])->refundable_remaining)->toBe(3_300);
});

test('two refunds of two payments of one invoice respect the cap a note left: only one is reserved and finalised', function () {
    $ids = seedRefundRaces()['cap'];
    $database = DB::connection()->getDatabaseName();
    $start = refundRaceStart();

    $results = runRaceWorkers([
        'first' => ['refund', $database, (string) $ids['a'], $start, '6000'],
        'second' => ['refund', $database, (string) $ids['b'], $start, '5300'],
    ], script: __DIR__.'/Support/refund-worker.php');
    $notes = TaxNote::query()->where('invoice_id', $ids['invoice'])->where('reason_code', 'refunded')->get();

    // 11,300 less the 5,000 note leaves 6,300: 6,000 and 5,300 do not both fit.
    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['BillingException', 'refunded'])
        ->and($notes)->toHaveCount(1)
        ->and(Invoice::query()->findOrFail($ids['invoice'])->credited_total)->toBeLessThanOrEqual(11_300)
        ->and(Transaction::query()->where('invoice_id', $ids['invoice'])->where('type', 'refund')->where('status', 'succeeded')->count())->toBe(1);
});

test('a provider callback finalising a reservation and a staff note settled to account credit finish without a deadlock', function () {
    $ids = seedRefundRaces()['deadlock'];
    $database = DB::connection()->getDatabaseName();
    $start = refundRaceStart();

    $results = runRaceWorkers([
        'callback' => ['settle', $database, (string) $ids['refund'], $start],
        'note' => ['note', $database, (string) $ids['invoice'], $start, '3000'],
    ], script: __DIR__.'/Support/refund-worker.php');

    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['issued', 'settled'])
        ->and(TaxNote::query()->where('invoice_id', $ids['invoice'])->count())->toBe(2)
        ->and(Transaction::query()->findOrFail($ids['refund'])->status)->toBe(TransactionStatus::Succeeded);
});

test('the provider callback against a release of the same pending refund: one note, one refund, the money out once', function () {
    $ids = seedRefundRaces()['release'];
    $database = DB::connection()->getDatabaseName();
    $start = refundRaceStart();

    $results = runRaceWorkers([
        'callback' => ['settle', $database, (string) $ids['refund'], $start],
        'release' => ['release', $database, (string) $ids['refund'], $start],
    ], script: __DIR__.'/Support/refund-worker.php');

    // Callback first: the refund is settled and the late release only tells staff. Release first: the callback books the refund the
    // provider made as a provider-initiated one. Either way the provider paid 6,000 once.
    expect($results['callback']['status'])->toBe(200)
        ->and(Transaction::query()->where('invoice_id', $ids['invoice'])->where('type', 'refund')->where('status', 'succeeded')->count())->toBe(1)
        ->and(TaxNote::query()->where('invoice_id', $ids['invoice'])->where('total', 6_000)->count())->toBe(1)
        ->and(Transaction::query()->findOrFail($ids['payment'])->refundable_remaining)->toBe(5_300);
});
