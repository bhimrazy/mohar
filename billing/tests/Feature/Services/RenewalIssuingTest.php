<?php

use App\Enums\BillingCycle;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceAdjustment;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Jobs\ComposeEventEmails;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceInvoice;
use App\Support\Money;
use App\Support\Queues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/../Notifications/helpers.php';

/**
 * End to end through the real Invoicer: what one renewal run writes, row by row (invoice, lines, service link, the
 * service's pointers, account credit, the client email and the webhook). Pinned before the renewal query work so
 * that the stored result stays identical.
 *
 * @return array<string, mixed>
 */
function renewalSnapshot(Service $service): array
{
    $link = ServiceInvoice::query()->where('service_id', $service->id)->sole();
    $invoice = Invoice::query()->with('items')->findOrFail($link->invoice_id);
    $service->refresh();

    return [
        'invoice' => [
            'status' => $invoice->status->value,
            ...$invoice->only(['source', 'currency', 'subtotal', 'discount_total', 'tax1_name', 'tax1_rate', 'tax1_amount', 'tax2_name',
                'tax2_rate', 'tax2_amount', 'credit_applied', 'total', 'balance', 'version', 'payment_gateway', 'number', 'proforma_number']),
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'paid' => $invoice->paid_at !== null,
            'client' => $invoice->client_snapshot['name'] ?? null,
        ],
        'items' => $invoice->items->map(fn ($item): array => [
            ...$item->only(['description', 'quantity', 'unit_amount', 'amount', 'tax_amount', 'taxable', 'sac_code']),
            'period_guard' => preg_replace('/:\d+:/', ':{id}:', (string) $item->period_guard),
            'type' => $item->type->value,
            'period' => $item->period_start?->toDateString().'/'.$item->period_end?->toDateString(),
        ])->all(),
        'link' => [...$link->only(['amount', 'discount']), 'kind' => $link->kind->value, 'status' => $link->status->value,
            'period' => $link->period_start?->toDateString().'/'.$link->period_end?->toDateString()],
        'service' => [
            'status' => $service->status->value,
            'next_due_date' => $service->next_due_date?->toDateString(),
            'next_invoice_date' => $service->next_invoice_date?->toDateString(),
            'renewals_invoiced' => $service->renewals_invoiced,
            'promotion_cycles_remaining' => $service->promotion_cycles_remaining,
        ],
        'credit_left' => app(CreditLedgerService::class)->balance($service->client_id, 'USD')->amount,
        'credit_ledger' => CreditLedgerEntry::query()->where('client_id', $service->client_id)->orderBy('id')->get()
            ->map(fn (CreditLedgerEntry $entry): string => "{$entry->reason->value} {$entry->amount}, balance {$entry->balance_after}".($entry->invoice_id === $invoice->id ? ', this invoice' : ''))->all(),
        'adjustments' => InvoiceAdjustment::query()->where('invoice_id', $invoice->id)->count(),
        'transactions' => Transaction::query()->where('invoice_id', $invoice->id)->count(),
        'emails' => EmailMessage::query()->where('client_id', $service->client_id)->orderBy('id')->get()
            ->map(fn (EmailMessage $message): string => $message->template_key.' → '.$message->recipient.' ('.$message->status->value.')')->all(),
        'webhooks' => WebhookDelivery::query()->orderBy('id')->get()
            ->filter(fn (WebhookDelivery $delivery): bool => ($delivery->payload['data']['object']['id'] ?? null) === $invoice->id)
            ->map(fn (WebhookDelivery $delivery): string => $delivery->event_type)->values()->all(),
    ];
}

/** Runs the faked ComposeEventEmails jobs, as a notifications worker would after the run (#566). */
function composeQueuedEmails(): void
{
    Queue::pushed(ComposeEventEmails::class)->each(fn (ComposeEventEmails $job) => app()->call([$job, 'handle']));
}

/** Queries for an automation run that renews one service: the run's own bookkeeping plus the renewal (measured 2026-10-20). */
const RENEWAL_QUERY_BUDGET = 34; // 52 before the renewal query work; +2 for the fiscal snapshot (lines, default currency); -13 since the invoice emails are composed in a notifications worker (#566)

