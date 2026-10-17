<?php

/*
| Shared helpers for the Billing feature tests (require_once from each test file).
*/

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $attributes
 */
function billingClient(array $attributes = []): Client
{
    if (! Currency::query()->where('code', 'USD')->exists()) {
        Currency::factory()->create();
    }

    return Client::factory()->create($attributes);
}

/**
 * @param  array<string, mixed>  $values  keys without the "billing." prefix
 */
function billingSettings(array $values): void
{
    app(Settings::class)->setMany(collect($values)->mapWithKeys(fn ($value, $key) => ['billing.'.$key => $value])->all());
}

/**
 * Issue a real invoice through the Invoicer contract.
 *
 * @param  list<int|InvoiceLineData>  $lines  amounts (minor units) or line DTOs
 */
function issueInvoice(Client $client, array $lines = [10_000], ?CarbonImmutable $dueDate = null, string $status = 'unpaid', bool $applyCredit = false, ?string $gateway = null): Invoice
{
    $lines = array_map(fn ($line) => $line instanceof InvoiceLineData ? $line : new InvoiceLineData('Hosting', $line, billableType: BillableType::Manual), $lines);
    $result = app(Invoicer::class)->issue(new InvoiceDraft($client->id, $client->currency, $lines, $dueDate, status: $status, paymentGateway: $gateway, applyCredit: $applyCredit));

    return Invoice::query()->findOrFail($result->invoiceId);
}

function payInvoice(Invoice $invoice, int $amount, ?string $txnId = null, string $gateway = 'bank_transfer'): Transaction
{
    return app(ApplyPayment::class)->handle($invoice, new PaymentData($amount, $gateway, $txnId));
}

function sandboxGateway(string $secret = 'whsec_test'): Gateway
{
    return Gateway::factory()->sandbox($secret)->create();
}
