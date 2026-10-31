<?php

use App\Contracts\Core\CompanyClock;
use App\Data\Billing\InvoiceLineData;
use App\Data\Reports\ReportQuery;
use App\Enums\BillableType;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Reports\RegisterDocuments;
use App\Modules\Billing\Reports\RegisterPeriod;
use App\Modules\Billing\Reports\RegisterSummary;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\BikramSambat;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\DateFormats;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Reports\ReportConformance;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use App\Support\Reports\ReportViewer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/helpers.php';

/*
| The VAT sales register and the note register (VAT PRs 6 and 7; vat-register §6, §7, AC 37–47): NPR, VAT 13%, Nepal time.
| Kartik 2083 BS runs from 2026-10-18 to 2026-11-16 AD; Mangsir 2083 starts on 2026-11-17.
*/

beforeEach(function () {
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'seller.pan' => '609876543', 'seller.legal_name' => 'Himal Hosting Pvt. Ltd.']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Run at 06:00 UTC (11:45 in Nepal) on an AD date. */
function onRegisterDay(string $date, Closure $action): mixed
{
    CarbonImmutable::setTestNow($date.' 06:00:00');

    return $action();
}

function registerNote(NoteRequest $request): TaxNote
{
    $issuer = app(TaxNoteIssuer::class);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

/**
 * Kartik 2083: two tax invoices (one with an exempt line, to a company without a PAN), a credit note, a debit note and
 * a draft; and a credit note in Mangsir.
 *
 * @return array{a: Invoice, b: Invoice}
 */
function kartikRegisterFixture(): array
{
    $withPan = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'company_name' => 'Everest Treks Pvt. Ltd.', 'tax_id' => '301234567']);
    $withoutPan = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'company_name' => 'Lumbini Foods', 'tax_id' => null]);
    $line = fn (string $description, int $amount, bool $taxable = true): InvoiceLineData => new InvoiceLineData($description, $amount, taxable: $taxable, billableType: BillableType::Manual);

    $a = onRegisterDay('2026-10-20', fn () => issueInvoice($withPan, [$line('Web hosting', 10_000), $line('Backups', 5_000)]));
    $b = onRegisterDay('2026-10-22', fn () => issueInvoice($withoutPan, [$line('Training', 4_000, false), $line('VPS', 10_000)]));
    onRegisterDay('2026-10-24', fn () => issueInvoice($withPan, [9_999], status: 'draft'));
    onRegisterDay('2026-10-27', fn () => registerNote(NoteRequest::creditAmount($a->id, 1_130, 'goodwill', 'Outage on 25 October')));
    onRegisterDay('2026-10-29', fn () => registerNote(NoteRequest::debit($b->id, 2_260, true, 'price_correction', 'Extra VPS storage')));
    onRegisterDay('2026-11-20', fn () => registerNote(NoteRequest::creditAmount($a->id, 565, 'goodwill', 'Second outage')));

    return ['a' => $a->fresh(), 'b' => $b->fresh()];
}

/** @return list<array<string, mixed>> */
function registerRows(string $key, array $filters): array
{
    $query = app(ReportExporter::class)->query($key, $filters, auth()->id());

    return app(ReportViewer::class)->page($key, $query, ReportQuery::MAX_LIMIT)->rows;
}

/** @return array<string, int|null> the money cells of a row in minor units */
function registerAmounts(array $row): array
{
    return array_map(fn ($cell) => $cell?->amount, array_intersect_key($row, array_flip(['total_sales', 'exempt', 'taxable', 'vat', 'export_value', 'total'])));
}