beforeEach(function () {
    $this->travelTo('2026-11-01 08:00:00');
    Queue::fake();
    Storage::fake('objects');
    seedEmailTemplates();
    billingSettings(['tax.enabled' => true, 'credit.auto_apply_on_generate' => true]);
    TaxRule::factory()->create(['name' => 'GST', 'level' => 1, 'rate' => '18.000']);
    WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/billing', 'events' => ['invoice.created', 'invoice.paid'], 'secret' => 'whsec_test']);
});

test('a renewal run writes the same invoice, lines, link, credit, email and webhook as before', function () {
    $credit = app(CreditLedgerService::class);
    $partCredit = billingClient(['email' => 'part@example.test', 'first_name' => 'Part', 'last_name' => 'Credit']);
    $noCredit = billingClient(['email' => 'none@example.test', 'first_name' => 'No', 'last_name' => 'Credit']);
    $fullCredit = billingClient(['email' => 'full@example.test', 'first_name' => 'Full', 'last_name' => 'Credit']);
    $credit->credit($partCredit->id, Money::of(500, 'USD'), 'Goodwill');
    $credit->credit($fullCredit->id, Money::of(50_000, 'USD'), 'Prepaid');

    $promoted = Service::factory()->dueOn('2026-11-15')->for($partCredit)->create([
        'product_name' => 'Starter Hosting', 'domain' => 'part.example', 'recurring_amount' => 1_299,
        'renewal_discount' => 200, 'promotion_cycles_remaining' => 2,
    ]);
    $annual = Service::factory()->dueOn('2026-11-10')->for($noCredit)->create([
        'product_name' => 'Business Hosting', 'domain' => 'none.example', 'cycle' => BillingCycle::Annually, 'recurring_amount' => 12_000,
    ]);
    $prepaid = Service::factory()->dueOn('2026-11-12')->for($fullCredit)->create([
        'product_name' => 'VPS Small', 'domain' => null, 'recurring_amount' => 2_500,
    ]);

    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    composeQueuedEmails();

    $invoice = fn (array $fields): array => ['source' => 'system', 'currency' => 'USD', 'discount_total' => 0, 'tax1_name' => 'GST', 'tax1_rate' => '18.000',
        'tax2_name' => null, 'tax2_rate' => '0.000', 'tax2_amount' => 0, 'credit_applied' => 0, 'payment_gateway' => null, 'proforma_number' => null,
        'issue_date' => '2026-11-01', 'paid' => false, ...$fields];
    $line = fn (array $fields): array => ['quantity' => 1, 'taxable' => true, 'sac_code' => '998315', 'type' => 'service', ...$fields];

    expect(renewalSnapshot($promoted))->toEqual([
        'invoice' => $invoice(['status' => 'unpaid', 'subtotal' => 1_099, 'discount_total' => 200, 'tax1_amount' => 198, 'total' => 1_297, 'balance' => 797,
            'credit_applied' => 500, 'version' => 4, 'number' => '1', 'due_date' => '2026-11-15', 'client' => 'Part Credit']),
        'items' => [
            $line(['description' => 'Starter Hosting - part.example (2026-11-15 - 2026-12-14)', 'unit_amount' => 1_299, 'amount' => 1_299, 'tax_amount' => 234,
                'period_guard' => 'service:{id}:2026-11-15', 'period' => '2026-11-15/2026-12-15']),
            $line(['description' => 'Promotion discount', 'unit_amount' => -200, 'amount' => -200, 'tax_amount' => -36,
                'period_guard' => 'promotion:{id}:2026-11-15', 'type' => 'promotion', 'period' => '2026-11-15/2026-12-15']),
        ],
        'link' => ['amount' => 1_099, 'discount' => 200, 'kind' => 'renewal', 'status' => 'open', 'period' => '2026-11-15/2026-12-15'],
        'service' => ['status' => 'active', 'next_due_date' => '2026-11-15', 'next_invoice_date' => '2026-12-15', 'renewals_invoiced' => 1, 'promotion_cycles_remaining' => 1],
        'credit_left' => 0,
        'credit_ledger' => ['manual 500, balance 500', 'applied_to_invoice -500, balance 0, this invoice'],
        'adjustments' => 1,
        'transactions' => 0,
        'emails' => ['invoice.created → part@example.test (queued)', 'invoice.modified → part@example.test (queued)'],
        'webhooks' => ['invoice.created'],
    ]);

    expect(renewalSnapshot($annual))->toEqual([
        'invoice' => $invoice(['status' => 'unpaid', 'subtotal' => 12_000, 'tax1_amount' => 2_160, 'total' => 14_160, 'balance' => 14_160,
            'version' => 3, 'number' => '2', 'due_date' => '2026-11-10', 'client' => 'No Credit']),
        'items' => [
            $line(['description' => 'Business Hosting - none.example (2026-11-10 - 2027-11-09)', 'unit_amount' => 12_000, 'amount' => 12_000, 'tax_amount' => 2_160,
                'period_guard' => 'service:{id}:2026-11-10', 'period' => '2026-11-10/2027-11-10']),
        ],
        'link' => ['amount' => 12_000, 'discount' => 0, 'kind' => 'renewal', 'status' => 'open', 'period' => '2026-11-10/2027-11-10'],
        'service' => ['status' => 'active', 'next_due_date' => '2026-11-10', 'next_invoice_date' => '2027-11-10', 'renewals_invoiced' => 1, 'promotion_cycles_remaining' => null],
        'credit_left' => 0,
        'credit_ledger' => [],
        'adjustments' => 0,
        'transactions' => 0,
        'emails' => ['invoice.created → none@example.test (queued)'],
        'webhooks' => ['invoice.created'],
    ]);

    expect(renewalSnapshot($prepaid))->toEqual([
        'invoice' => $invoice(['status' => 'paid', 'subtotal' => 2_500, 'tax1_amount' => 450, 'total' => 2_950, 'balance' => 0,
            'credit_applied' => 2_950, 'version' => 5, 'number' => '3', 'due_date' => '2026-11-12', 'paid' => true, 'client' => 'Full Credit']),
        'items' => [
            $line(['description' => 'VPS Small (2026-11-12 - 2026-12-11)', 'unit_amount' => 2_500, 'amount' => 2_500, 'tax_amount' => 450,
                'period_guard' => 'service:{id}:2026-11-12', 'period' => '2026-11-12/2026-12-12']),
        ],
        'link' => ['amount' => 2_500, 'discount' => 0, 'kind' => 'renewal', 'status' => 'paid', 'period' => '2026-11-12/2026-12-12'],
        'service' => ['status' => 'active', 'next_due_date' => '2026-12-12', 'next_invoice_date' => '2026-12-12', 'renewals_invoiced' => 1, 'promotion_cycles_remaining' => null],
        'credit_left' => 47_050,
        'credit_ledger' => ['manual 50000, balance 50000', 'applied_to_invoice -2950, balance 47050, this invoice'],
        'adjustments' => 1,
        'transactions' => 0,
        'emails' => ['invoice.created → full@example.test (queued)'],
        'webhooks' => ['invoice.created', 'invoice.paid'],
    ]);
});

