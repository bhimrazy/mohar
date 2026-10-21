<?php

use App\Enums\BillingCycle;
use App\Events\Billing\InvoiceCreated;
use App\Modules\Billing\Actions\PublishInvoice;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceInvoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Notifications/helpers.php';

/**
 * Review probes for #189 (renewal query reduction), adopted as regression tests: document numbers under concurrent
 * transactions (PostgreSQL), the checks on the lines and client passed to the calculator, consecutive numbers and credit
 * lines of a renewal run, and webhooks that skip building a payload nobody subscribes to.
 */

/**
 * Starts number-worker.php processes against this test worker's database and returns their output lines.
 *
 * @param  list<array{int, int, string}>  $workers  [delay ms, hold ms, commit|rollback]
 * @return list<array{number: int, taken: float, ended: float}>
 */
function raceNumbers(string $sequence, array $workers): array
{
    $script = __DIR__.'/Support/number-worker.php';
    $database = DB::connection()->getDatabaseName();
    $processes = [];

    foreach ($workers as [$delay, $hold, $mode]) {
        $process = proc_open([PHP_BINARY, $script, $database, $sequence, (string) $delay, (string) $hold, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $processes[] = [$process, $pipes];
    }

    $results = [];

    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        expect($out)->not->toStartWith('error', $out.$err);
        [$number, $taken, $ended] = explode(' ', trim($out)) + [null, null, null];
        $results[] = ['number' => (int) $number, 'taken' => (float) $taken, 'ended' => (float) $ended];
    }

    return $results;
}

function cleanUpSequence(string $sequence): void
{
    proc_close(proc_open([PHP_BINARY, __DIR__.'/Support/number-worker.php', DB::connection()->getDatabaseName(), $sequence, '0', '0', 'cleanup'], [], $pipes));
}

describe('document numbers under real concurrent transactions (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('two first numbers of a new period race on the missing row: 1 and 2, the loser waits for the winner', function () {
        $sequence = 'probe-race-'.bin2hex(random_bytes(4));

        try {
            [$winner, $loser] = raceNumbers($sequence, [[0, 2_000, 'commit'], [600, 0, 'commit']]);

            expect([$winner['number'], $loser['number']])->toBe([1, 2])
                ->and($loser['taken'])->toBeGreaterThanOrEqual($winner['ended'] - 0.05);
        } finally {
            cleanUpSequence($sequence);
        }
    });

    test('a first number taken and rolled back leaves no gap for the transaction waiting on it', function () {
        $sequence = 'probe-rollback-'.bin2hex(random_bytes(4));

        try {
            [$rolledBack, $waiting] = raceNumbers($sequence, [[0, 2_000, 'rollback'], [600, 0, 'commit']]);
            [$after] = raceNumbers($sequence, [[0, 0, 'commit']]);

            expect([$rolledBack['number'], $waiting['number'], $after['number']])->toBe([1, 1, 2]);
        } finally {
            cleanUpSequence($sequence);
        }
    });

    test('a burst of first numbers is unique and gapless', function () {
        $sequence = 'probe-burst-'.bin2hex(random_bytes(4));

        try {
            $numbers = array_column(raceNumbers($sequence, array_fill(0, 6, [0, 150, 'commit'])), 'number');
            sort($numbers);

            expect($numbers)->toBe([1, 2, 3, 4, 5, 6]);
        } finally {
            cleanUpSequence($sequence);
        }
    });
});

test('recalculate() refuses another invoice\'s lines and leaves both invoices untouched', function () {
    $a = issueInvoice(billingClient(), [1_000], status: 'draft');
    $b = issueInvoice(billingClient(), [5_000], status: 'draft');

    expect(fn () => app(InvoiceCalculator::class)->recalculate($a, null, $b->items()->get()))->toThrow(LogicException::class)
        ->and($a->fresh()->total)->toBe(1_000)
        ->and($b->fresh()->items->pluck('tax_amount')->all())->toBe([0]);
});

