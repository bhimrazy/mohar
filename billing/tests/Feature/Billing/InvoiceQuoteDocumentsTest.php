<?php

use App\Contracts\Billing\BillingDocuments;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Jobs\SendEmailMessage;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';
require_once dirname(__DIR__).'/Notifications/helpers.php';

/*
| Invoice and quote PDFs on their emails (P6b, billing.md §8 and §9; issues #29, #23). Nepal VAT 13%, NPR, Nepal time.
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

function pdfEmailClient(): Client
{
    return Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'tax_id' => '301234567', 'company_name' => 'Everest Traders']);
}

test('a new invoice email carries the invoice PDF and says so', function () {
    $invoice = issueInvoice(pdfEmailClient(), [1_000_000]);
    $message = EmailMessage::query()->where('template_key', 'invoice.created')->where('related_id', $invoice->id)->sole();

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->subjectLine === $message->subject
        && $mail->document?->filename === 'invoice-'.$invoice->displayNumber().'.pdf'
        && Storage::disk('objects')->exists($mail->document->path));
    expect($message->body_text)->toContain('A PDF copy of the invoice is attached.');
});

test('the receipt carries the invoice as it stands after payment: a tax invoice', function () {
    $invoice = issueInvoice(pdfEmailClient(), [1_000_000]);
    payInvoice($invoice, 1_130_000);

    $file = app(BillingDocuments::class)->document(BillingDocuments::INVOICE, $invoice->id);
    $html = view('billing::pdf.invoice', app(InvoicePdf::class)->viewData($invoice->fresh()))->render();

    expect($file->filename)->toStartWith('invoice-')
        ->and(EmailMessage::query()->where('template_key', 'invoice.payment_confirmation')->sole()->body_text)->toContain('A PDF copy of the invoice is attached.')
        ->and($html)->toContain('Tax Invoice');
});

test('an invoice PDF is rendered in the message locale and cached per locale', function () {
    $invoice = issueInvoice(pdfEmailClient(), [1_000_000]);

    $file = app(BillingDocuments::class)->document(BillingDocuments::INVOICE, $invoice->id, 'ne');

    expect($file->path)->toContain("billing/invoices/{$invoice->id}/")->toEndWith('-ne.pdf')
        ->and(Storage::disk('objects')->exists($file->path))->toBeTrue()
        ->and(app()->getLocale())->toBe('en');
});

test('a draft invoice and an unknown invoice have no document', function () {
    $draft = issueInvoice(pdfEmailClient(), [1_000_000], status: 'draft');

    expect(app(BillingDocuments::class)->document(BillingDocuments::INVOICE, $draft->id))->toBeNull()
        ->and(app(BillingDocuments::class)->document(BillingDocuments::INVOICE, 999_999))->toBeNull();
});

test('with attachments off the invoice and quote emails have no PDF and do not say so', function () {
    billingSettings(['pdf.attach_to_emails' => false]);
    $client = pdfEmailClient();
    issueInvoice($client, [1_000_000]);
    $quote = app(Quotes::class)->save(['client_id' => $client->id, 'subject' => 'Migration', 'items' => [['description' => 'Setup', 'quantity' => 1, 'unit_amount' => 500_000, 'taxable' => true]]]);
    app(Quotes::class)->deliver($quote);

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->document === null);
    Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->document !== null);
    expect(EmailMessage::query()->whereIn('template_key', ['invoice.created', 'quote.delivered'])->pluck('body_text')->implode(' '))->not->toContain('attached');
});

test('a delivered quote is emailed with its PDF; a draft has none', function () {
    $client = pdfEmailClient();
    $quote = app(Quotes::class)->save(['client_id' => $client->id, 'subject' => 'Migration', 'items' => [['description' => 'Setup', 'quantity' => 2, 'unit_amount' => 500_000, 'taxable' => true]]]);

    expect(app(BillingDocuments::class)->document(BillingDocuments::QUOTE, $quote->id))->toBeNull();

    $quote = app(Quotes::class)->deliver($quote);
    $message = EmailMessage::query()->where('template_key', 'quote.delivered')->where('related_id', $quote->id)->sole();

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->subjectLine === $message->subject
        && $mail->document?->filename === 'quote-'.$quote->number.'.pdf' && Storage::disk('objects')->exists($mail->document->path));
    expect($message->body_text)->toContain('A PDF copy of the quote is attached.');
});

test('an edited quote gets a new PDF and the old file is removed', function () {
    $quote = app(Quotes::class)->save(['client_id' => pdfEmailClient()->id, 'subject' => 'Migration', 'items' => [['description' => 'Setup', 'quantity' => 1, 'unit_amount' => 500_000, 'taxable' => false]]]);
    $quote = app(Quotes::class)->deliver($quote);
    $first = app(BillingDocuments::class)->document(BillingDocuments::QUOTE, $quote->id);

    CarbonImmutable::setTestNow('2026-10-20 06:05:00');
    Quote::query()->whereKey($quote->id)->update(['subject' => 'Migration, revised', 'updated_at' => now()]);
    $second = app(BillingDocuments::class)->document(BillingDocuments::QUOTE, $quote->id);

    expect($second->path)->not->toBe($first->path)
        ->and(Storage::disk('objects')->exists($first->path))->toBeFalse()
        ->and(Storage::disk('objects')->exists($second->path))->toBeTrue();
});

test('a resend of the invoice email attaches the document again', function () {
    $invoice = issueInvoice(pdfEmailClient(), [1_000_000]);
    $original = EmailMessage::query()->where('template_key', 'invoice.created')->sole();
    Mail::fake();
    $original->update(['status' => EmailStatus::Queued]);

    (new SendEmailMessage($original->id))->handle(app(Settings::class));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->document?->filename === 'invoice-'.$invoice->displayNumber().'.pdf');
});

test('a document over the size cap is left off and the text does not claim it', function () {
    config(['notifications.attachment_max_bytes' => 10]);
    $invoice = issueInvoice(pdfEmailClient(), [1_000_000]);
    app(BillingDocuments::class)->document(BillingDocuments::INVOICE, $invoice->id);
    $message = EmailMessage::query()->where('template_key', 'invoice.created')->sole();
    $message->update(['status' => EmailStatus::Queued]);
    Mail::fake();

    (new SendEmailMessage($message->id))->handle(app(Settings::class));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->document === null);
});

test('a converted quote line keeps its quantity, price and discount note when the item text is long', function () {
    $client = pdfEmailClient();
    $quotes = app(Quotes::class);
    $long = str_repeat('Managed hosting migration ', 20);
    $quote = $quotes->save(['client_id' => $client->id, 'subject' => 'Migration', 'items' => [
        ['description' => $long, 'quantity' => 3, 'unit_amount' => 333_333, 'discount_percent' => 10, 'taxable' => true],
        ['description' => 'Short line', 'quantity' => 3, 'unit_amount' => 333_333, 'discount_percent' => 10, 'taxable' => true],
    ]]);

    $invoice = $quotes->convert($quote);
    $descriptions = $invoice->items->sortBy('sort_order')->pluck('description')->all();

    expect(mb_strlen($descriptions[0]))->toBe(255)
        ->and($descriptions[0])->toStartWith('Managed hosting migration')->toContain('3 × ')->toEndWith('10% discount)')
        ->and($descriptions[1])->toBe('Short line (3 × Rs 3,333.33, 10% discount)')
        ->and($invoice->items->first()->quantity)->toBe(1);
});
