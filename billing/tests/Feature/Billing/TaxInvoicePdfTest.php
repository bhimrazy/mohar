<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    billingSettings([
        'tax.enabled' => true, 'tax.home_country' => 'IN', 'tax.home_state' => 'MH',
        'seller.legal_name' => 'Hosting Co Pvt Ltd', 'seller.address' => "12 Marine Drive\nMumbai 400002", 'seller.gstin' => '27AAPFU0939F1ZV', 'seller.pan' => 'AAPFU0939F',
    ]);
    Currency::factory()->inr()->create();
    Currency::factory()->npr()->create();
    TaxRule::factory()->create(['name' => 'CGST', 'level' => 1, 'country' => 'IN', 'scope' => TaxRule::HOME_STATE, 'rate' => '9.000']);
    TaxRule::factory()->create(['name' => 'SGST', 'level' => 2, 'country' => 'IN', 'scope' => TaxRule::HOME_STATE, 'rate' => '9.000']);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

function renderInvoiceHtml(Invoice $invoice): string
{
    return view('billing::pdf.invoice', app(InvoicePdf::class)->viewData($invoice))->render();
}

test('an Indian GST invoice is a tax invoice with seller and buyer GSTIN, place of supply and SAC', function () {
    $client = Client::factory()->create(['country' => 'IN', 'currency' => 'INR', 'state' => 'Maharashtra', 'tax_id' => '27AAACR5055K1Z5', 'company_name' => 'Sharma Digital LLP']);
    $invoice = issueInvoice($client, [10_000_000]);

    $html = renderInvoiceHtml($invoice);

    expect($html)->toContain('Tax Invoice')
        ->toContain('Hosting Co Pvt Ltd')->toContain('GSTIN: 27AAPFU0939F1ZV')->toContain('PAN: AAPFU0939F')
        ->toContain('GSTIN: 27AAACR5055K1Z5')
        ->toContain('Place of supply')->toContain('Maharashtra (27)')
        ->toContain('998315')
        ->toContain('CGST @ 9%')->toContain('SGST @ 9%')
        ->toContain('₹1,18,000.00')
        ->not->toContain('>Paid<');

    expect($invoice->fresh()->seller_snapshot['gstin'])->toBe('27AAPFU0939F1ZV');
});

test('the seller details printed on an issued invoice never change', function () {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'IN', 'currency' => 'INR', 'state' => 'MH']), [10_000]);
    billingSettings(['seller.legal_name' => 'Renamed Ltd']);

    expect(renderInvoiceHtml($invoice->fresh()))->toContain('Hosting Co Pvt Ltd')->not->toContain('Renamed Ltd');
});

test('a Nepal VAT invoice labels the buyer PAN and formats rupees', function () {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'tax_id' => '301234567']), [1_000_000]);
    payInvoice($invoice, 500_000);

    $html = renderInvoiceHtml($invoice->fresh());

    expect($html)->toContain('Tax Invoice')->toContain('PAN/VAT No.: 301234567')->toContain('Rs 11,300.00')->toContain('−Rs 5,000.00')->toContain('All amounts in NPR')->toContain('Paid')
        ->not->toContain('Place of supply');
});

test('untaxed invoices are plain invoices without a SAC column', function () {
    billingSettings(['tax.enabled' => false]);
    $html = renderInvoiceHtml(issueInvoice(billingClient(), [12_345]));

    expect($html)->toContain('<h1>Invoice</h1>')->toContain('$123.45')->not->toContain('SAC');
});

test('invoice dates follow the date format setting and ignore values off the list', function () {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'IN', 'currency' => 'INR', 'state' => 'MH']), [10_000]);
    $due = $invoice->fresh()->due_date;

    app(Settings::class)->set('core.date_format', 'd/m/Y');
    expect(renderInvoiceHtml($invoice->fresh()))->toContain($due->format('d/m/Y'));

    app(Settings::class)->set('core.date_format', 'YYYY-MM-DD');
    expect(renderInvoiceHtml($invoice->fresh()))->toContain($due->format('M j, Y'))->not->toContain('YYYY');
});

test('the quote PDF prints its valid-until date in the date format setting', function () {
    $quote = Quote::factory()->create(['client_id' => Client::factory()->create(['currency' => 'NPR'])->id, 'currency' => 'NPR', 'valid_until' => '2026-11-05', 'tax1_amount' => 0, 'tax2_amount' => 0]);

    app(Settings::class)->set('core.date_format', 'j M Y');
    $html = view('billing::pdf.quote', app(InvoicePdf::class)->quoteViewData($quote))->render();

    expect($html)->toContain('5 Nov 2026')->not->toContain('2026-11-05');
});