test('the Kartik register lists the invoices and notes of the month from their snapshots, and the totals net exactly (AC 37, 38, 40)', function () {
    ['a' => $a, 'b' => $b] = kartikRegisterFixture();
    actingAsStaff(['reports.vat_register.view']);

    // A later change to the live invoice does not reach the register.
    DB::table('invoices')->where('id', $a->id)->update(['total' => 1, 'tax1_amount' => 1]);

    $rows = registerRows('vat_register', ['month' => '2083-07']);

    expect(array_column($rows, 'number'))->toBe([$a->number, $b->number, 'CN-1', 'DN-1'])
        ->and(array_column($rows, 'type'))->toBe(['Tax invoice', 'Tax invoice', 'Credit note', 'Debit note'])
        ->and(array_column($rows, 'bs_date'))->toBe(['2083-07-03', '2083-07-05', '2083-07-10', '2083-07-12'])
        ->and($rows[0]['ad_date']->format('Y-m-d'))->toBe('2026-10-20')
        ->and($rows[0])->toMatchArray(['buyer' => 'Everest Treks Pvt. Ltd.', 'pan' => '301234567', 'goods' => 'Web hosting + 1 more', 'quantity' => 2, 'unit' => 'service', 'reference' => null])
        ->and(registerAmounts($rows[0]))->toBe(['total_sales' => 15_000, 'exempt' => 0, 'taxable' => 15_000, 'vat' => 1_950, 'export_value' => 0, 'total' => 16_950])
        ->and($rows[1]['pan'])->toBeNull()
        ->and(registerAmounts($rows[1]))->toBe(['total_sales' => 14_000, 'exempt' => 4_000, 'taxable' => 10_000, 'vat' => 1_300, 'export_value' => 0, 'total' => 15_300])
        ->and($rows[2])->toMatchArray(['reference' => $a->number, 'reference_bs_date' => '2083-07-03'])
        ->and(registerAmounts($rows[2]))->toBe(['total_sales' => -1_000, 'exempt' => 0, 'taxable' => -1_000, 'vat' => -130, 'export_value' => 0, 'total' => -1_130])
        ->and(registerAmounts($rows[3]))->toBe(['total_sales' => 2_000, 'exempt' => 0, 'taxable' => 2_000, 'vat' => 260, 'export_value' => 0, 'total' => 2_260])
        ->and($rows[0]['total_sales']->currency)->toBe('NPR');

    $page = app(ReportViewer::class)->page('vat_register', app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id()), 10);
    $totals = collect($page->totals)->keyBy('bs_date')->map(registerAmounts(...));

    expect($totals->keys()->all())->toBe(['Tax invoices', 'Credit notes', 'Debit notes', 'Net'])
        ->and($totals['Net'])->toBe(['total_sales' => 30_000, 'exempt' => 4_000, 'taxable' => 26_000, 'vat' => 1_950 + 1_300 - 130 + 260, 'export_value' => 0, 'total' => 30_000 + 3_380])
        ->and($totals['Net']['vat'])->toBe($totals['Tax invoices']['vat'] + $totals['Credit notes']['vat'] + $totals['Debit notes']['vat']);

    // The Mangsir register holds only the later note, as a negative row (AC 37).
    expect(array_column(registerRows('vat_register', ['month' => '2083-08']), 'number'))->toBe(['CN-2']);
});

test('the note register lists the notes of the month with their reason, settlement and issuer (vat-register §6.4)', function () {
    ['a' => $a] = kartikRegisterFixture();
    actingAsStaff(['reports.tax_note_register.view']);

    $rows = registerRows('tax_note_register', ['month' => '2083-07']);

    expect(array_column($rows, 'number'))->toBe(['CN-1', 'DN-1'])
        ->and($rows[0])->toMatchArray(['type' => 'Credit note', 'invoice' => $a->number, 'invoice_bs_date' => '2083-07-03', 'reason_code' => 'goodwill', 'reason' => 'Outage on 25 October', 'settlement' => 'Balance reduced'])
        ->and(registerAmounts($rows[0]))->toBe(['taxable' => -1_000, 'exempt' => 0, 'vat' => -130, 'total' => -1_130])
        ->and(registerAmounts($rows[1]))->toBe(['taxable' => 2_000, 'exempt' => 0, 'vat' => 260, 'total' => 2_260]);
});

test('both registers page conformantly', function () {
    // The kit throws ReportConformanceFailure instead of asserting.
    $this->expectNotToPerformAssertions();
    kartikRegisterFixture();
    onRegisterDay('2026-10-30', fn () => registerNote(NoteRequest::creditAmount(Invoice::query()->whereNotNull('number')->orderBy('id')->firstOrFail()->id, 226, 'goodwill', 'Third')));
    actingAsStaff(['reports.vat_register.view', 'reports.tax_note_register.view']);

    ReportConformance::assertPages('vat_register', app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id()), 2);
    ReportConformance::assertPages('tax_note_register', app(ReportExporter::class)->query('tax_note_register', ['month' => '2083-07'], auth()->id()), 2);
    ReportConformance::assertQueryBudget('vat_register', app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id()));
    ReportConformance::assertQueryBudget('tax_note_register', app(ReportExporter::class)->query('tax_note_register', ['month' => '2083-07'], auth()->id()));
});