test('recalculate() refuses a client that is not the invoice\'s, or one with unsaved changes', function () {
    $invoice = issueInvoice(billingClient(), [1_000], status: 'draft');
    $edited = Client::query()->findOrFail($invoice->client_id)->fill(['country' => 'NP']);

    expect(fn () => app(InvoiceCalculator::class)->recalculate($invoice, billingClient()))->toThrow(LogicException::class)
        ->and(fn () => app(InvoiceCalculator::class)->recalculate($invoice, $edited))->toThrow(LogicException::class)
        ->and(fn () => app(PublishInvoice::class)->handle($invoice, client: billingClient()))->toThrow(LogicException::class);
});

test('recalculate() with a subset of an existing invoice\'s open lines still totals every open line', function () {
    $invoice = issueInvoice(billingClient(), [1_000, 2_000], status: 'draft');

    // Passed lines are used only for an invoice created in this request; this one is read back, so its lines are read.
    app(InvoiceCalculator::class)->recalculate($invoice, null, $invoice->items()->limit(1)->get());

    expect($invoice->fresh()->total)->toBe(3_000);
});

test('publishing with a Client read before its tax country changed uses the current country', function () {
    billingSettings(['tax.enabled' => true]);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
    $client = billingClient(['country' => 'US']);
    $draft = issueInvoice($client, [1_000], status: 'draft');
    $stale = Client::query()->findOrFail($client->id);
    Client::query()->whereKey($client->id)->update(['country' => 'NP']); // another request, committed before publishing

    app(PublishInvoice::class)->handle($draft, client: $stale);

    expect($draft->fresh()->tax1_amount)->toBe(130);
});

test('a renewal run takes consecutive invoice numbers and books one credit line per applied credit', function () {
    $this->travelTo('2026-11-01 08:00:00');
    Queue::fake();
    seedEmailTemplates();
    billingSettings(['tax.enabled' => true, 'credit.auto_apply_on_generate' => true, 'number_reset' => 'monthly', 'number_format' => 'INV-{YEAR}{MONTH}-{NUMBER}']);
    TaxRule::factory()->create(['name' => 'GST', 'level' => 1, 'rate' => '18.000']);
    $credit = app(CreditLedgerService::class);
    $part = billingClient();
    $full = billingClient();
    $none = billingClient();
    $credit->credit($part->id, Money::of(500, 'USD'), 'Goodwill');
    $credit->credit($full->id, Money::of(50_000, 'USD'), 'Prepaid');
    $services = [
        Service::factory()->dueOn('2026-11-15')->for($part)->create(['recurring_amount' => 1_299]),
        Service::factory()->dueOn('2026-11-10')->for($none)->create(['recurring_amount' => 12_000, 'cycle' => BillingCycle::Annually]),
        Service::factory()->dueOn('2026-11-12')->for($full)->create(['recurring_amount' => 2_500]),
    ];

    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    $invoiceIds = collect($services)->map(fn (Service $service): int => ServiceInvoice::query()->where('service_id', $service->id)->sole()->invoice_id);
    $numbers = Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->pluck('number')->all();
    $applied = CreditLedgerEntry::query()->where('reason', CreditReason::AppliedToInvoice)->orderBy('id')->get()
        ->map(fn (CreditLedgerEntry $entry): array => [$entry->client_id, $entry->invoice_id, $entry->amount, $entry->balance_after])->all();

    expect($numbers)->toBe(['INV-202611-1', 'INV-202611-2', 'INV-202611-3'])
        ->and($applied)->toEqualCanonicalizing([
            [$part->id, $invoiceIds[0], -500, 0],
            [$full->id, $invoiceIds[2], -2_950, 47_050],
        ]);
});

test('a webhook event no endpoint subscribes to does not read its entity; a subscribed one still does', function () {
    $invoice = issueInvoice(billingClient(), [1_000]);
    $invoiceReads = function () use ($invoice): int {
        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            $reads += preg_match('/from "invoices"/', $query->sql);
        });
        app(WebhookPublisher::class)->publishEvent(new InvoiceCreated($invoice->id, $invoice->client_id));

        return $reads;
    };

    WebhookEndpoint::factory()->create(['events' => ['invoice.paid']]);
    $unsubscribed = $invoiceReads();
    WebhookEndpoint::factory()->create(['events' => ['invoice.created']]);
    Queue::fake();

    expect($unsubscribed)->toBe(0)
        ->and($invoiceReads())->toBeGreaterThan(0)
        ->and(WebhookDelivery::query()->where('event_type', 'invoice.created')->count())->toBe(1);
});
