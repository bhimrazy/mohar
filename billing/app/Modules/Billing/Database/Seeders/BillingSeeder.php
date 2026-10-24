<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Core\CompanyClock;
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
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Demo billing data: gateways, tax rules and invoices in every status for the demo customer.
 */
class BillingSeeder extends DemoSeeder
{
    /** Gateway keys of the Nepal wallets (PaymentsNepal); seeded only when that module registered them. */
    public const NEPAL_WALLETS = ['esewa', 'khalti'];

    /**
     * NPR prices in paisa, the same figures the demo shows elsewhere (the US dollar list prices at the starting
     * exchange rate of 151.94): the hosting, VPS and backup lines equal the recurring amount of the demo client's
     * service on that product (ServicesSeeder), the domain line is himallabs.com's .com renewal (DomainsSeeder), and
     * Pro is the catalogue's Pro Hosting monthly price (CatalogSeeder).
     */
    private const NPR_STARTER = 75_818;

    private const NPR_PRO = 121_900;

    private const NPR_BUSINESS = 197_370;

    private const NPR_VPS_SMALL = 288_686;

    private const NPR_BACKUPS = 45_582;

    private const NPR_DOMAIN_COM_RENEWAL = 227_900;

    public function run(ApplyPayment $pay, CancelInvoice $cancel, RefundTransaction $refund, Quotes $quotes, CreateAddFundsInvoice $addFunds, SaveDraftInvoice $drafts): void
    {
        $this->gateways();
        $this->taxRules();

        if (Invoice::query()->exists()) {
            return;
        }

        $today = app(CompanyClock::class)->today();
        $demo = Client::query()->where('email', 'demo@example.com')->first();

        if ($demo === null) {
            return;
        }

        // The demo host is a Nepal company: Nepal VAT is its home tax. Only empty settings are filled, so a re-seed
        // keeps a developer's own values.
        $this->fillEmptySettings([
            'billing.number_format' => 'INV-{NUMBER}',
            'billing.quote_number_format' => 'Q-{NUMBER}',
            'billing.tax.enabled' => true,
            'billing.tax.home_country' => 'NP',
            'billing.tax.home_state' => 'Bagmati',
            // The demo host's invoice seller details (also the public site footer).
            'billing.seller.legal_name' => 'Example Hosting Pvt. Ltd.',
            'billing.seller.address' => 'Jhamsikhel Road, Lalitpur 44600, Bagmati, Nepal',
            'billing.seller.email' => 'billing@example.com',
            'billing.seller.phone' => '+977 1-5900000',
            // One number: Nepal's PAN is also the VAT number, so the invoice prints a single "PAN/VAT No." line.
            'billing.seller.vat_number' => '600000001',
            // Nepali invoices show the Bikram Sambat date beside the AD date.
            'billing.show_bs_dates' => true,
        ]);
        $this->nepalVatMode($today);
        BillingSequence::query()->firstOrCreate(['sequence' => 'invoice', 'period' => ''], ['next_number' => 1041]);
        BillingSequence::query()->firstOrCreate(['sequence' => 'quote', 'period' => ''], ['next_number' => 204]);

        ClientFlag::query()->firstOrCreate(['client_id' => $demo->id], ['first_order_accepted_at' => now()]);
        $wallets = Gateway::query()->whereIn('key', self::NEPAL_WALLETS)->where('is_active', true)->orderBy('key')->pluck('key')->all();
        // The showcase client is Nepali: eSewa or Khalti when installed, else a bank transfer.
        $wallet = fn (int $n): string => $wallets === [] ? 'bank_transfer' : $wallets[$n % count($wallets)];
        $online = self::sandboxAllowed() ? SandboxCardDriver::KEY : 'manual';
        $us = Client::query()->where('currency', 'USD')->orderBy('id')->first();

        $issue = $this->issue(...);
        $service = fn (string $description, int $amount, int $id, CarbonImmutable $start): InvoiceLineData => new InvoiceLineData(
            $description, $amount, billableType: BillableType::Service, billableId: $id, periodStart: $start, periodEnd: $start->addMonth(),
        );
        // Every scripted invoice is issued on its own day with the usual 14 days to pay, so the due date is never before it.
        $on = fn (): CarbonImmutable => app(CompanyClock::class)->today();

        $paid = $overdue = null;
        $scenario = [
            // Cancelled order.
            ['daysAgo' => 30, 'hour' => 10, 'run' => fn () => $cancel->handle($issue($demo, [new InvoiceLineData('Pro Hosting (cancelled order)', self::NPR_PRO)], 'bank_transfer'), note: 'Order cancelled by customer')],
            // Overdue with a partial bank transfer.
            ['daysAgo' => 24, 'hour' => 11, 'run' => function () use (&$overdue, $issue, $demo, $service, $on): void {
                $overdue = $issue($demo, [$service('VPS Small — vps.himallabs.com.np', self::NPR_VPS_SMALL, 2, $on())], 'bank_transfer');
            }],
            ['daysAgo' => 20, 'hour' => 14, 'run' => function () use (&$overdue, $pay): void {
                $pay->handle($overdue->refresh(), new PaymentData(100_000, 'bank_transfer', 'WIRE-'.random_int(10000, 99999)));
            }],
            // Paid through eSewa or Khalti.
            ['daysAgo' => 12, 'hour' => 10, 'run' => function () use (&$paid, $issue, $demo, $service, $on, $wallet): void {
                $paid = $issue($demo, [
                    $service('Business Hosting — himallabs.com.np', self::NPR_BUSINESS, 1, $on()->subMonth()),
                    new InvoiceLineData('Domain renewal — himallabs.com', self::NPR_DOMAIN_COM_RENEWAL, billableType: BillableType::Domain, billableId: 1),
                ], $wallet(0));
            }],
            ['daysAgo' => 11, 'hour' => 15, 'run' => function () use (&$paid, $pay): void {
                $pay->handle($paid->refresh(), new PaymentData($paid->balance, $paid->payment_gateway, strtoupper(substr((string) $paid->payment_gateway, 0, 3)).'-'.random_int(100000, 999999)));
            }],
            // Due soon.
            ['daysAgo' => 9, 'hour' => 10, 'run' => fn () => $issue($demo, [$service('Business Hosting — himallabs.com.np', self::NPR_BUSINESS, 1, $on()), new InvoiceLineData('Daily backups add-on', self::NPR_BACKUPS, billableType: BillableType::ServiceAddon, billableId: 3)], $wallet(1))],
        ];

        // Starter Hosting for the first Nepali clients, paid for every third one, issued over the last ten days.
        $others = Client::query()->whereKeyNot($demo->id)->where('currency', 'NPR')->orderBy('id')->limit(6)->get();

        foreach ($others as $index => $client) {
            $scenario[] = ['daysAgo' => 10 - $index, 'hour' => 12, 'run' => function () use ($issue, $pay, $refund, $service, $client, $index, $on, $wallet): void {
                $invoice = $issue($client, [$service('Starter Hosting', self::NPR_STARTER, 100 + $index, $on())], $index % 2 === 0 ? 'bank_transfer' : $wallet($index));

                if ($index % 3 === 0) {
                    $payment = $pay->handle($invoice, new PaymentData($invoice->balance, $invoice->payment_gateway ?? 'manual', 'TX-'.Str::upper(Str::random(8))));

                    // One goodwill refund, on a client that is not the showcase one.
                    if ($index === 3) {
                        $refund->handle($payment, 20_000, RefundMode::ToCredit, reason: 'Goodwill credit for downtime');
                    }
                }
            }];
        }

        if ($us !== null) {
            $scenario[] = ['daysAgo' => 6, 'hour' => 13, 'run' => function () use ($issue, $service, $us, $on, $online): void {
                $issue($us, [$service('Business Hosting', 2_499, 150, $on())], $online);
            }];

            if (self::sandboxAllowed()) {
                $card = (new SandboxCardDriver)->tokenize('4242424242424242', 12, $today->addYears(2)->year);
                PayMethod::query()->create([
                    'client_id' => $us->id, 'gateway' => SandboxCardDriver::KEY, 'kind' => 'card', 'token' => $card->token,
                    'brand' => $card->brand, 'last4' => $card->last4, 'expiry_month' => 12, 'expiry_year' => $card->expiryYear, 'is_default' => true,
                ]);
            }
        }

        // ~90 days of history with the scenario inside it, so invoice numbers stay chronological.
        $this->call(BillingHistorySeeder::class, parameters: ['scenario' => $scenario]);

        // Deposit to credit.
        $deposit = $addFunds->handle($demo, 100_000, $wallet(0));
        $pay->handle($deposit, new PaymentData(100_000, $wallet(0), 'DEP-'.random_int(100000, 999999)));

        $quote = $quotes->save([
            'client_id' => $demo->id,
            'subject' => 'Managed Kubernetes migration',
            'proposal_text' => "Migration of the himallabs.com.np stack to a managed cluster.\nIncludes planning, cut-over and two weeks of hypercare.",
            'items' => [
                ['description' => 'Migration planning workshop', 'quantity' => 1, 'unit_amount' => 4_500_000, 'discount_percent' => 0, 'taxable' => true],
                ['description' => 'Cut-over engineering (hours)', 'quantity' => 12, 'unit_amount' => 450_000, 'discount_percent' => 10, 'taxable' => true],
            ],
        ]);
        $quotes->deliver($quote);

        $this->marketInvoices($issue, $pay, $today);

        // Anything numbered before the mode took effect (none, with the start below) would be classified here.
        Artisan::call('billing:fiscal-vat-split');

        if ($first = $others->first()) {
            $drafts->handle(['client_id' => $first->id, 'notes' => 'Thank you for your business.', 'items' => [
                ['description' => 'Custom development (hours)', 'quantity' => 6, 'unit_amount' => 450_000, 'taxable' => true],
            ]]);
        }
    }

