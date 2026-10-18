<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Actions\SaveDraftInvoice;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\BillingSequence;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo billing data: gateways, tax rules and invoices in every status for the demo customer.
 */
class BillingSeeder extends Seeder
{
    public function run(ApplyPayment $pay, CancelInvoice $cancel, RefundTransaction $refund, Quotes $quotes, CreateAddFundsInvoice $addFunds, SaveDraftInvoice $drafts): void
    {
        $this->gateways();
        $this->taxRules();

        if (Invoice::query()->exists()) {
            return;
        }

        $today = CarbonImmutable::today();
        $demo = Client::query()->where('email', 'demo@example.com')->first();

        if ($demo === null) {
            return;
        }

        app(Settings::class)->setMany([
            'billing.number_format' => 'INV-{NUMBER}',
            'billing.quote_number_format' => 'Q-{NUMBER}',
            'billing.tax.enabled' => true,
            'billing.tax.home_country' => 'IN',
            'billing.tax.home_state' => 'MH',
        ]);
        BillingSequence::query()->firstOrCreate(['sequence' => 'invoice', 'period' => ''], ['next_number' => 1041]);
        BillingSequence::query()->firstOrCreate(['sequence' => 'quote', 'period' => ''], ['next_number' => 204]);

        ClientFlag::query()->firstOrCreate(['client_id' => $demo->id], ['first_order_accepted_at' => now()]);
        $online = self::sandboxAllowed() ? SandboxCardDriver::KEY : 'manual';

        if (self::sandboxAllowed()) {
            $card = (new SandboxCardDriver)->tokenize('4242424242424242', 12, $today->addYears(2)->year);
            PayMethod::query()->create([
                'client_id' => $demo->id, 'gateway' => SandboxCardDriver::KEY, 'kind' => 'card', 'token' => $card->token,
                'brand' => $card->brand, 'last4' => $card->last4, 'expiry_month' => 12, 'expiry_year' => $card->expiryYear, 'is_default' => true,
            ]);
        }

        $issue = $this->issue(...);
        $service = fn (string $description, int $amount, int $id, CarbonImmutable $start): InvoiceLineData => new InvoiceLineData(
            $description, $amount, billableType: BillableType::Service, billableId: $id, periodStart: $start, periodEnd: $start->addMonth(),
        );

        // Paid by card, then partially refunded.
        $paid = $issue($demo, [
            $service('Business Hosting — demo-labs.com', 2_499, 1, $today->subMonth()),
            new InvoiceLineData('Domain renewal — demo-labs.com', 1_299, billableType: BillableType::Domain, billableId: 1),
        ], $today->subDays(20));
        $payment = $pay->handle($paid, new PaymentData($paid->balance, $online, 'sbx_ch_'.Str::lower(Str::random(16)), fee: 140));
        $refund->handle($payment, 500, RefundMode::ToCredit, reason: 'Goodwill credit for downtime');

        // Due soon, overdue with a partial bank transfer, and cancelled.
        $issue($demo, [$service('Business Hosting — demo-labs.com', 2_499, 1, $today->addDays(5)), new InvoiceLineData('Daily backups add-on', 500, billableType: BillableType::ServiceAddon, billableId: 3)], $today->addDays(5));
        $overdue = $issue($demo, [$service('VPS Pro — vps1.demo-labs.com', 4_900, 2, $today->subDays(9))], $today->subDays(9), 'bank_transfer');
        $pay->handle($overdue, new PaymentData(2_000, 'bank_transfer', 'WIRE-'.random_int(10000, 99999)));
        $cancel->handle($issue($demo, [new InvoiceLineData('SSL certificate (cancelled order)', 3_900)], $today->subDays(30)), note: 'Order cancelled by customer');

        // Deposit to credit.
        $deposit = $addFunds->handle($demo, 5_000, $online);
        $pay->handle($deposit, new PaymentData(5_000, $online, 'sbx_ch_'.Str::lower(Str::random(16))));

        $quote = $quotes->save([
            'client_id' => $demo->id,
            'subject' => 'Managed Kubernetes migration',
            'proposal_text' => "Migration of the demo-labs.com stack to a managed cluster.\nIncludes planning, cut-over and two weeks of hypercare.",
            'items' => [
                ['description' => 'Migration planning workshop', 'quantity' => 1, 'unit_amount' => 45_000, 'discount_percent' => 0, 'taxable' => true],
                ['description' => 'Cut-over engineering (hours)', 'quantity' => 12, 'unit_amount' => 12_000, 'discount_percent' => 10, 'taxable' => true],
            ],
        ]);
        $quotes->deliver($quote);

        $this->marketInvoices($issue, $pay, $today);

        $others = Client::query()->whereKeyNot($demo->id)->where('currency', 'USD')->orderBy('id')->limit(6)->get();

        foreach ($others as $index => $client) {
            $invoice = $issue($client, [$service('Starter Hosting', 999 + $index * 500, 100 + $index, $today->subDays(10 - $index * 4))], $today->subDays(10 - $index * 4), $index % 2 === 0 ? 'bank_transfer' : $online);

            if ($index % 3 === 0) {
                $pay->handle($invoice, new PaymentData($invoice->balance, $invoice->payment_gateway ?? 'manual', 'TX-'.Str::upper(Str::random(8))));
            }
        }

        if ($first = $others->first()) {
            $drafts->handle(['client_id' => $first->id, 'notes' => 'Thanks for your business.', 'items' => [
                ['description' => 'Custom development (hours)', 'quantity' => 6, 'unit_amount' => 9_500, 'taxable' => true],
            ]]);
        }
    }

