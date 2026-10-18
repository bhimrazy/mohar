<?php

use App\Contracts\Billing\TaxCalculator;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Enums\TaxExemptReason;
use App\Modules\Billing\Models\TaxRule;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    billingSettings(['tax.enabled' => true]);
});

test('issued invoices carry exclusive tax from the most specific rule', function () {
    TaxRule::factory()->create(['name' => 'Sales tax', 'rate' => '5.000']);
    TaxRule::factory()->create(['name' => 'NY tax', 'country' => 'US', 'state' => 'NY', 'rate' => '8.875']);
    TaxRule::factory()->create(['name' => 'US tax', 'country' => 'US', 'rate' => '6.000']);

    $invoice = issueInvoice(billingClient(['country' => 'US', 'state' => 'NY']), [10_000]);

    expect($invoice)->tax1_name->toBe('NY tax')->tax1_amount->toBe(888)->total->toBe(10_888)
        ->and(issueInvoice(billingClient(['country' => 'US', 'state' => 'TX']), [10_000])->tax1_name)->toBe('US tax')
        ->and(issueInvoice(billingClient(['country' => 'CA', 'state' => 'ON']), [10_000])->tax1_name)->toBe('Sales tax');
});

test('inclusive mode keeps the catalog price as the total', function () {
    billingSettings(['tax.mode' => 'inclusive']);
    TaxRule::factory()->create(['rate' => '20.000']);

    $invoice = issueInvoice(billingClient(), [1_200]);

    expect($invoice)->subtotal->toBe(1_000)->tax1_amount->toBe(200)->total->toBe(1_200)->tax_inclusive->toBeTrue();
});

test('compound two-level tax', function () {
    billingSettings(['tax.compound' => true]);
    TaxRule::factory()->create(['name' => 'GST', 'level' => 1, 'rate' => '10.000']);
    TaxRule::factory()->create(['name' => 'PST', 'level' => 2, 'rate' => '5.000']);

    $invoice = issueInvoice(billingClient(), [10_000]);

    expect($invoice)->tax1_amount->toBe(1_000)->tax2_amount->toBe(550)->total->toBe(11_550)->tax_compound->toBeTrue();
});

test('tax-exempt clients pay no tax', function () {
    TaxRule::factory()->create(['rate' => '20.000']);

    $invoice = issueInvoice(billingClient(['tax_exempt' => true]), [10_000]);

    expect($invoice)->tax1_amount->toBe(0)->total->toBe(10_000)->tax_exempt_reason->toBe(TaxExemptReason::Exempt);
});

test('EU B2B customers abroad with a valid VAT ID are reverse charged, home customers pay VAT', function () {
    billingSettings(['vat.enabled' => true, 'vat.home_country' => 'DE']);
    TaxRule::factory()->create(['name' => 'MwSt', 'country' => 'DE', 'rate' => '19.000']);
    TaxRule::factory()->create(['name' => 'TVA', 'country' => 'FR', 'rate' => '20.000']);

    $abroad = issueInvoice(billingClient(['country' => 'FR', 'tax_id' => 'FR12345678901']), [10_000]);
    $home = issueInvoice(billingClient(['country' => 'DE', 'tax_id' => 'DE123456789']), [10_000]);
    $consumer = issueInvoice(billingClient(['country' => 'FR', 'tax_id' => null]), [10_000]);

    expect($abroad)->tax1_amount->toBe(0)->tax_exempt_reason->toBe(TaxExemptReason::ReverseCharge)
        ->and($home)->tax1_name->toBe('MwSt')->tax1_amount->toBe(1_900)
        ->and($consumer)->tax1_name->toBe('TVA')->tax1_amount->toBe(2_000);
});

test('changing a tax rule never alters issued invoices', function () {
    $rule = TaxRule::factory()->create(['rate' => '20.000']);
    $invoice = issueInvoice(billingClient(), [10_000]);

    $rule->update(['rate' => '25.000']);

    expect($invoice->fresh())->tax1_amount->toBe(2_000)->tax1_rate->toBe('20.000');
});

test('rules take effect from their effective date', function () {
    TaxRule::factory()->create(['rate' => '19.000']);
    TaxRule::factory()->create(['rate' => '21.000', 'priority' => 0, 'effective_from' => '2027-01-01']);
    $client = billingClient();

    $this->travelTo(CarbonImmutable::parse('2026-12-31'));
    expect(issueInvoice($client, [10_000])->tax1_amount)->toBe(1_900);

    $this->travelTo(CarbonImmutable::parse('2027-01-02'));
    expect(issueInvoice($client, [10_000])->tax1_amount)->toBe(2_100);
});

test('only configured item kinds are taxed and promotions reduce the base', function () {
    billingSettings(['tax.apply_to' => 'service']);
    TaxRule::factory()->create(['rate' => '10.000']);

    $totals = app(TaxCalculator::class)->calculate([
        new InvoiceLineData('Hosting', 10_000, billableType: BillableType::Service),
        new InvoiceLineData('Promo', -2_000, billableType: BillableType::Promotion),
        new InvoiceLineData('Domain', 1_000, billableType: BillableType::Domain),
    ], 'USD', 'US', null);

    expect($totals->taxAmount())->toBe(800)->and($totals->total)->toBe(9_800)
        ->and($totals->taxes[0])->toMatchArray(['name' => 'VAT', 'rate' => 10.0, 'amount' => 800]);
});

test('the tax calculator previews totals for a client', function () {
    TaxRule::factory()->create(['rate' => '20.000']);
    $client = billingClient();

    $totals = app(TaxCalculator::class)->calculateForClient([new InvoiceLineData('Hosting', 1_000)], $client->id, 'USD');

    expect($totals)->subtotal->toBe(1_000)->total->toBe(1_200);
});

test('tax disabled means no tax', function () {
    billingSettings(['tax.enabled' => false]);
    TaxRule::factory()->create(['rate' => '20.000']);

    expect(issueInvoice(billingClient(), [1_000])->total)->toBe(1_000);
});
