<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__).'/Billing/helpers.php';
require_once dirname(__DIR__).'/Notifications/helpers.php';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Storage::fake('objects');
    Mail::fake();
    Currency::factory()->npr()->create();
    billingSettings([
        'tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR',
        'seller.legal_name' => 'Himal Hosting Pvt Ltd', 'seller.pan' => '609876543', 'seller.vat_number' => '609876543',
    ]);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    seedEmailTemplates();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function r1029Invoice(Client $client): Invoice
{
    $invoice = issueInvoice($client, [1_000_000]);
    payInvoice($invoice, 1_130_000);

    return $invoice->fresh();
}

function r1029Note(Invoice $invoice): TaxNote
{
    $issuer = app(TaxNoteIssuer::class);
    $request = NoteRequest::creditAmount($invoice->id, 565_000, 'price_correction', 'Plan price corrected.', settlement: 'credit')->notifying(false);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

test('a client cannot render unlimited note PDF copies (each a dompdf render under a row lock and a fiscal print)', function () {
    [, $client] = actingAsClient(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']));
    $note = r1029Note(r1029Invoice($client));
    $statuses = [];

    for ($i = 0; $i < 40; $i++) {
        $statuses[] = $this->get(route('client.invoices.notes.pdf', $note->id))->getStatusCode();
    }

    expect($statuses)->toContain(429)->and($note->fresh()->print_count)->toBeLessThan(40);
});

test('a throttled document download is not a print: no count and no activity row', function () {
    [, $client] = actingAsClient(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']));
    $note = r1029Note(r1029Invoice($client));

    foreach (range(1, BillingServiceProvider::CLIENT_DOCUMENTS_PER_MINUTE) as $download) {
        $this->get(route('client.invoices.notes.pdf', $note->id))->assertOk();
    }

    $this->get(route('client.invoices.notes.pdf', $note->id))->assertTooManyRequests();
    $this->get(route('client.invoices.pdf', $note->invoice_id))->assertTooManyRequests();

    expect($note->fresh()->print_count)->toBe(BillingServiceProvider::CLIENT_DOCUMENTS_PER_MINUTE)
        ->and(ActivityLog::query()->where('event', 'billing.tax_note.printed')->count())->toBe(BillingServiceProvider::CLIENT_DOCUMENTS_PER_MINUTE);
});
