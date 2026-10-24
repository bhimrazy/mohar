<?php

use App\Contracts\Core\CompanyClock;
use App\Data\Reports\ReportQuery;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Reports\RegisterDocuments;
use App\Modules\Billing\Reports\RegisterPeriod;
use App\Modules\Billing\Reports\RegisterSummary;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportViewer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/helpers.php';

/*
| The register against the mode and its flags (review of #447): a mixed document, a document with only another tax, and the
| register with the mode never switched on. NPR, VAT 13%, Nepal time; Kartik 2083 runs 2026-10-18 to 2026-11-16.
*/

beforeEach(function () {
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'seller.pan' => '609876543', 'seller.legal_name' => 'Himal']);
    $this->vat = TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function modeRegDay(string $date, Closure $action): mixed
{
    CarbonImmutable::setTestNow($date.' 06:00:00');

    return $action();
}

function modeRegNote(NoteRequest $request)
{
    $issuer = app(TaxNoteIssuer::class);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

/** @return list<array<string, int|string|null>> */
function modeRegRows(): array
{
    $query = app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id());

    return array_map(
        fn (array $row): array => array_map(fn ($cell) => $cell?->amount, array_intersect_key($row, array_flip(['total_sales', 'exempt', 'taxable', 'vat', 'export_value']))) + ['number' => $row['number']],
        app(ReportViewer::class)->page('vat_register', $query, ReportQuery::MAX_LIMIT)->rows,
    );
}

function modeRegSummary(): RegisterSummary
{
    app()->forgetScopedInstances();

    return app(RegisterDocuments::class)->summary(RegisterPeriod::of(app(ReportExporter::class)->query('vat_register', ['month' => '2083-07'], auth()->id()), app(CompanyClock::class)), false);
}

test('a document with VAT on one level and another tax on the other counts only the VAT, and so does its credit note', function () {
    useNepalVatRule($this->vat);
    TaxRule::factory()->create(['name' => 'Tourism levy', 'level' => 2, 'country' => 'NP', 'rate' => '2.000']);
    $invoice = modeRegDay('2026-10-20', fn () => issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [10_000]))->fresh();
    $note = modeRegDay('2026-10-22', fn () => modeRegNote(NoteRequest::creditAmount($invoice->id, (int) round($invoice->fiscal_total / 2), 'goodwill', 'Half')));
    actingAsStaff(['reports.vat_register.view']);

    expect($invoice)->fiscal_tax1->toBe(1_300)->fiscal_tax2->toBe(200)->fiscal_tax1_is_vat->toBeTrue()->fiscal_tax2_is_vat->toBeFalse()
        ->and($note)->tax1_is_vat->toBeTrue()->tax2_is_vat->toBeFalse()
        ->and(array_column(modeRegRows(), 'vat'))->toBe([1_300, -650])
        ->and(modeRegSummary()->net())->toMatchArray(['taxable' => 5_000, 'vat' => 650, 'exempt' => 0]);
});

test('a GST-only invoice and its credit note net in exempt sales with no VAT', function () {
    useNepalVatRule($this->vat);
    TaxRule::factory()->create(['name' => 'GST', 'level' => 1, 'country' => 'IN', 'rate' => '18.000']);
    $invoice = modeRegDay('2026-10-20', fn () => issueInvoice(Client::factory()->create(['country' => 'IN', 'currency' => 'NPR']), [10_000]))->fresh();
    modeRegDay('2026-10-22', fn () => modeRegNote(NoteRequest::creditAmount($invoice->id, 5_900, 'goodwill', 'Half')));
    actingAsStaff(['reports.vat_register.view']);

    expect(array_column(modeRegRows(), 'exempt'))->toBe([10_000, -5_000])
        ->and(modeRegSummary()->net())->toMatchArray(['exempt' => 5_000, 'taxable' => 0, 'vat' => 0]);
});

test('with the mode never switched on the register lists nothing, shows no VAT figures and says why', function () {
    modeRegDay('2026-10-20', fn () => issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [10_000]));
    actingAsStaff(['reports.vat_register.view']);

    expect(modeRegRows())->toBe([])
        ->and(modeRegSummary())->unclassified->toBe(0)->and(modeRegSummary()->net()['vat'])->toBe(0);

    $this->get(route('admin.billing.vat-register.index', ['month' => '2083-07']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mode.enabled', false)->where('documents.total', 0)->where('warnings.unclassified', 0));

    Artisan::call('billing:fiscal-vat-split');
    expect(modeRegRows())->toBe([]);
});

test('only documents dated on or after the start day are listed, and earlier months are not offered', function () {
    modeRegDay('2026-10-20', fn () => issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [10_000]));
    useNepalVatRule($this->vat);
    billingSettings(['nepal_vat.enabled_from' => '2026-11-17']);
    $later = modeRegDay('2026-11-18', fn () => issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [20_000]));
    actingAsStaff(['reports.vat_register.view']);

    CarbonImmutable::setTestNow('2026-11-30 06:00:00');
    $this->get(route('admin.billing.vat-register.index', ['month' => '2083-07']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('documents.total', 0)->where('options.months.0.value', '2083-08')->has('options.months', 1));
    $this->get(route('admin.billing.vat-register.index', ['month' => '2083-08']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('documents.total', 1)->where('mode.started', true));

    expect($later->fresh()->fiscal_tax1_is_vat)->toBeTrue();
});