    /**
     * The sandbox gateway is only seeded (and active) for local development and tests.
     */
    private static function sandboxAllowed(): bool
    {
        return SandboxCardDriver::enabled() && app()->environment(['local', 'testing']);
    }

    private function gateways(): void
    {
        Gateway::query()->firstOrCreate(['key' => 'bank_transfer'], [
            'display_name' => 'Bank transfer', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 1,
            'config' => ['instructions' => "Transfer the amount to:\nHosting Co Ltd · IBAN DE89 3704 0044 0532 0130 00 · BIC COBADEFFXXX\nReference: {invoice}"],
        ]);
        if (self::sandboxAllowed()) {
            Gateway::query()->firstOrCreate(['key' => SandboxCardDriver::KEY], [
                'display_name' => 'Card (sandbox)', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0,
                'config' => ['webhook_secret' => 'whsec_demo', 'fee_percent' => '2.9'],
            ]);
        }
        Gateway::query()->firstOrCreate(['key' => 'manual'], [
            'display_name' => 'Manual payment', 'is_active' => true, 'show_on_order_form' => false, 'sort_order' => 2, 'config' => [],
        ]);
    }

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    private function issue(Client $client, array $lines, CarbonImmutable $due, ?string $gateway = null): Invoice
    {
        $result = app(Invoicer::class)->issue(new InvoiceDraft($client->id, $client->currency, $lines, $due, paymentGateway: $gateway, notify: false));

        return Invoice::query()->findOrFail($result->invoiceId);
    }

    /**
     * India (GST intra-/inter-state, B2B GSTIN) and Nepal (VAT 13%) customers in their own currencies.
     *
     * @param  \Closure(Client, list<InvoiceLineData>, CarbonImmutable, ?string): Invoice  $issue
     */
    private function marketInvoices(\Closure $issue, ApplyPayment $pay, CarbonImmutable $today): void
    {
        $markets = [
            ['first_name' => 'Priya', 'last_name' => 'Sharma', 'company_name' => 'Sharma Digital LLP', 'email' => 'priya@sharmadigital.in', 'city' => 'Pune', 'state' => 'Maharashtra', 'postcode' => '411001', 'country' => 'IN', 'currency' => 'INR', 'tax_id' => '27AAPFU0939F1ZV', 'amount' => 149_900],
            ['first_name' => 'Arjun', 'last_name' => 'Rao', 'company_name' => 'Bengaluru Cloudworks Pvt Ltd', 'email' => 'arjun@cloudworks.in', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'postcode' => '560001', 'country' => 'IN', 'currency' => 'INR', 'tax_id' => '29AAGCB7383J1Z4', 'amount' => 299_900],
            ['first_name' => 'Sita', 'last_name' => 'Gurung', 'company_name' => 'Himalayan Treks', 'email' => 'sita@himalayantreks.com.np', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP', 'currency' => 'NPR', 'tax_id' => null, 'amount' => 250_000],
        ];

        foreach ($markets as $index => $market) {
            $amount = $market['amount'];
            unset($market['amount']);
            $client = Client::query()->firstOrCreate(['email' => $market['email']], [...$market, 'phone' => null, 'address_1' => 'Main Road '.($index + 1), 'language' => 'en', 'status' => 'active', 'tax_exempt' => false]);
            $invoice = $issue($client, [new InvoiceLineData('Business Hosting (annual)', $amount, billableType: BillableType::Service, billableId: 500 + $index, periodStart: $today, periodEnd: $today->addYear())], $today->addDays(7), 'bank_transfer');

            if ($index === 0) {
                $pay->handle($invoice, new PaymentData($invoice->balance, 'bank_transfer', 'NEFT-'.random_int(100000, 999999)));
            }
        }
    }

    private function taxRules(): void
    {
        foreach ([
            ['name' => 'CGST', 'level' => 1, 'country' => 'IN', 'scope' => TaxRule::HOME_STATE, 'rate' => '9.000'],
            ['name' => 'SGST', 'level' => 2, 'country' => 'IN', 'scope' => TaxRule::HOME_STATE, 'rate' => '9.000'],
            ['name' => 'IGST', 'level' => 1, 'country' => 'IN', 'scope' => TaxRule::OTHER_STATE, 'rate' => '18.000'],
            ['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'scope' => null, 'rate' => '13.000'],
            ['name' => 'NY sales tax', 'level' => 1, 'country' => 'US', 'state' => 'NY', 'scope' => null, 'rate' => '8.875'],
        ] as $rule) {
            TaxRule::query()->firstOrCreate(['name' => $rule['name'], 'country' => $rule['country'], 'level' => $rule['level']], $rule);
        }
    }
}
