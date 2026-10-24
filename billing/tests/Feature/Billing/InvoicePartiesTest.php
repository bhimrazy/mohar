<?php

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    billingSettings([
        'tax.enabled' => true, 'tax.home_country' => 'NP',
        'seller.legal_name' => 'Himal Hosting Pvt. Ltd.', 'seller.address' => "Putalisadak\nKathmandu 44600",
        'seller.email' => 'billing@himal.example', 'seller.pan' => '600111222', 'seller.vat_number' => '600111222',
    ]);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

function nepalBuyerForParties(): Client
{
    [, $client] = actingAsClient(Client::factory()->create([
        'country' => 'NP', 'currency' => 'NPR', 'company_name' => 'Sagarmatha Traders', 'first_name' => 'Sita', 'last_name' => 'Gurung',
        'address_1' => 'Lakeside Road', 'city' => 'Pokhara', 'state' => 'Gandaki', 'postcode' => '33700', 'tax_id' => '301234567',
    ]));
    Currency::factory()->npr()->create();

    return $client;
}

test('the client invoice shows the seller with its PAN/VAT number and the buyer with their PAN', function () {
    $client = nepalBuyerForParties();
    $invoice = issueInvoice($client, [1_000_000]);

    $this->get(route('client.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/billing/invoices/show')
            ->where('parties.document', 'tax_invoice')
            ->where('parties.seller.name', 'Himal Hosting Pvt. Ltd.')
            ->where('parties.seller.address', "Putalisadak\nKathmandu 44600")
            ->where('parties.seller.tax_ids', [['label' => 'PAN/VAT No.', 'value' => '600111222']])
            ->where('parties.seller_from_settings', false)
            ->where('parties.buyer.name', 'Sagarmatha Traders')
            ->where('parties.buyer.contact', 'Sita Gurung')
            ->where('parties.buyer.address', ['Lakeside Road', 'Pokhara, Gandaki 33700', 'Nepal'])
            ->where('parties.buyer.tax_id', ['label' => 'PAN/VAT No.', 'value' => '301234567'])
            ->where('parties.place_of_supply', null));
});

test('a numbered invoice keeps the buyer and seller it was issued with', function () {
    $client = nepalBuyerForParties();
    $invoice = issueInvoice($client, [1_000_000]);
    $client->forceFill(['company_name' => 'Renamed Pvt. Ltd.', 'tax_id' => '309999999'])->save();
    billingSettings(['seller.legal_name' => 'New Seller Ltd', 'seller.pan' => '600999999', 'seller.vat_number' => '600999999']);

    $this->get(route('client.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('parties.buyer.name', 'Sagarmatha Traders')
            ->where('parties.buyer.tax_id.value', '301234567')
            ->where('parties.seller.name', 'Himal Hosting Pvt. Ltd.')
            ->where('parties.seller.tax_ids.0.value', '600111222'));

    billingStaff();
    $this->get(route('admin.billing.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/billing/invoices/show')
            ->where('parties.buyer.name', 'Sagarmatha Traders')
            ->where('parties.seller.name', 'Himal Hosting Pvt. Ltd.'));
});

test('in paid numbering the invoice is a proforma until paid, then a tax invoice for the buyer it was raised for', function () {
    billingSettings(['sequential_paid_numbering' => true]);
    $client = nepalBuyerForParties();
    $invoice = issueInvoice($client, [1_000_000]);

    $this->get(route('client.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page->where('parties.document', 'proforma'));

    $client->forceFill(['tax_id' => '309999999'])->save();
    payInvoice($invoice->fresh(), $invoice->fresh()->balance);

    expect($invoice->fresh()->number)->not->toBeNull();
    $this->get(route('client.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('parties.document', 'tax_invoice')
            ->where('parties.buyer.tax_id.value', '301234567'));
});

test('a draft shows the seller from today\'s settings and says so', function () {
    billingStaff();
    $invoice = issueInvoice(billingClient(['country' => 'NP']), [10_000], status: 'draft');

    $this->get(route('admin.billing.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('parties.seller.name', 'Himal Hosting Pvt. Ltd.')
            ->where('parties.seller_from_settings', true));
});

test('the client invoice lists the period each line covers', function () {
    $client = nepalBuyerForParties();
    $line = new InvoiceLineData('Business hosting', 500_000, billableType: BillableType::Manual, periodStart: CarbonImmutable::parse('2026-11-01'), periodEnd: CarbonImmutable::parse('2027-10-31'));
    $invoice = issueInvoice($client, [$line]);

    $this->get(route('client.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.period_start', '2026-11-01')
            ->where('items.0.period_end', '2027-10-31'));
});