test('a USD invoice is converted with its stored rate, per document, and a full credit note nets to zero (AC 41)', function () {
    DB::table('currencies')->where('code', 'NPR')->update(['exchange_rate' => '134.25']);
    $client = Client::factory()->create(['country' => 'US', 'currency' => 'USD']);
    $invoice = onRegisterDay('2026-10-20', fn () => issueInvoice($client, [10_001]))->fresh(['items']);
    DB::table('currencies')->where('code', 'NPR')->update(['exchange_rate' => '150']);
    onRegisterDay('2026-10-21', fn () => registerNote(NoteRequest::creditLines($invoice->id, $invoice->items->mapWithKeys(fn ($item) => [$item->id => $item->amount + $item->tax_amount])->all(), 'invoice_cancelled', 'Cancelled')));
    actingAsStaff(['reports.vat_register.view']);

    $rows = registerRows('vat_register', ['month' => '2083-07']);
    $net = collect(app(ReportViewer::class)->page('vat_register', app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id()), 10)->totals)->last();

    expect($invoice->report_rate)->toBe('134.250000000000')
        ->and($rows[0]['total_sales']->amount)->toBe(1_342_634) // 10,001 × 134.25 = 1,342,634.25, half up per document
        ->and($rows[0])->toMatchArray(['currency' => 'USD', 'rate' => '134.250000'])
        ->and($rows[0]['original_amount']->amount)->toBe(10_001)
        ->and($rows[1]['total_sales']->amount)->toBe(-1_342_634)
        ->and(registerAmounts($net))->toBe(['total_sales' => 0, 'exempt' => 0, 'taxable' => 0, 'vat' => 0, 'export_value' => 0, 'total' => 0]);
});

test('the fiscal year runs from 1 Shrawan to the end of Asar and its view has 12 month rows (AC 39)', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $lastOfAsar = BikramSambat::toAd(2083, 3, BikramSambat::daysInMonth(2083, 3))->format('Y-m-d');
    $firstOfShrawan = BikramSambat::toAd(2083, 4, 1)->format('Y-m-d');
    $old = onRegisterDay($lastOfAsar, fn () => issueInvoice($client, [10_000]));
    $new = onRegisterDay($firstOfShrawan, fn () => issueInvoice($client, [20_000]));
    actingAsStaff(['reports.vat_register.view']);

    $query = app(ReportExporter::class)->query('vat_register', ['year' => '2082.083'], auth()->id());
    $period = RegisterPeriod::of($query, app(CompanyClock::class));
    $page = app(ReportViewer::class)->page('vat_register', $query, 10);

    expect($period->from)->toBe(BikramSambat::toAd(2082, 4, 1)->format('Y-m-d'))->and($period->to)->toBe($lastOfAsar)
        ->and(array_column($page->rows, 'number'))->toBe([$old->number])
        ->and(array_column(array_slice($page->totals, 0, 12), 'bs_date'))->toBe(['Shrawan 2082', 'Bhadra 2082', 'Asoj 2082', 'Kartik 2082', 'Mangsir 2082', 'Poush 2082', 'Magh 2082', 'Falgun 2082', 'Chaitra 2082', 'Baisakh 2083', 'Jestha 2083', 'Asar 2083'])
        ->and($page->totals[11]['vat']->amount)->toBe(1_300)
        ->and($page->totals)->toHaveCount(16)
        ->and(array_column(registerRows('vat_register', ['year' => '2083.084']), 'number'))->toBe([$new->number])
        ->and(array_column(registerRows('vat_register', ['from' => $lastOfAsar, 'to' => $firstOfShrawan]), 'number'))->toBe([$old->number, $new->number]);
});

test('the default period is the last closed BS month on the company calendar (AC 43)', function () {
    CarbonImmutable::setTestNow('2026-11-16 18:15:00 UTC');
    expect(RegisterPeriod::lastClosedMonth(app(CompanyClock::class))->firstMonth)->toBe('2083-07');

    CarbonImmutable::setTestNow('2026-11-16 18:14:59 UTC');
    expect(RegisterPeriod::lastClosedMonth(app(CompanyClock::class))->firstMonth)->toBe('2083-06');
});

test('a period filter is validated in plain words: one kind at a time, inside the BS table, a year at most', function (array $filters, string $field, ?string $message) {
    actingAsStaff(['reports.vat_register.view']);

    expect(fn () => app(ReportExporter::class)->query('vat_register', $filters, auth()->id()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field)
            ->and($message === null || $e->errors()[$field][0] === $message)->toBeTrue());
})->with([
    'month and year' => [['month' => '2083-07', 'year' => '2083.084'], 'month', null],
    'month 13' => [['month' => '2083-13'], 'month', 'Choose a BS month from the list.'],
    'outside the table' => [['month' => '2099-01'], 'month', 'Choose a BS month from the list.'],
    'year halves disagree' => [['year' => '2083.090'], 'year', 'Choose a BS fiscal year from the list.'],
    'open range' => [['from' => '2026-10-01'], 'to', null],
    'range over a year' => [['from' => '2025-10-01', 'to' => '2026-10-02'], 'to', 'Choose a date range of one year or less, or pick a BS fiscal year.'],
]);