test('one renewal stays within its query budget', function () {
    WebhookEndpoint::query()->delete();
    $service = Service::factory()->dueOn('2026-11-15')->for(billingClient())->create(['recurring_amount' => 1_299]);
    $this->artisan('automation:run', ['--date' => '2026-10-31', '--only' => 'services.renewals'])->assertSuccessful(); // warm: settings, templates
    issueInvoice(billingClient(), [1_000]); // the invoice sequence has its row, as on every day after the first
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    expect(ServiceInvoice::query()->where('service_id', $service->id)->count())->toBe(1)
        ->and($queries)->toBeLessThanOrEqual(RENEWAL_QUERY_BUDGET);
});

test('a renewal run does no PDF work: the worker composing the invoice email renders the PDF and words the claim (#566)', function () {
    $renders = 0;
    app()->resolving(InvoicePdf::class, function () use (&$renders): void {
        $renders++;
    });
    $service = Service::factory()->dueOn('2026-11-15')->for(billingClient())->create(['recurring_amount' => 1_299]);

    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    expect($renders)->toBe(0)
        ->and(Storage::disk('objects')->allFiles())->toBe([])
        ->and(EmailMessage::query()->count())->toBe(0);
    Queue::assertPushedOn(Queues::DOCUMENT_EMAILS, ComposeEventEmails::class);

    composeQueuedEmails();

    $invoiceId = ServiceInvoice::query()->where('service_id', $service->id)->sole()->invoice_id;
    expect($renders)->toBeGreaterThan(0)
        ->and(EmailMessage::query()->where('template_key', 'invoice.created')->where('related_id', $invoiceId)->sole()->body_text)->toContain('A PDF copy of the invoice is attached.')
        ->and(Storage::disk('objects')->allFiles("billing/invoices/{$invoiceId}"))->not->toBe([]);
});
