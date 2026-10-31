<?php

use App\Data\Billing\CallbackEvent;
use App\Events\Billing\PaymentReinstated;
use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';

/**
 * Review probes for PR #256 (#232 won-unbooked path, #221 PaymentReinstated). Each asserts the invariant the PR
 * should hold; at a151d09d they fail.
 *
 * P1/P2: tried locally, a lock on the payments plus a re-read of the chargeback in the unbooked-win path makes P2 pass
 * but not P1. Adding a durable marker written under that lock (for instance a zero-amount `chargeback_reversal` row for
 * the dispute id) and checked by the chargeback path under its own payment lock makes both pass.
 */
beforeEach(function () {
    sandboxGateway();
});

/** runRaceWorkers() (Support/race.php) with Support/dispute-worker.php: race.php hard-codes credit-worker.php. */
function pr256RaceWorkers(array $workers, float $timeoutSeconds = 30.0): array
{
    $script = __DIR__.'/Support/dispute-worker.php';
    $running = [];

    try {
        foreach ($workers as $label => $arguments) {
            $process = proc_open([PHP_BINARY, $script, $label, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect($process)->not->toBeFalse("worker $label did not start");
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$label] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => ''];
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $open = false;

            foreach ($running as $label => &$worker) {
                $worker['out'] .= (string) stream_get_contents($worker['pipes'][1]);
                $worker['err'] .= (string) stream_get_contents($worker['pipes'][2]);
                $open = $open || ! feof($worker['pipes'][1]) || ! feof($worker['pipes'][2]);
            }
            unset($worker);

            if (! $open) {
                break;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException("race workers still running after {$timeoutSeconds} s: ".implode(', ', array_keys($running)));
            }

            usleep(20_000);
        }
    } finally {
        foreach ($running as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], 9);
            }

            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
    }

    $results = [];

    foreach ($running as $label => $worker) {
        $result = json_decode(trim($worker['out']), true);
        expect($result)->toBeArray("worker $label printed no result: ".$worker['out'].$worker['err'])
            ->and($result['label'] ?? null)->toBe($label)
            ->and(array_key_exists('error', $result))->toBeFalse("worker $label failed: ".($result['error'] ?? '').' '.$worker['err']);
        $results[$label] = $result;
    }

    return $results;
}

function pr256Event(string $type, string $dispute, string $charge, int $amount, bool $reinstated = false): CallbackEvent
{
    return new CallbackEvent(type: $type, invoiceId: null, txnId: $dispute, amount: $amount, currency: 'USD', parentTxnId: $charge,
        reason: 'dispute: fraudulent', raw: ['dispute' => $dispute], fundsReinstated: $reinstated);
}

/** Booked chargebacks for a dispute id that no reversal undoes. */
function pr256Orphans(string $dispute): int
{
    $chargeback = Transaction::query()->where('gateway_txn_id', $dispute)->where('type', TransactionType::Chargeback)->exists();
    $reversal = Transaction::query()->where('gateway_txn_id', $dispute)->where('type', TransactionType::ChargebackReversal)->exists();

    return $chargeback && ! $reversal ? 1 : 0;
}

test('probe P1: a funds_withdrawn retrieved before the win and applied after the won close leaves no orphan chargeback', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'ch_p1', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    $handler = app(GatewayCallbackHandler::class);

    // The won close (Stripe shows withdrawal + reinstatement, nothing booked yet) is answered 200, books nothing ...
    expect($handler->process('sandbox_card', pr256Event(CallbackEvent::DISPUTE_WON, 'dp_p1', 'ch_p1', 5_000, reinstated: true))['result'])
        ->toBe(GatewayCallbackHandler::DISPUTE_WON_UNBOOKED);
    // ... then the in-flight funds_withdrawn, whose driver GET saw `needs_response`, reaches Billing.
    $handler->process('sandbox_card', pr256Event(CallbackEvent::CHARGEBACK, 'dp_p1', 'ch_p1', 5_000));

    expect(pr256Orphans('dp_p1'))->toBe(0, 'chargeback booked for a dispute already won at the provider; nothing will reverse it')
        ->and($invoice->fresh()->balance)->toBe(0);
});

test('probe P2 (PostgreSQL, two processes): the won close does not wait for a chargeback being booked concurrently', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }

    $this->useSeededDatabase('pr256_won_race', function (): void {
        sandboxGateway();
        $client = billingClient(['email' => 'won-race@example.test']);
        $invoice = issueInvoice($client, [5_000]);
        payInvoice($invoice, 5_000, 'ch_p2', 'sandbox_card');
    });
    $database = DB::connection()->getDatabaseName();

    // The chargeback holds its row (inserted, uncommitted) for 1.5 s; the won close arrives 0.3 s in.
    $results = pr256RaceWorkers([
        'chargeback' => ['chargeback', $database, 'dp_p2', 'ch_p2', '5000', '0', '1500'],
        'won' => ['won', $database, 'dp_p2', 'ch_p2', '5000', '300', '0'],
    ]);

    expect($results['chargeback']['result'])->toBe('chargeback recorded')
        // A correct outcome: the won close waits for the payment lock, sees the chargeback and reverses it.
        ->and($results['won']['result'])->toBe('dispute won; payment reinstated')
        ->and(pr256Orphans('dp_p2'))->toBe(0);
});

test('probe E1: every PaymentReversed of a combined charge gets its PaymentReinstated counterpart', function () {
    Event::fake([PaymentReversed::class, PaymentReinstated::class]);
    $client = billingClient();
    $a = issueInvoice($client, [1_000]);
    $b = issueInvoice($client, [2_000]);
    app(PaymentBatches::class)->settle(app(PaymentBatches::class)->create($client->id, [$a->id, $b->id]), 'sandbox_card', 'ch_e1', 3_000);
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_e1', 'parent_txn_id' => 'ch_e1', 'amount' => 3_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute.won', 'txn_id' => 'dp_e1', 'parent_txn_id' => 'ch_e1', 'amount' => 3_000, 'currency' => 'USD'])->assertOk();

    $reversed = Event::dispatched(PaymentReversed::class)->map(fn (array $args) => $args[0]->invoiceId)->sort()->values()->all();
    $reinstated = Event::dispatched(PaymentReinstated::class)->map(fn (array $args) => $args[0]->invoiceId)->sort()->values()->all();

    // PR body: "Consumers get one counterpart per PaymentReversed".
    expect($reinstated)->toBe($reversed);
});

test('probe E2: a won dispute of a payment that only went to credit fires no PaymentReinstated without a PaymentReversed', function () {
    Event::fake([PaymentReversed::class, PaymentReinstated::class]);
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    payInvoice($invoice, 5_000);
    // A second payment of the paid invoice: all of it is account credit.
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'ch_e2', 'amount' => 4_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_e2', 'parent_txn_id' => 'ch_e2', 'amount' => 4_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute.won', 'txn_id' => 'dp_e2', 'parent_txn_id' => 'ch_e2', 'amount' => 4_000, 'currency' => 'USD'])->assertOk();

    expect(Event::dispatched(PaymentReinstated::class)->count())->toBe(Event::dispatched(PaymentReversed::class)->count());
});