test('an AD range of one year is accepted and titled in the app date format', function () {
    actingAsStaff(['reports.vat_register.view']);
    $query = app(ReportExporter::class)->query('vat_register', ['from' => '2025-10-01', 'to' => '2026-10-01'], auth()->id());

    expect(RegisterPeriod::of($query, app(CompanyClock::class))->label())->toBe(CarbonImmutable::parse('2025-10-01')->format(DateFormats::current()).' – '.CarbonImmutable::parse('2026-10-01')->format(DateFormats::current()));
});

test('only a Nepal buyer has a PAN, and only Nepal business buyers without one are warned about', function () {
    $india = Client::factory()->create(['country' => 'IN', 'currency' => 'NPR', 'company_name' => 'Pune Cloud LLP', 'tax_id' => '27AAPFU0939F1ZV']);
    $usa = Client::factory()->create(['country' => 'US', 'currency' => 'NPR', 'company_name' => 'Northwind Labs', 'tax_id' => null]);
    onRegisterDay('2026-10-20', fn () => [issueInvoice($india, [10_000]), issueInvoice($usa, [10_000])]);
    actingAsStaff(['reports.vat_register.view']);

    $query = app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id());

    expect(array_column(registerRows('vat_register', ['month' => '2083-07']), 'pan'))->toBe([null, null])
        ->and(app(ReportRegistry::class)->get('vat_register')->summary($query)->withoutPan)->toBe(0);
});

/**
 * Foreign-currency documents at awkward stored rates: USD invoices to a Nepal client (so VAT applies), one export with
 * both a taxable and an exempt part, and credit notes, so rounding meets .5 boundaries with both signs.
 */
function awkwardRateRegisterFixture(): void
{
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'USD', 'company_name' => 'Annapurna Cloud', 'tax_id' => '302222222']);
    $rates = ['134.25', '151.939999996478', '0.5', '2.5', '0.333333333333'];
    $invoices = onRegisterDay('2026-10-20', fn () => array_map(fn (int $amount) => issueInvoice($client, [$amount, 3]), [10_001, 7, 3, 12_345, 1]));

    foreach ($invoices as $i => $invoice) {
        DB::table('invoices')->where('id', $invoice->id)->update(['report_rate' => $rates[$i]]);
    }

    // Odd taxable and exempt parts at 2.5 each end in .5: rounding them separately is one paisa more than rounding
    // the export value once.
    DB::table('invoices')->where('id', $invoices[3]->id)->update(['supply_class' => 'export', 'fiscal_taxable' => 12_345, 'fiscal_exempt' => 3]);
    onRegisterDay('2026-10-22', fn () => array_map(fn (int $i) => registerNote(NoteRequest::creditAmount($invoices[$i]->id, 5, 'goodwill', 'Rounding')), [0, 1, 2]));
    onRegisterDay('2026-10-23', fn () => registerNote(NoteRequest::debit($invoices[4]->id, 3, true, 'price_correction', 'Rounding')));
}

function registerSummaryWith(?bool $sql, string $month = '2083-07'): RegisterSummary
{
    app()->forgetScopedInstances();
    $documents = app(RegisterDocuments::class);
    $documents->convertInSql = $sql;

    return $documents->summary(RegisterPeriod::of(app(ReportExporter::class)->query('vat_register', ['month' => $month], auth()->id()), app(CompanyClock::class)), false);
}

test('the totals equal the sum of the rows, on awkward rates and both signs', function () {
    awkwardRateRegisterFixture();
    actingAsStaff(['reports.vat_register.view']);

    $rows = registerRows('vat_register', ['month' => '2083-07']);
    $sum = fn (string $key): int => array_sum(array_map(fn (array $row): int => $row[$key]?->amount ?? 0, $rows));
    $net = registerSummaryWith(null)->net();

    expect($rows)->toHaveCount(9)
        ->and([$net['total_sales'], $net['taxable'], $net['exempt'], $net['vat'], $net['export_value']])
        ->toBe([$sum('total_sales'), $sum('taxable'), $sum('exempt'), $sum('vat'), $sum('export_value')]);
});

/**
 * Two more Kartik invoices: one with India GST only (not Nepal VAT) and one whose tax levels were never classified.
 *
 * @return array{gst: Invoice, loose: Invoice}
 */
