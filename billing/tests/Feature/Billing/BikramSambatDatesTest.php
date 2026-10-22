<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Notifications/helpers.php';

/*
 * Issue #207: an optional Bikram Sambat date beside the AD issue and due dates. Issued 12 Jan 2026 = 28 Poush 2082 BS,
 * due 26 Jan 2026 = 12 Magh 2082 BS (Nepal time, NPR, VAT 13% defaults).
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-12 10:00', 'Asia/Kathmandu'));
    app(Settings::class)->set('core.date_format', 'j M Y');
    Currency::factory()->npr()->create();
});

function nprInvoice(?Client $client = null): Invoice
{
    $client ??= Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'payer@example.test']);

    return issueInvoice($client, [1_000_000], CarbonImmutable::parse('2026-01-26'));
}

test('BS dates are off by default: no BS date in pages, the PDF or merge fields', function () {
    billingStaff();
    $invoice = nprInvoice();

    $this->get(route('admin.billing.invoices.show', $invoice))
        ->assertInertia(fn (Assert $page) => $page->where('invoice.issue_date_bs', null)->where('invoice.due_date_bs', null));

    expect(view('billing::pdf.invoice', app(InvoicePdf::class)->viewData($invoice))->render())->toContain('12 Jan 2026')->not->toContain(' BS)')
        ->and(app(InvoicePdf::class)->path($invoice, 'en'))->toEndWith('-en.pdf');
});

test('staff turn the setting on from Admin → Settings → Billing', function () {
    actingAsStaff(['*']);
    confirmPassword();

    // The page posts the whole Billing section: every current value, with this one switched on.
    $payload = [];
    foreach (app(SettingsRegistry::class)->sections()['billing']['settings'] as $definition) {
        $value = app(Settings::class)->get($definition->key);
        data_set($payload, $definition->key, match (true) {
            is_array($value) => implode(', ', $value),
            is_int($value), is_float($value) => (string) $value,
            default => $value,
        });
    }
    data_set($payload, 'billing.show_bs_dates', true);

    $this->put(route('admin.platform.settings.update', ['section' => 'billing']), $payload)->assertSessionDoesntHaveErrors();

    expect(app(Settings::class)->get('billing.show_bs_dates'))->toBeTrue();
});

test('with the setting on, the admin invoice page shows both dates', function () {
    billingSettings(['show_bs_dates' => true]);
    billingStaff();
    $invoice = nprInvoice();

    $this->get(route('admin.billing.invoices.show', $invoice))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.issue_date', '2026-01-12')->where('invoice.issue_date_bs', '28 Poush 2082 BS')
            ->where('invoice.due_date', '2026-01-26')->where('invoice.due_date_bs', '12 Magh 2082 BS'));
});

test('with the setting on, the client invoice page shows both dates', function () {
    billingSettings(['show_bs_dates' => true]);
    [, $client] = actingAsClient(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']));
    $invoice = nprInvoice($client);

    $this->get(route('client.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/billing/invoices/show')
            ->where('invoice.issue_date_bs', '28 Poush 2082 BS')
            ->where('invoice.due_date_bs', '12 Magh 2082 BS'));
});

test('with the setting on, the PDF prints both dates and is cached apart from the AD-only PDF', function () {
    $invoice = nprInvoice();
    $adOnly = app(InvoicePdf::class)->path($invoice, 'en');
    billingSettings(['show_bs_dates' => true]);

    $html = view('billing::pdf.invoice', app(InvoicePdf::class)->viewData($invoice))->render();

    expect($html)->toContain('Issued 12 Jan 2026 (28 Poush 2082 BS)')->toContain('Due 26 Jan 2026 (12 Magh 2082 BS)')
        ->and(app(InvoicePdf::class)->path($invoice, 'en'))->toEndWith('-en-bs.pdf')->not->toBe($adOnly);
});

test('with the setting on, invoice emails show both dates; off, only the AD date', function (bool $on) {
    Mail::fake();
    seedEmailTemplates();
    billingSettings(['show_bs_dates' => $on, 'pdf.attach_to_emails' => false]);
    billingStaff();
    $invoice = nprInvoice();

    $this->post(route('admin.billing.invoices.remind', $invoice))->assertRedirect();

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($on): bool {
        $both = str_contains($mail->plainText, '26 Jan 2026 (12 Magh 2082 BS)') && str_contains((string) $mail->htmlBody, '12 Jan 2026 (28 Poush 2082 BS)');

        return str_contains($mail->plainText, '26 Jan 2026') && $both === $on && ($on || ! str_contains($mail->plainText, ' BS)'));
    });
})->with(['on' => true, 'off' => false]);

test('a due date outside the BS table shows the AD date alone on the page, the PDF and the email', function () {
    Mail::fake();
    seedEmailTemplates();
    billingSettings(['show_bs_dates' => true, 'pdf.attach_to_emails' => false]);
    billingStaff();
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'payer@example.test']), [1_000_000], CarbonImmutable::parse('2044-04-14'));

    $this->get(route('admin.billing.invoices.show', $invoice))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('invoice.issue_date_bs', '28 Poush 2082 BS')->where('invoice.due_date_bs', null));

    expect(view('billing::pdf.invoice', app(InvoicePdf::class)->viewData($invoice))->render())
        ->toContain('Issued 12 Jan 2026 (28 Poush 2082 BS)')->toContain('Due 14 Apr 2044')->not->toContain('Due 14 Apr 2044 (');

    $this->post(route('admin.billing.invoices.remind', $invoice))->assertRedirect();

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains($mail->plainText, '14 Apr 2044')
        && ! str_contains($mail->plainText, '14 Apr 2044 (') && str_contains($mail->plainText, '12 Jan 2026 (28 Poush 2082 BS)'));
});
