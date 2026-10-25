<?php

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Services\TaxNotePdf;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/helpers.php';
require_once dirname(__DIR__).'/Notifications/helpers.php';

/*
| The note as a document (VAT PR 5, vat-register §8–§10): PDF, print count, email with the PDF, webhooks and the client
| area (AC 20 webhook, 35, 36, 55, 56). Nepal VAT 13%, NPR, Nepal time; the mode is on. Rs 10,000 + VAT is Rs 11,300.
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

function documentInvoice(?Client $client = null): Invoice
{
    $client ??= Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'tax_id' => '301234567', 'company_name' => 'Everest Traders']);
    $invoice = issueInvoice($client, [1_000_000]);
    payInvoice($invoice, 1_130_000);

    return $invoice->fresh();
}

function documentNote(Invoice $invoice, bool $notify = true, string $kind = 'credit'): TaxNote
{
    $issuer = app(TaxNoteIssuer::class);
    $request = ($kind === 'credit'
        ? NoteRequest::creditAmount($invoice->id, 565_000, 'price_correction', 'Plan price corrected.', settlement: 'credit')
        : NoteRequest::debit($invoice->id, 113_000, true, 'price_correction', 'Setup fee was left off.'))->notifying($notify);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

test('the note PDF shows its kind, both parties with PAN, both dates, the invoice and the reason (AC 36)', function () {
    $invoice = documentInvoice();
    $note = documentNote($invoice);

    $html = view('billing::pdf.tax-note', app(TaxNotePdf::class)->viewData($note))->render();

    expect($html)->toContain('Credit Note')->toContain($note->number)
        ->toContain('Himal Hosting Pvt Ltd')->toContain('PAN/VAT No.: 609876543')
        ->toContain('Everest Traders')->toContain('PAN/VAT No.: 301234567')
        ->toContain('Issued Oct 20, 2026 (3 Kartik 2083 BS)')
        ->toContain('<strong>'.$invoice->number.'</strong>')->toContain('Dated Oct 20, 2026 (3 Kartik 2083 BS)')
        ->toContain('Price correction')->toContain('Plan price corrected.')
        ->toContain('VAT @ 13%')->toContain('Rs 5,650.00')
        ->not->toContain('Copy of original');
});

test('every note download is a print: the first is the original, later ones say which copy (AC 55, §9)', function () {
    $note = documentNote(documentInvoice());
    actingAsStaff(['billing.tax_notes.view']);

    $this->get(route('admin.billing.notes.pdf', $note))->assertOk()->assertDownload();
    $second = $this->get(route('admin.billing.notes.pdf', $note))->assertOk();

    expect($note->fresh()->print_count)->toBe(2)
        ->and($second->headers->get('Content-Disposition'))->toContain('credit-note-')
        ->and(view('billing::pdf.tax-note', app(TaxNotePdf::class)->viewData($note, 2))->render())->toContain('Copy of original — print 2')
        ->and(ActivityLog::query()->where('event', 'billing.tax_note.printed')->pluck('properties')->map(fn ($properties) => $properties['print_count'])->all())->toBe([1, 2]);
});

test('a tax invoice downloaded twice in the mode is counted and marked; outside the mode it is not (AC 55)', function () {
    $invoice = documentInvoice();
    actingAsStaff(['billing.invoices.view']);

    $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk();
    $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk();

    expect($invoice->fresh())->print_count->toBe(2)->version->toBe($invoice->version)
        ->and(view('billing::pdf.invoice', app(InvoicePdf::class)->viewData($invoice->fresh(), 2))->render())->toContain('Copy of original — print 2');

    billingSettings(['nepal_vat.enabled' => false]);
    $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk();

    expect($invoice->fresh()->print_count)->toBe(2);
});

test('staff without the view permission cannot download a note', function () {
    $note = documentNote(documentInvoice());
    actingAsStaff(['billing.invoices.view']);

    $this->get(route('admin.billing.notes.pdf', $note))->assertForbidden();
    expect($note->fresh()->print_count)->toBe(0);
});

test('a client sees and downloads only their own notes; another client\'s note is 404 (AC 35)', function () {
    Currency::query()->where('code', 'USD')->delete(); // actingAsClient() adds it again
    [, $client] = actingAsClient(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']));
    $own = documentInvoice($client);
    $note = documentNote($own);
    $foreign = documentNote(documentInvoice());

    $this->get(route('client.invoices.notes.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('client/billing/notes/index')
            ->has('notes.data', 1)->where('notes.data.0.number', $note->number)->where('notes.data.0.reason', 'Price correction')
            ->missing('notes.data.0.created_by'));
    $this->get(route('client.invoices.show', $own->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('notes', 1)->where('notes.0.number', $note->number));
    $this->get(route('client.invoices.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('has_notes', true));

    $this->get(route('client.invoices.notes.pdf', $note->id))->assertOk()->assertDownload();
    $this->get(route('client.invoices.notes.pdf', $foreign->id))->assertNotFound();

    expect($note->fresh()->print_count)->toBe(1)->and($foreign->fresh()->print_count)->toBe(0);
});

test('a note issued with "Send to client" emails the client with the note PDF attached (AC 56)', function () {
    $invoice = documentInvoice();
    billingStaff();
    confirmPassword();
    $data = ['kind' => 'credit', 'allocation' => 'amount', 'amount' => '5650.00', 'reason_code' => 'price_correction', 'reason_text' => 'Plan price corrected.', 'settlement' => 'credit'];
    $preview = $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->assertOk()->json();

    $this->post(route('admin.billing.invoices.notes.store', $invoice), [...$data, 'notify' => true, 'version' => $preview['version'], 'digest' => $preview['digest']])->assertSessionHasNoErrors();

    $note = TaxNote::query()->sole();
    $message = EmailMessage::query()->where('template_key', 'credit_note.issued')->sole();

    expect($message)->related_type->toBe('tax_note')->related_id->toBe($note->id)
        ->and($message->subject)->toContain($note->number)->toContain('Rs 5,650.00');
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->subjectLine === $message->subject
        && count($mail->attachments()) === 1 && $mail->document?->filename === 'credit-note-'.$note->number.'.pdf'
        && Storage::disk('objects')->exists($mail->document->path));
    // Attaching sends the original: it is not a print.
    expect($note->fresh()->print_count)->toBe(0);
});

test('a note issued without "Send to client" sends no email, and a debit note uses its own template', function () {
    $invoice = documentInvoice();

    $noteMail = fn (): array => EmailMessage::query()->where('related_type', 'tax_note')->pluck('template_key')->all();

    documentNote($invoice, notify: false);
    expect($noteMail())->toBe([]);

    documentNote($invoice, kind: 'debit');
    expect($noteMail())->toBe(['debit_note.issued']);
});

test('issuing a note queues the credit_note.issued and debit_note.issued webhooks with the note as payload (AC 20)', function () {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->create(['events' => ['credit_note.issued', 'debit_note.issued']]);
    $invoice = documentInvoice();

    $credit = documentNote($invoice, notify: false);
    $debit = documentNote($invoice, notify: false, kind: 'debit');

    $deliveries = WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->orderBy('id')->get();

    expect($deliveries->pluck('event_type')->all())->toBe(['credit_note.issued', 'debit_note.issued'])
        ->and($deliveries[0]->payload['data']['object'])->toMatchArray(['number' => $credit->number, 'kind' => 'credit', 'invoice_number' => $invoice->number, 'amount_minor' => 565_000, 'reason_text' => 'Plan price corrected.'])
        ->and($deliveries[1]->payload['data']['object'])->toMatchArray(['number' => $debit->number, 'kind' => 'debit', 'amount_minor' => 113_000])
        ->and($deliveries[0]->payload['data']['ids'])->toMatchArray(['tax_note_id' => $credit->id, 'invoice_id' => $invoice->id]);
});