function unclassifiedRegisterFixture(): array
{
    $gst = TaxRule::factory()->create(['name' => 'GST', 'level' => 1, 'country' => 'IN', 'rate' => '18.000']);
    $india = Client::factory()->create(['country' => 'IN', 'currency' => 'NPR']);
    $nepal = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);

    $other = onRegisterDay('2026-10-21', fn () => issueInvoice($india, [10_000]));
    $loose = onRegisterDay('2026-10-23', fn () => issueInvoice($nepal, [20_000]));
    DB::table('invoices')->where('id', $loose->id)->update(['fiscal_tax1_is_vat' => null, 'fiscal_tax2_is_vat' => null]);

    expect($other->fresh())->tax1_rule_id->toBe($gst->id)->supply_class->toBe('exempt')->fiscal_tax1_is_vat->toBeFalse();

    return ['gst' => $other->fresh(), 'loose' => $loose->fresh()];
}

test('another tax is not VAT, and a document whose tax was never classified is left out of VAT and counted', function () {
    kartikRegisterFixture();
    unclassifiedRegisterFixture();
    actingAsStaff(['reports.vat_register.view']);

    $rows = collect(registerRows('vat_register', ['month' => '2083-07']))->keyBy('number');
    $summary = registerSummaryWith(null);
    $gst = $rows->first(fn (array $row) => $row['total_sales']?->amount === 10_000 && $row['vat']?->amount === 0);
    $loose = $rows->first(fn (array $row) => $row['total_sales'] === null);

    // The GST invoice is exempt sales of its whole value; its 18% is not VAT. The loose one is listed with blank amounts, out
    // of every total, and counted in the warning.
    expect(registerAmounts($gst))->toMatchArray(['exempt' => 10_000, 'taxable' => 0, 'vat' => 0, 'total' => 10_000])
        ->and(registerAmounts($loose))->toBe(['total_sales' => null, 'exempt' => null, 'taxable' => null, 'vat' => null, 'export_value' => null, 'total' => null])
        ->and($summary->unclassified)->toBe(1)
        // Left out of the sums, still counted for the return.
        ->and($summary->counts['invoice'])->toBe(count(array_filter($rows->all(), fn (array $row): bool => $row['type'] === 'Tax invoice')))
        ->and($summary->net()['taxable'])->toBe(collect(registerRows('vat_register', ['month' => '2083-07']))->sum(fn (array $row): int => $row['taxable']?->amount ?? 0));
});

test('an export value is rounded once, as taxable plus exempt', function () {
    awkwardRateRegisterFixture();
    actingAsStaff(['reports.vat_register.view']);

    $export = collect(registerRows('vat_register', ['month' => '2083-07']))->first(fn (array $row): bool => $row['export_value']?->amount !== 0 && $row['export_value'] !== null);

    // (12,345 + 3) × 2.5 = 30,870; rounding the parts separately would give 30,863 + 8 = 30,871.
    expect($export['export_value']->amount)->toBe(30_870)
        ->and(registerSummaryWith(null)->net()['export_value'])->toBe(30_870);
});

test('a document with no stored rate has blank amounts, export value included', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'USD']);
    $invoice = onRegisterDay('2026-10-20', fn () => issueInvoice($client, [10_000]));
    DB::table('invoices')->where('id', $invoice->id)->update(['report_rate' => null]);
    actingAsStaff(['reports.vat_register.view']);

    $row = registerRows('vat_register', ['month' => '2083-07'])[0];

    expect([$row['total_sales'], $row['taxable'], $row['exempt'], $row['vat'], $row['export_value']])->toBe([null, null, null, null, null]);
});

test('a page past the last shows the last page', function () {
    kartikRegisterFixture();
    actingAsStaff(['reports.vat_register.view']);

    $this->get(route('admin.billing.vat-register.index', ['month' => '2083-07', 'page' => 999]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.current_page', 1)->has('rows.data', 4));
});

test('on PostgreSQL the SQL conversion equals the per-document PHP conversion', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The SQL conversion runs on PostgreSQL only.');
    }

    awkwardRateRegisterFixture();
    unclassifiedRegisterFixture();
    actingAsStaff(['reports.vat_register.view']);

    $sql = registerSummaryWith(true);
    $php = registerSummaryWith(false);

    expect($sql->byKind)->toBe($php->byKind)->and($sql->byMonth)->toBe($php->byMonth)->and($sql->unclassified)->toBe($php->unclassified)->toBe(1)
        ->and($sql->net()['vat'])->not->toBe(0);
});