    /**
     * The sandbox gateway is only seeded (and active) for local development and tests.
     */
    public static function sandboxAllowed(): bool
    {
        return SandboxCardDriver::enabled() && app()->environment(['local', 'testing']);
    }

    private function gateways(): void
    {
        Gateway::query()->firstOrCreate(['key' => 'bank_transfer'], [
            'display_name' => 'Bank transfer', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 1,
            'config' => ['instructions' => "Transfer the amount in NPR to:\nExample Hosting Pvt. Ltd. · Example Bank Ltd., Lalitpur branch\nAccount no. 0000-0000-0000-0001 (example only)\nReference: {invoice}"],
        ]);
        if (self::sandboxAllowed()) {
            Gateway::query()->firstOrCreate(['key' => SandboxCardDriver::KEY], [
                'display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0,
                'config' => ['webhook_secret' => 'whsec_demo', 'fee_percent' => '2.9'],
            ]);
        }
        // Sandbox-mode wallets with obviously fake secrets; only when the PaymentsNepal module is installed.
        foreach (['esewa' => ['eSewa', ['sandbox' => true, 'product_code' => 'EPAYTEST', 'secret_key' => 'demo-sandbox-secret']], 'khalti' => ['Khalti', ['sandbox' => true, 'secret_key' => 'demo-sandbox-secret']]] as $key => [$name, $config]) {
            if (app(GatewayRegistry::class)->has($key)) {
                Gateway::query()->firstOrCreate(['key' => $key], ['display_name' => $name, 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => $key === 'esewa' ? 1 : 2, 'config' => $config]);
            }
        }
        Gateway::query()->firstOrCreate(['key' => 'manual'], [
            'display_name' => 'Manual payment', 'is_active' => true, 'show_on_order_form' => false, 'sort_order' => 2, 'config' => [],
        ]);
    }

