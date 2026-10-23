<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Services\FiscalBackfill;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

// The backfill (ADR 0024 §2, AC 9) on rows written the way the previous release left them. Nepal VAT 13%, NPR.

beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.report_currency' => 'NPR']);
    $this->client = billingClient(['country' => 'NP', 'currency' => 'NPR']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyInvoice(int $clientId, array $attributes = []): int
{
    $total = $attributes['total'] ?? 11_300;
    $tax = $attributes['tax1_amount'] ?? 1_300;

    return DB::table('invoices')->insertGetId([
        'client_id' => $clientId, 'number' => 'L-'.bin2hex(random_bytes(3)), 'status' => 'paid', 'kind' => 'standard', 'source' => 'manual',
        'currency' => 'NPR', 'issue_date' => '2026-08-01', 'subtotal' => $total - $tax, 'tax1_name' => 'VAT', 'tax1_rate' => 13, 'total' => $total,
        'created_at' => '2026-08-01 05:00:00', 'updated_at' => '2026-08-01 05:00:00', 'paid_at' => '2026-08-02 05:00:00',
        ...$attributes, 'tax1_amount' => $tax,
    ]);
}

function legacyLine(int $invoiceId, int $amount, int $tax, string $description = 'Hosting', ?string $cancelledAt = null): int
{
    return DB::table('invoice_items')->insertGetId([
        'invoice_id' => $invoiceId, 'type' => 'manual', 'description' => $description, 'quantity' => 1, 'unit_amount' => $amount, 'amount' => $amount,
        'taxable' => $tax !== 0, 'tax_amount' => $tax, 'cancelled_at' => $cancelledAt, 'created_at' => '2026-08-01 05:00:00', 'updated_at' => '2026-08-01 05:00:00',
    ]);
}

function legacyAdjustment(int $invoiceId, string $kind, string $reason, int $amount, string $at, int $taxable = 0, int $tax = 0, ?string $description = null): int
{
    return DB::table('invoice_adjustments')->insertGetId([
        'invoice_id' => $invoiceId, 'kind' => $kind, 'reason' => $reason, 'amount' => $amount, 'taxable_amount' => $taxable, 'tax_amount' => $tax,
        'description' => $description, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

function legacyPayment(int $invoiceId, int $clientId, int $amount, string $createdAt, string $occurredAt): int
{
    return DB::table('transactions')->insertGetId([
        'client_id' => $clientId, 'invoice_id' => $invoiceId, 'gateway' => 'bank_transfer', 'type' => 'payment', 'status' => 'succeeded', 'amount_in' => $amount,
        'currency' => 'NPR', 'refundable_remaining' => $amount, 'occurred_at' => $occurredAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]);
}

/** The migration itself, as `php artisan migrate` runs it. */
function runBackfillMigration(): void
{
    (require base_path('app/Modules/Billing/Database/Migrations/2026_10_24_300680_backfill_fiscal_snapshot_of_numbered_invoices.php'))->up();
}

function reviewsOf(int $invoiceId): array
{
    return InvoiceFiscalReview::query()->where('invoice_id', $invoiceId)->orderBy('id')->pluck('reason')->all();
}

test('a publish-numbered invoice gets numbered_at = created_at and a snapshot equal to its own totals', function () {
    $id = legacyInvoice($this->client->id, ['total' => 13_300]);
    legacyLine($id, 10_000, 1_300, 'Hosting');
    legacyLine($id, 2_000, 0, 'Domain');
    legacyAdjustment($id, 'debit', 'late_fee', 565, '2026-08-20 05:00:00', 500, 65, 'Late payment fee');
    $number = Invoice::query()->findOrFail($id)->number;

    runBackfillMigration();
    $invoice = Invoice::query()->findOrFail($id);

    expect($invoice->number)->toBe($number)
        ->and($invoice->numbered_at->toDateTimeString())->toBe('2026-08-01 05:00:00')
        ->and([$invoice->fiscal_taxable, $invoice->fiscal_exempt, $invoice->fiscal_tax1, $invoice->fiscal_tax2, $invoice->fiscal_total])->toBe([10_000, 2_000, 1_300, 0, 13_300])
        ->and([$invoice->report_currency, $invoice->report_rate, $invoice->supply_class])->toBe(['NPR', '1.000000000000', 'taxable'])
        // 1 Aug 2026 is 16 Shrawan 2083 BS.
        ->and([$invoice->bs_issue_date, $invoice->bs_period, $invoice->bs_fiscal_year])->toBe(['2083-04-16', '2083-04', '2083.084'])
        // Every adjustment on a publish-numbered invoice is after numbering, so none is in the snapshot.
        ->and([$invoice->credited_total, $invoice->debited_total])->toBe([0, 565])
        ->and(reviewsOf($id))->toBe([]);
});

test('a payment-numbered invoice gets the created_at of its numbering payment, not its backdated occurred_at', function () {
    $id = legacyInvoice($this->client->id, ['proforma_number' => 'PF-1', 'issue_date' => '2026-09-10', 'created_at' => '2026-09-01 05:00:00', 'paid_at' => '2026-09-10 07:00:00']);
    legacyLine($id, 10_000, 1_300);
    legacyAdjustment($id, 'debit', 'late_fee', 565, '2026-09-05 05:00:00', 500, 65);
    legacyPayment($id, $this->client->id, 11_865, '2026-09-10 07:00:00', '2026-09-01 00:00:00');
    legacyAdjustment($id, 'credit', 'refund', 1_000, '2026-09-20 05:00:00');

    runBackfillMigration();
    $invoice = Invoice::query()->findOrFail($id);

    expect($invoice->numbered_at->toDateTimeString())->toBe('2026-09-10 07:00:00')
        // The late fee came before the payment, so it is in the value; the refund came after, so it is in the caps.
        ->and([$invoice->fiscal_taxable, $invoice->fiscal_tax1, $invoice->fiscal_total])->toBe([10_500, 1_365, 11_865])
        ->and([$invoice->credited_total, $invoice->debited_total])->toBe([1_000, 0])
        ->and([$invoice->bs_issue_date, $invoice->bs_period])->toBe(['2083-05-25', '2083-05'])
        // The refund stores no line and no tax split.
        ->and(reviewsOf($id))->toBe([InvoiceFiscalReview::LINE_LESS_ROW]);
});

test('an invoice without one identifiable numbering payment is listed and gets its paid_at as an estimate', function () {
    $two = legacyInvoice($this->client->id, ['proforma_number' => 'PF-2', 'paid_at' => '2026-09-15 08:00:00']);
    legacyLine($two, 10_000, 1_300);
    legacyPayment($two, $this->client->id, 5_000, '2026-09-10 07:00:00', '2026-09-10 07:00:00');
    legacyPayment($two, $this->client->id, 6_300, '2026-09-15 08:00:00', '2026-09-15 08:00:00');

    $credit = legacyInvoice($this->client->id, ['proforma_number' => 'PF-3', 'paid_at' => '2026-09-16 08:00:00']);
    legacyLine($credit, 10_000, 1_300);
    legacyAdjustment($credit, 'credit', 'credit_applied', 11_300, '2026-09-16 08:00:00');

    $partial = legacyInvoice($this->client->id, ['proforma_number' => 'PF-4', 'paid_at' => '2026-09-17 08:00:00']);
    legacyLine($partial, 10_000, 1_300);
    legacyPayment($partial, $this->client->id, 6_000, '2026-09-10 07:00:00', '2026-09-10 07:00:00');

    runBackfillMigration();

    foreach ([[$two, '2026-09-15 08:00:00'], [$credit, '2026-09-16 08:00:00'], [$partial, '2026-09-17 08:00:00']] as [$id, $paidAt]) {
        expect(Invoice::query()->findOrFail($id)->numbered_at->toDateTimeString())->toBe($paidAt)
            ->and(Invoice::query()->findOrFail($id)->fiscal_total)->toBe(11_300)
            ->and(reviewsOf($id))->toBe([InvoiceFiscalReview::NUMBERING_INSTANT]);
    }
});

test('a cancelled line stored with its net as taxable on an untaxed line, and an unmatched cancellation, are listed', function () {
    $id = legacyInvoice($this->client->id, ['total' => 13_300]);
    legacyLine($id, 10_000, 1_300, 'Hosting');
    legacyLine($id, 2_000, 0, 'Domain', '2026-08-10 05:00:00');
    legacyAdjustment($id, 'credit', 'item_cancellation', 2_000, '2026-08-10 05:00:00', 2_000, 0, 'Cancelled line: Domain');
    legacyAdjustment($id, 'credit', 'item_cancellation', 300, '2026-08-11 05:00:00', 266, 34, 'Something else');

    $plain = legacyInvoice($this->client->id, ['total' => 2_000, 'tax1_amount' => 0]);
    legacyLine($plain, 2_000, 0, 'Domain', '2026-08-10 05:00:00');
    legacyAdjustment($plain, 'credit', 'item_cancellation', 2_000, '2026-08-10 05:00:00', 2_000, 0, 'Cancelled line: Domain');
    legacyAdjustment($plain, 'credit', 'cancellation', 100, '2026-08-11 05:00:00');

    runBackfillMigration();

    expect(Invoice::query()->findOrFail($id)->credited_total)->toBe(2_300)
        ->and(reviewsOf($id))->toBe([InvoiceFiscalReview::ITEM_CANCELLATION, InvoiceFiscalReview::ITEM_CANCELLATION])
        // With no tax anywhere every row is exempt and nothing is ambiguous.
        ->and(reviewsOf($plain))->toBe([]);
});

test('the backfill is chunked, repeatable and never rewrites a number, a snapshot or a seen entry', function () {
    $ids = [];

    foreach (range(1, 5) as $i) {
        $ids[] = $id = legacyInvoice($this->client->id, ['proforma_number' => 'PF-'.$i]);
        legacyLine($id, 10_000, 1_300);
        legacyAdjustment($id, 'credit', 'cancellation', 50, '2026-08-05 05:00:00');
    }

    $numbers = Invoice::query()->whereIn('id', $ids)->orderBy('id')->pluck('number')->all();
    $first = app(FiscalBackfill::class)->run(2);
    $before = DB::table('invoices')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    InvoiceFiscalReview::query()->update(['seen_at' => '2026-10-24 10:00:00']);
    $second = app(FiscalBackfill::class)->run(2);

    expect($first['filled'])->toBe(5)->and($second)->toBe(['filled' => 0, 'reviews' => 0])
        ->and(Invoice::query()->whereIn('id', $ids)->orderBy('id')->pluck('number')->all())->toBe($numbers)
        ->and(DB::table('invoices')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and(InvoiceFiscalReview::query()->count())->toBe($first['reviews'])
        ->and(InvoiceFiscalReview::query()->unseen()->count())->toBe(0);
});

test('add-funds invoices, drafts and invoices already filled are left alone', function () {
    $deposit = legacyInvoice($this->client->id, ['kind' => 'add_funds']);
    $draft = legacyInvoice($this->client->id, ['number' => null, 'status' => 'draft']);
    $done = legacyInvoice($this->client->id, ['numbered_at' => '2026-07-01 00:00:00', 'fiscal_total' => 1]);

    runBackfillMigration();

    expect(Invoice::query()->find($deposit)->numbered_at)->toBeNull()
        ->and(Invoice::query()->find($draft)->numbered_at)->toBeNull()
        ->and(Invoice::query()->find($done))->numbered_at->toDateTimeString()->toBe('2026-07-01 00:00:00')->fiscal_total->toBe(1)
        ->and(app(FiscalBackfill::class)->remaining())->toBe(0);
});

test('an invoice the previous release numbered after the migration is filled by the command, and the list shows its entries', function () {
    runBackfillMigration();
    $id = legacyInvoice($this->client->id);
    legacyLine($id, 10_000, 1_300);
    legacyAdjustment($id, 'credit', 'refund', 500, '2026-08-05 05:00:00');

    $this->artisan('billing:fiscal-snapshot')->expectsOutputToContain('1 invoices filled')->assertSuccessful();
    $this->artisan('billing:fiscal-snapshot', ['--list' => true])->expectsOutputToContain(InvoiceFiscalReview::LINE_LESS_ROW)->assertSuccessful();

    expect(Invoice::query()->findOrFail($id)->numbered_at)->not->toBeNull();
});

test('a date outside the Bikram Sambat table leaves the BS fields empty and does not stop the run', function () {
    $id = legacyInvoice($this->client->id, ['issue_date' => '1930-01-01']);
    legacyLine($id, 10_000, 1_300);

    runBackfillMigration();

    expect(Invoice::query()->findOrFail($id))->numbered_at->not->toBeNull()->bs_issue_date->toBeNull()->bs_period->toBeNull()->bs_fiscal_year->toBeNull();
});