test('the totals cost a fixed number of queries as converted documents and notes grow, and never page with OFFSET', function () {
    $npr = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $usd = Client::factory()->create(['country' => 'NP', 'currency' => 'USD']);
    actingAsStaff(['reports.vat_register.view']);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    $summaryQueries = function () use (&$queries): array {
        $queries = [];
        registerSummaryWith(null);

        return array_values(array_filter($queries, fn (string $sql): bool => ! str_contains($sql, 'settings')));
    };
    $seed = function (int $count) use ($npr, $usd): void {
        foreach (range(1, $count) as $i) {
            onRegisterDay('2026-10-20', fn () => issueInvoice($npr, [10_000]));
            $invoice = onRegisterDay('2026-10-20', fn () => issueInvoice($usd, [10_001]));
            onRegisterDay('2026-10-21', fn () => registerNote(NoteRequest::creditAmount($invoice->id, 113, 'goodwill', 'Budget')));
        }
    };

    $seed(1);
    $small = $summaryQueries();
    $seed(8);
    $large = $summaryQueries();

    expect(count($large))->toBe(count($small))
        ->and(array_filter($large, fn (string $sql): bool => str_contains($sql, 'offset')))->toBe([]);

    // PostgreSQL converts in SQL, so no query reads documents one chunk at a time.
    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(array_filter($large, fn (string $sql): bool => str_contains($sql, 'limit')))->toBe([]);
    }
});

test('a refused fiscal year or range keeps its error and its value in the picker', function (array $filters, string $field) {
    actingAsStaff(['reports.vat_register.view']);

    $this->get(route('admin.billing.vat-register.index', $filters))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has("filterErrors.{$field}")
            ->where('submitted', $filters));
})->with([
    'year' => [['year' => '2083.090'], 'year'],
    'range over a year' => [['from' => '2025-01-01', 'to' => '2026-10-01'], 'to'],
]);

test('the screen shows the register with totals, header and warnings, and refuses without the view permission', function () {
    kartikRegisterFixture();
    CarbonImmutable::setTestNow('2026-11-20 06:00:00');

    actingAsStaff(['reports.vat_register.export']);
    $this->get(route('admin.billing.vat-register.index'))->assertForbidden();

    actingAsStaff(['reports.vat_register.view']);
    $this->get(route('admin.billing.vat-register.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/vat-register/index')
            ->where('period.label', 'Kartik 2083 (month 7)')
            ->where('rows.total', 4)
            ->where('totals.3.vat', ['amount' => 3_380, 'currency' => 'NPR'])
            ->where('header.0', ['Seller PAN', '609876543'])
            ->where('warnings.withoutPan', 2)
            ->where('tabs', ['register'])
            ->where('can.export', false));
});

test('the export needs the export permission and a recent password, and writes one report.exported entry', function () {
    kartikRegisterFixture();
    CarbonImmutable::setTestNow('2026-11-20 06:00:00');
    $url = route('admin.billing.vat-register.export');

    actingAsStaff(['reports.vat_register.view']);
    confirmPassword();
    $this->get($url)->assertForbidden();
    $this->flushSession();

    actingAsStaff(['reports.vat_register.export']);
    $this->getJson($url)->assertStatus(423);
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);

    confirmPassword();
    $response = $this->get($url)->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\u{FEFF}")->toContain('Everest Treks Pvt. Ltd.')->toContain(',-10.00,')->toContain('Kartik 2083 (month 7)')
        ->and($response->headers->get('Content-Disposition'))->toContain('vat-register-2083-07-kartik.csv')
        ->and(ActivityLog::query()->where('event', 'report.exported')->sole()->properties['filters'] ?? null)->toBe(['month' => '2083-07']);
});

test('the screen is rate-limited per staff member', function () {
    actingAsStaff(['reports.vat_register.view']);

    for ($i = 0; $i < BillingServiceProvider::REGISTER_REQUESTS_PER_MINUTE; $i++) {
        $this->get(route('admin.billing.vat-register.index'))->assertOk();
    }

    $this->get(route('admin.billing.vat-register.index'))->assertTooManyRequests();
});

test('both registers are registered with a sensitive export', function () {
    $registry = app(ReportRegistry::class);

    expect($registry->keys())->toContain('vat_register', 'tax_note_register')
        ->and(app(PermissionRegistry::class)->isSensitive('reports.vat_register.export'))->toBeTrue()
        ->and(app(PermissionRegistry::class)->isSensitive('reports.tax_note_register.export'))->toBeTrue();
});

/*
| Layouts and exports (VAT PR 7; vat-register §7, AC 44, 46, 47).
*/

/** @return list<list<string>> the CSV's rows, BOM removed */
function registerCsv(string $csv): array
{
    $lines = preg_split('/\r?\n/', rtrim(substr($csv, strlen("\u{FEFF}")), "\r\n")) ?: [];

    return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);
}

function registerExportUrl(array $query = []): string
{
    return route('admin.billing.vat-register.export', ['month' => '2083-07', ...$query]);
}

