<?php

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\TaxNotePanel;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
| Notes and the Nepal VAT flags (VAT follow-up to ADR 0024, Amendment #432): a note copies its invoice's flags, is refused on
| a taxed invoice that was never classified, and the panel opens only once the mode is in effect.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    $this->vat = useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function vatFlagsInvoice(): Invoice
{
    return issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [10_000]);
}

function vatFlagsIssue(Invoice $invoice): TaxNote
{
    $request = NoteRequest::creditAmount($invoice->id, 1_130, 'goodwill', 'Outage');
    $issuer = app(TaxNoteIssuer::class);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

test('a note copies the Nepal VAT flags of its invoice', function () {
    $invoice = vatFlagsInvoice();

    expect(vatFlagsIssue($invoice))->tax1_is_vat->toBeTrue()->tax2_is_vat->toBeFalse();
});

test('a note on a taxed invoice that was never classified is refused in plain words, and the panel says why up front', function () {
    $invoice = vatFlagsInvoice();
    DB::table('invoices')->where('id', $invoice->id)->update(['fiscal_tax1_is_vat' => null, 'fiscal_tax2_is_vat' => null]);

    expect(fn () => vatFlagsIssue($invoice))->toThrow(BillingException::class, 'billing:fiscal-vat-split')
        ->and(TaxNote::query()->count())->toBe(0)
        ->and(app(TaxNoteIssuer::class)->creditBlocked($invoice->fresh()))->toContain('billing:fiscal-vat-split')
        ->and(app(TaxNoteIssuer::class)->debitBlocked($invoice->fresh()))->toContain('billing:fiscal-vat-split');
});

test('no note is written while the mode is off or before its start day, so none ever has NULL flags', function () {
    $invoice = vatFlagsInvoice();

    billingSettings(['nepal_vat.enabled' => false]);
    expect(fn () => vatFlagsIssue($invoice))->toThrow(BillingException::class, 'only while Nepal VAT mode is on');

    billingSettings(['nepal_vat.enabled' => true, 'nepal_vat.enabled_from' => '2026-11-17']);
    expect(fn () => vatFlagsIssue($invoice))->toThrow(BillingException::class, 'starts on 2026-11-17')
        ->and(TaxNote::query()->count())->toBe(0);
});

test('a zero-tax invoice with no flags still takes a note', function () {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'US', 'currency' => 'USD']), [10_000]);
    DB::table('invoices')->where('id', $invoice->id)->update(['fiscal_tax1_is_vat' => null, 'fiscal_tax2_is_vat' => null]);

    expect($invoice->fresh()->fiscal_tax1)->toBe(0)
        ->and(vatFlagsIssue($invoice->fresh()))->toBeInstanceOf(TaxNote::class);
});

test('the notes panel opens when the mode is in effect, not before its start day or while it is off', function () {
    $invoice = vatFlagsInvoice()->fresh();
    $panel = app(TaxNotePanel::class);

    expect($panel->issuable($invoice))->toBeTrue();

    billingSettings(['nepal_vat.enabled_from' => '2026-11-17']);
    expect($panel->issuable($invoice))->toBeFalse();

    billingSettings(['nepal_vat.enabled_from' => '2026-01-01', 'nepal_vat.enabled' => false]);
    expect($panel->issuable($invoice))->toBeFalse();
});
