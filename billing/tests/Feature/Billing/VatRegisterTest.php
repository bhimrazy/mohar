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
use App\Modules\Billing\Reports\RegisterPeriod;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\BikramSambat;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use App\Support\Reports\ReportViewer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Reports\ReportConformance;

require_once __DIR__.'/helpers.php';

/*
| The VAT sales register and the note register (VAT PR 6; vat-register §6, AC 37–43): NPR, VAT 13%, Nepal time.
| Kartik 2083 BS runs from 2026-10-18 to 2026-11-16 AD; Mangsir 2083 starts on 2026-11-17.
*/

beforeEach(function () {
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'seller.pan' => '609876543', 'seller.legal_name' => 'Himal Hosting Pvt. Ltd.']);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
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
        ->and(registerAmounts($rows[0]))->toBe(['total_sales' => 15_000, 'exempt' => 0, 'taxable' => 15_000, 'vat' => 1_950, 'export_value' => 0])
        ->and($rows[1]['pan'])->toBeNull()
        ->and(registerAmounts($rows[1]))->toBe(['total_sales' => 14_000, 'exempt' => 4_000, 'taxable' => 10_000, 'vat' => 1_300, 'export_value' => 0])
        ->and($rows[2]['reference'])->toBe($a->number.' (2083-07-03 BS)')
        ->and(registerAmounts($rows[2]))->toBe(['total_sales' => -1_000, 'exempt' => 0, 'taxable' => -1_000, 'vat' => -130, 'export_value' => 0])
        ->and(registerAmounts($rows[3]))->toBe(['total_sales' => 2_000, 'exempt' => 0, 'taxable' => 2_000, 'vat' => 260, 'export_value' => 0])
        ->and($rows[0]['total_sales']->currency)->toBe('NPR');

    $page = app(ReportViewer::class)->page('vat_register', app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id()), 10);
    $totals = collect($page->totals)->keyBy('bs_date')->map(registerAmounts(...));

    expect($totals->keys()->all())->toBe(['Tax invoices', 'Credit notes', 'Debit notes', 'Net'])
        ->and($totals['Net'])->toBe(['total_sales' => 30_000, 'exempt' => 4_000, 'taxable' => 26_000, 'vat' => 1_950 + 1_300 - 130 + 260, 'export_value' => 0])
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
    kartikRegisterFixture();
    onRegisterDay('2026-10-30', fn () => registerNote(NoteRequest::creditAmount(Invoice::query()->whereNotNull('number')->orderBy('id')->firstOrFail()->id, 226, 'goodwill', 'Third')));
    actingAsStaff(['reports.vat_register.view', 'reports.tax_note_register.view']);

    ReportConformance::assertPages('vat_register', app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id()), 2);
    ReportConformance::assertPages('tax_note_register', app(ReportExporter::class)->query('tax_note_register', ['month' => '2083-07'], auth()->id()), 2);
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
        ->and($rows[0]['foreign'])->toBe('USD 100.01 at 134.250000000000')
        ->and($rows[1]['total_sales']->amount)->toBe(-1_342_634)
        ->and(registerAmounts($net))->toBe(['total_sales' => 0, 'exempt' => 0, 'taxable' => 0, 'vat' => 0, 'export_value' => 0]);
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

test('a period filter is validated: one kind at a time, inside the BS table', function (array $filters, string $field) {
    actingAsStaff(['reports.vat_register.view']);

    expect(fn () => app(ReportExporter::class)->query('vat_register', $filters, auth()->id()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
})->with([
    'month and year' => [['month' => '2083-07', 'year' => '2083.084'], 'month'],
    'month 13' => [['month' => '2083-13'], 'month'],
    'outside the table' => [['month' => '2099-01'], 'month'],
    'year halves disagree' => [['year' => '2083.090'], 'year'],
    'open range' => [['from' => '2026-10-01'], 'to'],
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
    $url = route('admin.billing.vat-register.export', ['month' => '2083-07']);

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
        ->and(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(1);
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