test('the IRD-layout CSV has the header rows with the period\'s counts, then Schedule 9\'s 13 columns in order with their Nepali headings, then ours (AC 44)', function () {
    ['a' => $a] = kartikRegisterFixture();
    CarbonImmutable::setTestNow('2026-11-20 06:00:00');
    actingAsStaff(['reports.vat_register.export']);
    confirmPassword();

    $response = $this->get(registerExportUrl(['layout' => 'ird']))->assertOk();
    $csv = $response->streamedContent();
    $rows = registerCsv($csv);

    expect(array_slice($rows, 0, 7))->toBe([
        ['Seller PAN', '609876543'],
        ['Seller name', 'Himal Hosting Pvt. Ltd.'],
        ['Fiscal year (BS)', '2083/84'],
        ['Tax period', 'Kartik 2083 (month 7)'],
        ['Tax invoices issued', '2'],
        ['Credit notes issued', '1'],
        ['Debit notes issued', '1'],
    ])->and($rows[7])->toBe([
        // Goods, quantity and unit are gone (25th amendment, 2080); "निर्यात" replaces "निकासी" (26th, 2081).
        'मिति', 'बीजक नम्बर', 'खरिदकर्ताको नाम', 'खरिदकर्ताको स्थायी लेखा नम्बर', 'जम्मा बिक्री / निर्यात मूल्य (रु)',
        'स्थानीय कर छुटको बिक्री मूल्य (रु)', 'करयोग्य बिक्री मूल्य (रु)', 'करयोग्य बिक्री कर (रु)',
        'निर्यात गरेको वस्तु वा सेवाको मूल्य (रु)', 'निर्यात गरेको देश', 'निर्यात प्रज्ञापनपत्र नम्बर', 'निर्यात प्रज्ञापनपत्र मिति', 'कुल जम्मा',
        'Date (AD)', 'Document', 'Reference invoice', 'Reference date (BS)', 'Document currency', 'Document amount', 'Rate',
    ])->and($rows[8])->toBe(['2083-07-03', $a->number, 'Everest Treks Pvt. Ltd.', '301234567', '150.00', '0.00', '150.00', '19.50', '0.00', '', '', '', '169.50', '2026-10-20', 'Tax invoice', '', '', '', '', ''])
        ->and($rows[10])->toBe(['2083-07-10', 'CN-1', 'Everest Treks Pvt. Ltd.', '301234567', '-10.00', '0.00', '-10.00', '-1.30', '0.00', '', '', '', '-11.30', '2026-10-27', 'Credit note', $a->number, '2083-07-03', '', '', ''])
        ->and(array_column(array_slice($rows, 12), 0))->toBe(['Tax invoices', 'Credit notes', 'Debit notes', 'Net'])
        ->and(end($rows)[12])->toBe('333.80')
        ->and($csv)->not->toContain('निकासी')->not->toContain('वस्तु वा सेवाको नाम')->not->toContain('परिमाण')
        ->and($response->headers->get('Content-Disposition'))->toContain('vat-register-ird-2083-07-kartik.csv')
        ->and(ActivityLog::query()->where('event', 'report.exported')->sole()->properties)->toMatchArray(['layout' => 'ird', 'format' => 'csv', 'rows' => 4]);
});

test('the counts of each kind cover the period only, include documents left out of the sums, and the note register counts notes only (Schedule 10)', function () {
    kartikRegisterFixture();
    $usd = Client::factory()->create(['country' => 'NP', 'currency' => 'USD']);
    $unconverted = onRegisterDay('2026-10-23', fn () => issueInvoice($usd, [10_000]));
    DB::table('invoices')->where('id', $unconverted->id)->update(['report_rate' => null]);
    actingAsStaff(['reports.vat_register.view', 'reports.tax_note_register.view']);
    $header = fn (string $key, string $month): array => app(ReportRegistry::class)->get($key)->headerBlock(app(ReportExporter::class)->query($key, ['month' => $month], auth()->id()));

    expect(array_slice($header('vat_register', '2083-07'), 4))->toBe([['Tax invoices issued', '3'], ['Credit notes issued', '1'], ['Debit notes issued', '1']])
        ->and(registerSummaryWith(null)->unconverted)->toBe(1)
        ->and(array_slice($header('vat_register', '2083-08'), 4))->toBe([['Tax invoices issued', '0'], ['Credit notes issued', '1'], ['Debit notes issued', '0']])
        ->and(array_slice($header('tax_note_register', '2083-07'), 4))->toBe([['Credit notes issued', '1'], ['Debit notes issued', '1']]);
});

