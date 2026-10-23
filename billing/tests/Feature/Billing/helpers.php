<?php

/*
| Shared helpers for the Billing feature tests (require_once from each test file).
*/

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Models\User;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxRule;
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

/**
 * Staff with every billing permission, including the sensitive ones a "billing.*" wildcard never grants.
 */
function billingStaff(): User
{
    return actingAsStaff(['billing.*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
}

/** Switch Nepal VAT mode on (started long ago) with this rule as the Nepal VAT rule, so invoices numbered after are classified. */
function useNepalVatRule(TaxRule $rule): TaxRule
{
    billingSettings(['nepal_vat.enabled' => true, 'nepal_vat.enabled_from' => '2026-01-01', 'nepal_vat.vat_rules' => [$rule->id]]);

    return $rule;
}
