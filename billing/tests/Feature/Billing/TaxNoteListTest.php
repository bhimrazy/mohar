<?php

use App\Contracts\Billing\BillingDocuments;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Notifications\Models\EmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/helpers.php';
require_once dirname(__DIR__).'/Notifications/helpers.php';

/*
| Billing → Credit notes (vat-register §8): the list with its filters, the note page and the resend. Nepal VAT 13%,
| NPR, Nepal time; the mode is on.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Storage::fake('objects');
    Mail::fake();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings([
        'tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR',
        'seller.legal_name' => 'Himal Hosting Pvt Ltd', 'seller.pan' => '609876543', 'seller.vat_number' => '609876543',
    ]);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    seedEmailTemplates();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function listedNote(Client $client, string $kind = 'credit', string $settlement = 'credit', bool $notify = false): TaxNote
{
    $invoice = issueInvoice($client, [1_000_000]);
    payInvoice($invoice, 1_130_000);
    $issuer = app(TaxNoteIssuer::class);
    $request = ($kind === 'credit'
        ? NoteRequest::creditAmount($invoice->id, 565_000, 'price_correction', 'Plan price corrected.', settlement: $settlement)
        : NoteRequest::debit($invoice->id, 113_000, true, 'late_fee', 'Setup fee was left off.'))->notifying($notify);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

function nepaliClient(string $name = 'Everest Traders'): Client
{
    return Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'tax_id' => '301234567', 'company_name' => $name]);
}

test('staff see every note with its kind, both dates, reason and settlement, newest first', function () {
    $credit = listedNote(nepaliClient());
    CarbonImmutable::setTestNow('2026-10-21 06:00:00');
    $debit = listedNote(nepaliClient('Annapurna Hosts'), 'debit');
    actingAsStaff(['billing.tax_notes.view']);

    $this->get(route('admin.billing.notes.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/notes/index')
            ->has('notes.data', 2)
            ->where('notes.data.0.number', $debit->number)->where('notes.data.0.kind_label', 'Debit Note')->where('notes.data.0.settlement', ['Invoice balance'])
            ->where('notes.data.1.number', $credit->number)->where('notes.data.1.settlement', ['Invoice balance', 'Account credit'])
            ->where('notes.data.1.reason', 'Price correction')->where('notes.data.1.issue_date_bs', '3 Kartik 2083 BS')
            ->where('notes.data.1.client.display_name', fn ($name) => str_starts_with($name, 'Everest Traders'))->where('notes.data.1.total', 565_000)
            ->where('periods.0.label', 'Kartik 2083')->where('can.resend', false));
});

test('each filter narrows the list: kind, reason, settlement, automatic, BS month, AD range, client and number', function () {
    $everest = nepaliClient();
    $credit = listedNote($everest);
    $debit = listedNote(nepaliClient('Annapurna Hosts'), 'debit');
    TaxNote::query()->whereKey($debit->id)->update(['automatic' => true]);
    TaxNote::query()->whereKey($credit->id)->update(['bs_period' => '2083-06', 'issue_date' => '2026-10-01']);
    actingAsStaff(['billing.tax_notes.view']);

    $numbers = fn (array $query): array => collect($this->get(route('admin.billing.notes.index', $query))->assertOk()->viewData('page')['props']['notes']['data'])->pluck('number')->all();

    expect($numbers(['kind' => 'debit']))->toBe([$debit->number])
        ->and($numbers(['reason' => 'price_correction']))->toBe([$credit->number])
        ->and($numbers(['settlement' => 'credit']))->toBe([$credit->number])
        ->and($numbers(['settlement' => 'balance']))->toBe([$debit->number, $credit->number])
        ->and($numbers(['settlement' => 'refund']))->toBe([])
        ->and($numbers(['automatic' => '1']))->toBe([$debit->number])
        ->and($numbers(['automatic' => '0']))->toBe([$credit->number])
        ->and($numbers(['period' => '2083-06']))->toBe([$credit->number])
        ->and($numbers(['from' => '2026-10-10']))->toBe([$debit->number])
        ->and($numbers(['to' => '2026-10-10']))->toBe([$credit->number])
        ->and($numbers(['client' => $everest->id]))->toBe([$credit->number])
        ->and($numbers(['search' => substr($debit->invoice_number, 0, 8)]))->toContain($debit->number)
        ->and($numbers(['search' => $credit->number]))->toBe([$credit->number]);
});

test('a bad filter value is refused, not ignored', function () {
    actingAsStaff(['billing.tax_notes.view']);

    $this->get(route('admin.billing.notes.index', ['period' => 'last month']))->assertSessionHasErrors('period');
    $this->get(route('admin.billing.notes.index', ['kind' => 'refund']))->assertSessionHasErrors('kind');
});

test('the list needs the view permission, and the page shows a note with its lines', function () {
    $note = listedNote(nepaliClient());
    actingAsStaff(['billing.invoices.view']);
    $this->get(route('admin.billing.notes.index'))->assertForbidden();
    $this->get(route('admin.billing.notes.show', $note))->assertForbidden();

    actingAsStaff(['billing.tax_notes.view']);
    $this->get(route('admin.billing.notes.show', $note))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/notes/show')
            ->where('note.number', $note->number)->where('note.reason_text', 'Plan price corrected.')->where('note.tax', 65_000)
            ->has('note.lines', 1)->where('can.resend', false));
});

test('resend emails the client the note again with its PDF, and each resend is its own message', function () {
    $note = listedNote(nepaliClient(), notify: true);
    expect(EmailMessage::query()->where('related_type', 'tax_note')->count())->toBe(1);
    actingAsStaff(['billing.tax_notes.resend']);

    $this->post(route('admin.billing.notes.resend', $note))->assertRedirect();
    $this->post(route('admin.billing.notes.resend', $note))->assertRedirect();

    $messages = EmailMessage::query()->where('template_key', 'credit_note.issued')->where('related_type', BillingDocuments::TAX_NOTE)->where('related_id', $note->id)->get();
    expect($messages)->toHaveCount(3)
        ->and($messages->pluck('subject')->unique()->all())->toHaveCount(1)
        ->and(ActivityLog::query()->where('event', 'billing.tax_note.resend_requested')->where('subject_id', $note->id)->count())->toBe(2)
        // The note itself is untouched, and a resend is not a print.
        ->and($note->fresh()->print_count)->toBe(0);
});

test('a note first issued without an email can be sent by a resend, and a debit note uses its own template', function () {
    $credit = listedNote(nepaliClient(), notify: false);
    $debit = listedNote(nepaliClient('Annapurna Hosts'), 'debit', notify: false);
    expect(EmailMessage::query()->where('related_type', 'tax_note')->count())->toBe(0);
    actingAsStaff(['billing.tax_notes.resend']);

    $this->post(route('admin.billing.notes.resend', $credit))->assertRedirect();
    $this->post(route('admin.billing.notes.resend', $debit))->assertRedirect();

    expect(EmailMessage::query()->where('related_type', 'tax_note')->orderBy('id')->pluck('template_key')->all())->toBe(['credit_note.issued', 'debit_note.issued']);
});

test('resend needs its own permission: viewing and issuing are not enough', function () {
    $note = listedNote(nepaliClient());
    actingAsStaff(['billing.tax_notes.view', 'billing.tax_notes.issue']);

    $this->post(route('admin.billing.notes.resend', $note))->assertForbidden();
    expect(EmailMessage::query()->where('related_type', 'tax_note')->count())->toBe(0);
});

test('resends are rate limited per staff member', function () {
    $note = listedNote(nepaliClient());
    actingAsStaff(['billing.tax_notes.resend']);

    foreach (range(1, 10) as $_) {
        $this->post(route('admin.billing.notes.resend', $note))->assertRedirect();
    }

    $this->post(route('admin.billing.notes.resend', $note))->assertStatus(429);
});

test('a note that does not exist answers 404 on the page and on resend', function () {
    actingAsStaff(['billing.tax_notes.view', 'billing.tax_notes.resend']);

    $this->get(route('admin.billing.notes.show', 99999))->assertNotFound();
    $this->post(route('admin.billing.notes.resend', 99999))->assertNotFound();
});
