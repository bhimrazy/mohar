<?php

/*
 * Security round 2026-10-31, documents track. Behavioural check (not a code read) that a login without the `billing`
 * area cannot open a credit or debit note, invoice or quote PDF, including a login that holds `billing` on another
 * account it can switch to. All are expected to hold; no print is counted for a refused download.
 */

use App\Models\User;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ClientContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__, 2).'/Billing/helpers.php';
require_once dirname(__DIR__, 2).'/Notifications/helpers.php';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Storage::fake('objects');
    Mail::fake();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR',
        'seller.legal_name' => 'Himal Hosting Pvt Ltd', 'seller.pan' => '609876543', 'seller.vat_number' => '609876543']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    seedEmailTemplates();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function probeNote(Client $client, string $kind): TaxNote
{
    $invoice = issueInvoice($client, [1_000_000]);
    payInvoice($invoice, 1_130_000);
    $issuer = app(TaxNoteIssuer::class);
    $request = ($kind === 'credit'
        ? NoteRequest::creditAmount($invoice->id, 565_000, 'price_correction', 'Plan price corrected.', settlement: 'credit')
        : NoteRequest::debit($invoice->id, 113_000, true, 'price_correction', 'Setup fee was left off.'))->notifying(false);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

test('D1 a sub-user with every area except billing gets 403 on credit and debit note PDFs, and no print is counted', function (string $kind) {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $note = probeNote($client, $kind);
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(array_values(array_diff(ClientContext::AREAS, ['billing'])))]);
    $this->actingAs($user);

    $this->get(route('client.invoices.notes.pdf', $note->id))->assertForbidden();
    $this->call('HEAD', route('client.invoices.notes.pdf', $note->id))->assertForbidden();
    $this->get(route('client.invoices.pdf', $note->invoice_id))->assertForbidden();
    expect($note->fresh()->print_count)->toBe(0)
        ->and(Invoice::query()->findOrFail($note->invoice_id)->print_count)->toBe(0);
})->with(['credit', 'debit']);

test('D2 billing on account A does not open account B\'s note, whichever account is selected', function () {
    $a = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $b = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $note = probeNote($b, 'credit');
    $user = User::factory()->create();
    $a->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $b->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['support'])]);
    $this->actingAs($user);

    $this->post(route('client.account.switch'), ['client_id' => $a->id]);
    $this->get(route('client.invoices.notes.pdf', $note->id))->assertNotFound();

    $this->post(route('client.account.switch'), ['client_id' => $b->id]);
    $this->get(route('client.invoices.notes.pdf', $note->id))->assertForbidden();
    $this->get(route('client.invoices.notes.index'))->assertForbidden();

    expect($note->fresh()->print_count)->toBe(0);
});

test('D3 a sub-user without billing cannot open a quote PDF', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $quote = Quote::factory()->for($client)->delivered()->create();
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['support', 'services'])]);
    $this->actingAs($user);

    $this->get(route('client.quotes.pdf', $quote->id))->assertForbidden();
});