test('the PDF prints the summary columns, which fit A4 landscape, with the header block and totals, for both registers', function (string $tab, string $key, array $columns, string $label) {
    kartikRegisterFixture();
    CarbonImmutable::setTestNow('2026-11-20 06:00:00');
    actingAsStaff(["reports.{$key}.export"]);
    confirmPassword();
    $data = [];
    View::composer('reports.pdf', function ($view) use (&$data) {
        $data = $view->getData();
    });

    $response = $this->get(registerExportUrl(['tab' => $tab, 'format' => 'pdf', 'layout' => 'ird']))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->streamedContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain(str_replace('_', '-', $key).'-2083-07-kartik.pdf')
        ->and(array_map(fn ($column) => $column->key, $data['columns']))->toBe($columns)
        ->and(array_sum(array_map('floatval', $data['widths'])))->toBeLessThanOrEqual(100.0)
        ->and($data['header'][0])->toBe(['Seller PAN', '609876543'])
        ->and(array_column($data['totals'], $columns[0]))->toContain($label, 'Net')
        // A PDF is the summary whatever layout was asked for.
        ->and(ActivityLog::query()->where('event', 'report.exported')->sole()->properties)->toMatchArray(['layout' => 'summary', 'format' => 'pdf']);
})->with([
    'VAT register' => ['register', 'vat_register', ['bs_date', 'number', 'type', 'buyer', 'pan', 'total_sales', 'exempt', 'taxable', 'vat', 'export_value'], 'Tax invoices'],
    'note register' => ['notes', 'tax_note_register', ['number', 'bs_date', 'type', 'invoice', 'buyer', 'pan', 'taxable', 'exempt', 'vat', 'total'], 'Credit notes'],
]);

test('the register offers CSV (detail), CSV (IRD sales book) and PDF; the note register has no IRD layout (AC 47)', function () {
    kartikRegisterFixture();
    actingAsStaff(['reports.vat_register.view', 'reports.vat_register.export', 'reports.tax_note_register.view', 'reports.tax_note_register.export']);

    $this->get(route('admin.billing.vat-register.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('exports', [
        ['label' => 'CSV (detail)', 'format' => 'csv', 'layout' => 'detail'],
        ['label' => 'CSV (IRD sales book)', 'format' => 'csv', 'layout' => 'ird'],
        ['label' => 'PDF', 'format' => 'pdf', 'layout' => 'summary'],
    ]));
    $this->get(route('admin.billing.vat-register.index', ['tab' => 'notes']))->assertInertia(fn (AssertableInertia $page) => $page->where('exports', [
        ['label' => 'CSV (detail)', 'format' => 'csv', 'layout' => 'detail'],
        ['label' => 'PDF', 'format' => 'pdf', 'layout' => 'summary'],
    ]));

    confirmPassword();
    $this->getJson(registerExportUrl(['tab' => 'notes', 'layout' => 'ird']))->assertUnprocessable()->assertJsonValidationErrors('export');
    $this->getJson(registerExportUrl(['format' => 'xlsx']))->assertUnprocessable()->assertJsonValidationErrors('export');
    $this->getJson(registerExportUrl(['format' => ['csv']]))->assertUnprocessable()->assertJsonValidationErrors('export');
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('without the export permission no export is offered and every format is refused; with it each asks for a recent password (AC 46)', function () {
    kartikRegisterFixture();
    $formats = [['layout' => 'detail'], ['layout' => 'ird'], ['format' => 'pdf']];

    actingAsStaff(['reports.vat_register.view']);
    confirmPassword();
    $this->get(route('admin.billing.vat-register.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('exports', []));

    foreach ($formats as $query) {
        $this->get(registerExportUrl($query))->assertForbidden();
    }
    $this->flushSession();

    actingAsStaff(['reports.vat_register.export']);

    foreach ($formats as $query) {
        $this->getJson(registerExportUrl($query))->assertStatus(423);
    }

    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('the seeded Billing role may export both registers; the wildcard alone does not grant it (vat-register §8.1)', function () {
    expect(SystemRoles::permissions('billing'))->toContain('reports.*', 'reports.vat_register.export', 'reports.tax_note_register.export')
        ->and(SystemRoles::permissions('support'))->not->toContain('reports.vat_register.export');
});

test('issuing a tax invoice and a note sends nothing to CBMS and leaves its status empty (AC 47, D5)', function () {
    Http::fake();

    kartikRegisterFixture();

    Http::assertNothingSent();
    expect(TaxNote::query()->count())->toBe(3)
        ->and(TaxNote::query()->whereNotNull('cbms_status')->orWhereNotNull('cbms_reference')->exists())->toBeFalse();
});
