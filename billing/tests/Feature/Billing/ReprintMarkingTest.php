<?php

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Services\TaxNotePdf;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\NotificationMailer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';
require_once dirname(__DIR__).'/Notifications/helpers.php';

/*
| "Copy of original" on every reprint (vat-register §9, a MUST since NV1; Electronic Billing Procedure 2074 cl. 6(च)):
| each path that hands out a tax invoice or a note. Downloads are prints: the first is the original, each later one is
| marked with its print number. An emailed PDF is the original and not a print (open with the accountant).
| Nepal VAT 13%, NPR, Nepal time; the mode is on.
*/

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

/** A paid Rs 10,000 + VAT tax invoice and, for a note, a Rs 5,650 credit note against it. */
function reprintDocument(Client $client, string $document): Invoice|TaxNote
{
    $invoice = issueInvoice($client, [1_000_000]);
    payInvoice($invoice, 1_130_000);

    if ($document === 'invoice') {
        return $invoice->fresh();
    }

    $issuer = app(TaxNoteIssuer::class);
    $request = NoteRequest::creditAmount($invoice->id, 565_000, 'price_correction', 'Plan price corrected.', settlement: 'credit');
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

/**
 * Renders copies as HTML instead of through dompdf, so the response shows what the copy prints. The original still comes
 * from the real cached PDF.
 */
function reprintHtmlCopies(string $document): void
{
    [$class, $view] = $document === 'invoice' ? [InvoicePdf::class, 'billing::pdf.invoice'] : [TaxNotePdf::class, 'billing::pdf.tax-note'];
    $real = app($class);
    $pdf = Mockery::mock($real);
    $pdf->shouldReceive('render')->andReturnUsing(fn ($record, ?int $print = null): string => view($view, $real->viewData($record, $print))->render());
    test()->instance($class, $pdf);
}

test('every download path marks each reprint "Copy of original" with its print number; the first is the original', function (string $document, string $who) {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'tax_id' => '301234567', 'company_name' => 'Everest Traders']);
    $record = reprintDocument($client, $document);

    if ($who === 'client') {
        Currency::query()->where('code', 'USD')->delete(); // actingAsClient() adds it
        actingAsClient($client);
        $url = route($document === 'invoice' ? 'client.invoices.pdf' : 'client.invoices.notes.pdf', $record->id);
    } else {
        actingAsStaff([$document === 'invoice' ? 'billing.invoices.view' : 'billing.tax_notes.view']);
        $url = route($document === 'invoice' ? 'admin.billing.invoices.pdf' : 'admin.billing.notes.pdf', $record);
    }

    reprintHtmlCopies($document);
    $cached = $document === 'invoice' ? app(InvoicePdf::class)->path($record) : app(TaxNotePdf::class)->path($record);

    $original = $this->get($url)->assertOk()->assertDownload();
    $second = $this->get($url)->assertOk()->getContent();
    $third = $this->get($url)->assertOk()->getContent();

    expect($original->streamedContent())->toBe(Storage::disk('objects')->get($cached))->toStartWith('%PDF-')
        ->and($second)->toContain('Copy of original — print 2')
        ->and($third)->toContain('Copy of original — print 3')->not->toContain('print 2')
        ->and($record->fresh()->print_count)->toBe(3);
})->with([
    'tax invoice' => ['invoice'],
    'credit note' => ['note'],
])->with([
    'staff' => ['staff'],
    'client' => ['client'],
]);

test('a debit note reprint is marked like a credit note', function () {
    $invoice = reprintDocument(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), 'invoice');
    $issuer = app(TaxNoteIssuer::class);
    $request = NoteRequest::debit($invoice->id, 113_000, true, 'price_correction', 'Setup fee was left off.');
    $preview = $issuer->preview($request);
    $note = $issuer->issue($request, $preview->version, $preview->digest);
    actingAsStaff(['billing.tax_notes.view']);
    reprintHtmlCopies('note');

    $this->get(route('admin.billing.notes.pdf', $note))->assertOk()->assertDownload();

    expect($this->get(route('admin.billing.notes.pdf', $note))->getContent())->toContain('Debit Note')->toContain('Copy of original — print 2')
        ->and($note->fresh()->print_count)->toBe(2);
});

test('an email or a resend after reprints carries the original, unmarked and not counted (emailed PDF as a print: open with the accountant)', function (string $document) {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'tax_id' => '301234567', 'company_name' => 'Everest Traders']);
    $record = reprintDocument($client, $document);
    actingAsStaff(['billing.invoices.view', 'billing.tax_notes.view']);
    reprintHtmlCopies($document);
    $url = route($document === 'invoice' ? 'admin.billing.invoices.pdf' : 'admin.billing.notes.pdf', $record);
    $this->get($url)->assertOk();
    $this->get($url)->assertOk();

    // The receipt carries the tax invoice; the note's own email carries the note.
    $message = EmailMessage::query()->where('template_key', $document === 'invoice' ? 'invoice.payment_confirmation' : 'credit_note.issued')->sole();
    app(NotificationMailer::class)->resend($message->fresh());

    $cached = $document === 'invoice' ? app(InvoicePdf::class)->path($record->fresh()) : app(TaxNotePdf::class)->path($record);
    $attached = Mail::sent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->subjectLine === $message->subject)->map(fn (TemplatedMail $mail) => $mail->document?->path)->values()->all();

    expect($attached)->toBe([$cached, $cached])
        ->and(Storage::disk('objects')->get($cached))->toStartWith('%PDF-')
        ->and($record->fresh()->print_count)->toBe(2);
})->with(['tax invoice' => ['invoice'], 'credit note' => ['note']]);
