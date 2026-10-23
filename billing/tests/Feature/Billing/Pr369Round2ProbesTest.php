<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\FiscalBackfill;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.report_currency' => 'NPR']);
    $this->client = billingClient(['country' => 'NP', 'currency' => 'NPR']);
});

function round2Invoice(int $clientId): int
{
    $id = DB::table('invoices')->insertGetId([
        'client_id' => $clientId, 'number' => 'R-'.bin2hex(random_bytes(3)), 'status' => 'paid', 'kind' => 'standard', 'source' => 'manual',
        'currency' => 'NPR', 'issue_date' => '2026-08-01', 'subtotal' => 10_000, 'tax1_name' => 'VAT', 'tax1_rate' => 13, 'tax1_amount' => 1_300, 'total' => 11_300,
        'created_at' => '2026-08-01 05:00:00', 'updated_at' => '2026-08-01 05:00:00', 'paid_at' => '2026-08-02 05:00:00',
    ]);
    DB::table('invoice_items')->insert(['invoice_id' => $id, 'type' => 'manual', 'description' => 'Hosting', 'quantity' => 1, 'unit_amount' => 10_000, 'amount' => 10_000,
        'taxable' => true, 'tax_amount' => 1_300, 'created_at' => '2026-08-01 05:00:00', 'updated_at' => '2026-08-01 05:00:00']);

    return $id;
}

function round2Fee(int $id, int $invoiceId, int $amount, ?string $at = null): void
{
    $at ??= now()->format('Y-m-d H:i:s');
    DB::table('invoice_adjustments')->insert(['id' => $id, 'invoice_id' => $invoiceId, 'kind' => 'debit', 'reason' => 'late_fee', 'amount' => $amount,
        'taxable_amount' => $amount, 'tax_amount' => 0, 'created_at' => $at, 'updated_at' => $at]);
}

test('probe W1: a value row that becomes visible below the watermark is reconciled by a later run', function () {
    [$late, $early] = [round2Invoice($this->client->id), round2Invoice($this->client->id)];
    app(FiscalBackfill::class)->run();

    round2Fee(1_001, $early, 100);           // committed first, with the higher id
    app(FiscalBackfill::class)->run();       // the row is young, so the watermark stays below it
    round2Fee(1_000, $late, 565);            // the transaction that took id 1,000 commits now
    app(FiscalBackfill::class)->run();
    app(FiscalBackfill::class)->run();

    expect(Invoice::query()->findOrFail($early)->debited_total)->toBe(100)
        ->and(Invoice::query()->findOrFail($late)->debited_total)->toBe(565)
        ->and(app(FiscalBackfill::class)->mismatches())->toBe(0);
});

test('probe S1: a previous-release row stamped in the second of numbering is accepted by the clamp (documented, ADR 0024 §2)', function () {
    $id = round2Invoice($this->client->id);
    app(FiscalBackfill::class)->run();
    $numberedAt = DB::table('invoices')->where('id', $id)->value('numbered_at');

    DB::table('invoice_adjustments')->insert(['invoice_id' => $id, 'kind' => 'debit', 'reason' => 'late_fee', 'amount' => 565,
        'taxable_amount' => 500, 'tax_amount' => 65, 'created_at' => $numberedAt, 'updated_at' => $numberedAt]);

    expect(app(FiscalBackfill::class)->run()['reconciled'])->toBe(0)
        ->and(app(FiscalBackfill::class)->mismatches())->toBe(0)
        ->and(Invoice::query()->findOrFail($id)->debited_total)->toBe(0);
});

test('the watermark moves over rows older than an hour, up to the first younger row', function () {
    $invoice = round2Invoice($this->client->id);
    app(FiscalBackfill::class)->run();
    $old = now()->subHours(2)->format('Y-m-d H:i:s');
    round2Fee(2_001, $invoice, 10, $old);
    round2Fee(2_002, $invoice, 10, $old);
    round2Fee(2_003, $invoice, 10);
    round2Fee(2_004, $invoice, 10, $old);

    app(FiscalBackfill::class)->reconcile();

    expect(app(Settings::class)->get('billing.fiscal_reconcile_after'))->toBe(2_002)
        ->and(Invoice::query()->findOrFail($invoice)->debited_total)->toBe(40);
});

test('the cached totals are recomputed in chunks of 200 invoices, each saving the watermark', function () {
    $old = now()->subHours(3)->format('Y-m-d H:i:s');
    $ids = array_map(fn () => round2Invoice($this->client->id), range(1, 205));
    app(FiscalBackfill::class)->run();

    foreach ($ids as $i => $id) {
        round2Fee(3_000 + $i, $id, 5, $old);
    }

    expect(app(FiscalBackfill::class)->reconcile())->toBe(205)
        ->and(app(Settings::class)->get('billing.fiscal_reconcile_after'))->toBe(3_204)
        ->and(app(FiscalBackfill::class)->mismatches())->toBe(0);
});

describe('PostgreSQL races', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('a value row that commits after a higher id is corrected by a later run', function () {
        $this->useSeededDatabase('pr369_watermark', function (): void {
            Currency::factory()->create();
            Currency::factory()->npr()->create();
            billingSettings(['tax.report_currency' => 'NPR']);
            $client = billingClient(['email' => 'pr369-wm@example.test', 'country' => 'NP', 'currency' => 'NPR']);
            issueInvoice($client, [10_000]);
            issueInvoice($client, [20_000]);
            DB::table('invoices')->update(['numbered_at' => '2026-01-01 00:00:00']);
        });
        $invoices = Invoice::query()->orderBy('id')->pluck('id')->all();
        $database = DB::connection()->getDatabaseName();

        // A takes the lower id and commits at 2.5 s; B takes the higher id, commits at 0.5 s and runs the command at once.
        $results = runRaceWorkers([
            'a' => ['adjust', $database, (string) $invoices[0], '565', '0', '2500', '0'],
            'b' => ['adjust', $database, (string) $invoices[1], '100', '500', '0', '1'],
        ], script: __DIR__.'/Support/adjustment-worker.php');

        expect($results['a']['id'])->toBeLessThan($results['b']['id'])
            ->and($results['b']['reconciled'])->toBe(1)
            ->and(Invoice::query()->findOrFail($invoices[0])->debited_total)->toBe(0);

        expect(app(FiscalBackfill::class)->run()['reconciled'])->toBe(1)
            ->and(Invoice::query()->findOrFail($invoices[0])->debited_total)->toBe(565)
            ->and(Invoice::query()->findOrFail($invoices[1])->debited_total)->toBe(100)
            ->and(app(FiscalBackfill::class)->mismatches())->toBe(0)
            ->and(app(FiscalBackfill::class)->run()['reconciled'])->toBe(0);
    });
});