    /**
     * Issues on the current day (the history's simulated clock inside the scenario) with $termDays to pay, so the
     * due date is never before the issue date.
     *
     * @param  list<InvoiceLineData>  $lines
     */
    private function issue(Client $client, array $lines, ?string $gateway = null, int $termDays = 14): Invoice
    {
        $issued = app(CompanyClock::class)->today();
        $result = app(Invoicer::class)->issue(new InvoiceDraft($client->id, $client->currency, $lines, $issued->addDays($termDays), $issued, paymentGateway: $gateway, notify: false));

        return Invoice::query()->findOrFail($result->invoiceId);
    }

    /**
     * India (GST intra-/inter-state, B2B GSTIN) and Nepal (VAT 13%) demo customers in their own currencies.
     *
     * @return list<array{client: Client, amount: int}>
     */
    public static function marketClients(): array
    {
        $markets = [
            ['first_name' => 'Priya', 'last_name' => 'Sharma', 'company_name' => 'Sharma Digital LLP', 'email' => 'priya@sharma-digital.example', 'city' => 'Pune', 'state' => 'Maharashtra', 'postcode' => '411001', 'country' => 'IN', 'currency' => 'INR', 'tax_id' => '27AAPFU0939F1ZV', 'amount' => 149_900],
            ['first_name' => 'Arjun', 'last_name' => 'Rao', 'company_name' => 'Bengaluru Cloudworks Pvt Ltd', 'email' => 'arjun@cloudworks.example', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'postcode' => '560001', 'country' => 'IN', 'currency' => 'INR', 'tax_id' => '29AAGCB7383J1Z4', 'amount' => 299_900],
            ['first_name' => 'Sita', 'last_name' => 'Gurung', 'company_name' => 'Gurung Trekking', 'email' => 'sita@gurung-trekking.example', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP', 'currency' => 'NPR', 'tax_id' => null, 'amount' => 250_000],
        ];

        return array_map(function (array $market): array {
            $amount = $market['amount'];
            unset($market['amount']);
            // Demo clients count as staff-created (origin is not fillable).
            $client = Client::unguarded(fn (): Client => Client::query()->firstOrCreate(['email' => $market['email']], [...$market, 'phone' => null, 'address_1' => 'Main Road 1', 'language' => 'en', 'status' => 'active', 'tax_exempt' => false, 'origin' => ClientOrigin::Staff, 'email_source' => EmailSource::Staff]));

            return ['client' => $client, 'amount' => $amount];
        }, $markets);
    }

    private function marketInvoices(\Closure $issue, ApplyPayment $pay, CarbonImmutable $today): void
    {
        foreach (self::marketClients() as $index => $market) {
            $invoice = $issue($market['client'], [new InvoiceLineData('Business Hosting (annual)', $market['amount'], billableType: BillableType::Service, billableId: 500 + $index, periodStart: $today, periodEnd: $today->addYear())], 'bank_transfer', 7);

            if ($index === 0) {
                $pay->handle($invoice, new PaymentData($invoice->balance, 'bank_transfer', 'NEFT-'.(310000 + $index)));
            }
        }
    }

    /**
     * The demo host files Nepal VAT: the mode is on from before the first seeded invoice (the history starts 730 days back), so every
     * seeded document is classified when it is numbered and the VAT register has rows. Only when no mode was ever stored.
     */
    private function nepalVatMode(CarbonImmutable $today): void
    {
        $vat = TaxRule::query()->where('country', 'NP')->where('name', 'VAT')->value('id');

        if ($vat === null) {
            return;
        }

        $this->fillEmptySettings([
            NepalVatMode::ENABLED => true,
            NepalVatMode::ENABLED_FROM => $today->subDays(740)->toDateString(),
            NepalVatMode::VAT_RULES => [(int) $vat],
        ]);
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
