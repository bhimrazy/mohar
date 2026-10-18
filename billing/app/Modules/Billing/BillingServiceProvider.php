<?php

namespace App\Modules\Billing;

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Enums\BillableType;
use App\Events\Orders\OrderAccepted;
use App\Models\User;
use App\Modules\Billing\Drivers\Gateways\BankTransferDriver;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\ManualDriver;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Http\Middleware\ConfirmPasswordForAction;
use App\Modules\Billing\Listeners\RecordAcceptedOrder;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Billing\Services\BillingDirectory;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PaymentReconciler;
use App\Modules\Billing\Services\Tax\TaxEngine;
use App\Modules\Billing\Support\BillingDashboard;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Automation\Automation;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Money;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class BillingServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'billing';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(BillingSettings::class);
        $this->app->singleton(TaxEngine::class);
        $this->app->singleton(CreditLedgerService::class);
        $this->app->bind(Invoicer::class, InvoiceService::class);
        $this->app->bind(TaxCalculator::class, TaxEngine::class);
        $this->app->bind(CreditLedger::class, CreditLedgerService::class);
        $this->app->bind(GatewayDirectory::class, BillingDirectory::class);
        $this->app->bind(InvoiceQueries::class, BillingDirectory::class);

        $this->app->singleton(GatewayRegistry::class, fn (): GatewayManager => $this->app->make(GatewayManager::class));
        $this->app->singleton(GatewayManager::class, function (): GatewayManager {
            $manager = new GatewayManager;
            $manager->register(new BankTransferDriver);
            $manager->register(new ManualDriver);
            if (SandboxCardDriver::enabled()) {
                $manager->register(new SandboxCardDriver);
            }

            return $manager;
        });
    }

    protected function bootModule(): void
    {
        Event::listen(OrderAccepted::class, RecordAcceptedOrder::class);

        // Each client pay request may open a provider session (Khalti initiate) with the merchant's key: cap it per
        // signed-in user so one account can't get the key throttled or flagged at the provider.
        RateLimiter::for('billing-pay', fn (Request $request): Limit => Limit::perMinute(self::PAY_REQUESTS_PER_MINUTE)
            ->by('billing-pay|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Step-up middleware is owned by Platform; provide the same contract when Platform isn't installed.
        $router = $this->app->make(Router::class);

        if (! array_key_exists('password.recent', $router->getMiddleware())) {
            $router->aliasMiddleware('password.recent', ConfirmPasswordForAction::class);
        }

        // Card data must never be flashed into the session by a validation redirect (PCI DSS 3.3.1).
        $this->callAfterResolving(ExceptionHandler::class, function (object $handler): void {
            if (method_exists($handler, 'dontFlash')) {
                $handler->dontFlash(['card_number', 'cvc', 'cvv', 'pan']);
            }
        });

        $this->registerAutomation();
        $this->registerNavigation();
        $this->registerDashboard();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearch();
        $this->registerMergeFields();
    }

    protected function schedule(Schedule $schedule): void
    {
        // Hosted gateways without webhooks: poll pending attempts between daily pipeline runs.
        $schedule->command('billing:reconcile-payments')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
    }

    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);
        $tasks = fn (): BillingAutomation => $this->app->make(BillingAutomation::class);

        $automation->task('billing.late_fees', Automation::LATE_FEES, fn (CarbonImmutable $today): int => $tasks()->lateFees($today), label: 'Apply late fees', description: 'Adds the late fee once to invoices that are overdue by the configured number of days.');
        $automation->task('billing.auto_capture', Automation::AUTO_CHARGE, fn (CarbonImmutable $today): int => $tasks()->autoCapture($today), label: 'Charge saved cards', description: 'Charges saved payment methods before the due date, then retries failed charges on schedule.');
        $automation->task('billing.reminders', Automation::REMINDERS, fn (CarbonImmutable $today): int => $tasks()->reminders($today), label: 'Send invoice reminders', description: 'Marks invoices overdue and sends the before-due and overdue reminders, once a day at most.');
        $automation->task('billing.payment_reconciliation', Automation::SYNC, fn (CarbonImmutable $today): int => $this->app->make(PaymentReconciler::class)->run(), label: 'Reconcile gateway payments', description: 'Looks up pending and unclear gateway payments whose customer never returned.');
        $automation->task('billing.auto_cancel', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => $tasks()->autoCancel($today), label: 'Cancel unpaid overdue invoices', description: 'Cancels overdue invoices that have no payments and no credit applied.');
        $automation->task('billing.housekeeping', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => $tasks()->housekeeping($today), label: 'Expire old quotes', description: 'Marks quotes past their validity date as expired.');
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);

        $navigation->add('admin', new NavItem('Invoices', 'admin.billing.invoices.index', 'FileText', 'Billing', 10, 'billing.invoices.view', 'admin.billing.invoices.*',
            badge: fn (?User $user): ?int => Invoice::query()->overdue()->count() ?: null));
        $navigation->add('admin', new NavItem('Transactions', 'admin.billing.transactions.index', 'ArrowLeftRight', 'Billing', 20, 'billing.transactions.view', 'admin.billing.transactions.*'));
        $navigation->add('admin', new NavItem('Quotes', 'admin.billing.quotes.index', 'ClipboardList', 'Billing', 30, 'billing.quotes.view', 'admin.billing.quotes.*'));
        $navigation->add('admin', new NavItem('Account credit', 'admin.billing.credit.index', 'Wallet', 'Billing', 40, 'billing.credit.manage', 'admin.billing.credit.*'));
        $navigation->add('admin', new NavItem('Gateways', 'admin.billing.gateways.index', 'CreditCard', 'Setup', 40, 'billing.gateways.manage', 'admin.billing.gateways.*'));
        $navigation->add('admin', new NavItem('Tax rules', 'admin.billing.tax-rules.index', 'Percent', 'Setup', 41, 'billing.tax.manage', 'admin.billing.tax-rules.*'));
        $navigation->add('admin', new NavItem('Payment attempts', 'admin.billing.payment-attempts.index', 'Activity', 'Billing', 50, 'billing.payments.manage', 'admin.billing.payment-attempts.*'));
        $navigation->add('admin', new NavItem('Gateway log', 'admin.billing.gateway-log.index', 'Webhook', 'Setup', 42, 'billing.gateway_log.view', 'admin.billing.gateway-log.*'));

        $navigation->add('client', new NavItem('Invoices', 'client.invoices.index', 'Receipt', 'Billing', 10, activePattern: 'client.invoices.*'));
        $navigation->add('client', new NavItem('Quotes', 'client.quotes.index', 'ClipboardList', 'Billing', 20, activePattern: 'client.quotes.*'));
        $navigation->add('client', new NavItem('Credit & funds', 'client.credit.index', 'Wallet', 'Billing', 30, activePattern: 'client.credit.*'));
        $navigation->add('client', new NavItem('Payment methods', 'client.pay-methods.index', 'CreditCard', 'Billing', 40, activePattern: 'client.pay-methods.*'));

        $navigation->add('admin-create', new NavItem('New invoice', 'admin.billing.invoices.create', 'FileText', order: 20, permission: 'billing.invoices.manage'));
        $navigation->add('admin-create', new NavItem('New quote', 'admin.billing.quotes.create', 'ClipboardList', order: 21, permission: 'billing.quotes.manage'));

        $navigation->add('admin-client', new NavItem('Invoices', 'admin.billing.invoices.index', 'FileText', order: 10, permission: 'billing.invoices.view'));
        $navigation->add('admin-client', new NavItem('Transactions', 'admin.billing.transactions.index', 'ArrowLeftRight', order: 11, permission: 'billing.transactions.view'));
        $navigation->add('admin-client', new NavItem('Quotes', 'admin.billing.quotes.index', 'ClipboardList', order: 12, permission: 'billing.quotes.view'));
        $navigation->add('admin-client', new NavItem('Account credit', 'admin.billing.credit.index', 'Wallet', order: 13, permission: 'billing.credit.manage'));
    }

    private function registerDashboard(): void
    {
        $dashboard = $this->app->make(Dashboard::class);
        $this->app->make(BillingDashboard::class)->register($dashboard);

        $dashboard->card('client', 'billing.unpaid', function (?User $user, Client $client): array {
            $unpaid = Invoice::query()->where('client_id', $client->id)->whereIn('status', InvoiceStatus::payable());

            return [
                'label' => 'Unpaid invoices',
                ...self::moneyValue(Money::of((int) (clone $unpaid)->sum('balance'), $client->currency)),
                'hint' => (clone $unpaid)->count().' to pay',
                'href' => route('client.invoices.index', ['status' => 'unpaid']),
                'icon' => 'Receipt',
            ];
        }, order: 10);

        $dashboard->card('client', 'billing.credit', fn (?User $user, Client $client): array => [
            'label' => 'Credit balance',
            ...self::moneyValue($this->app->make(CreditLedgerService::class)->balance($client->id, $client->currency)),
            'href' => route('client.credit.index'),
            'icon' => 'Wallet',
        ], order: 20);
    }

    /**
     * Card value for money: a display string plus the raw minor units for the frontend's formatMoney().
     *
     * @return array{value: string, money: array{amount: int, currency: string}}
     */
    private static function moneyValue(Money $money): array
    {
        return [
            'value' => app(MoneyFormatter::class)->format($money),
            'money' => ['amount' => $money->amount, 'currency' => $money->currency],
        ];
    }

    /**
     * Money-moving and security-relevant permissions: never granted by wildcards (only explicitly or by "*"),
     * and their routes require a recent password confirmation (password.recent).
     */
    /** Client pay requests (invoice and batch) per user per minute. */
    public const PAY_REQUESTS_PER_MINUTE = 10;

    public const SENSITIVE_PERMISSIONS = [
        'billing.refunds.manage',
        'billing.payments.manage',
        'billing.transactions.manage',
        'billing.credit.adjust',
        'billing.gateways.manage',
        'billing.tax.manage',
    ];

    private function registerPermissions(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Billing', [
            'billing.invoices.view' => 'View invoices',
            'billing.invoices.manage' => 'Create, publish, cancel invoices and send reminders',
            'billing.payments.manage' => 'Record payments, mark paid, capture, apply credit',
            'billing.refunds.manage' => 'Refund transactions',
            'billing.transactions.view' => 'View transactions',
            'billing.transactions.manage' => 'Record manual transactions',
            'billing.credit.manage' => 'View client credit and invoicing preferences',
            'billing.credit.adjust' => 'Adjust client credit balances',
            'billing.quotes.view' => 'View quotes',
            'billing.quotes.manage' => 'Create, send and convert quotes',
            'billing.gateways.manage' => 'Configure payment gateways',
            'billing.gateway_log.view' => 'View the gateway log',
            'billing.tax.manage' => 'Manage tax rules',
            'billing.settings.manage' => 'Change billing settings',
        ], sensitive: self::SENSITIVE_PERMISSIONS);
    }

    private function registerSettings(): void
    {
        $bool = fn (string $key, string $label, ?string $help = null): SettingDefinition => new SettingDefinition('billing.'.$key, $label, 'boolean', $help);
        $int = fn (string $key, string $label, ?string $help = null): SettingDefinition => new SettingDefinition('billing.'.$key, $label, 'integer', $help, rules: ['min:0']);
        $list = fn (string $key, string $label, ?string $help = null): SettingDefinition => new SettingDefinition('billing.'.$key, $label, 'string', $help, rules: ['regex:/^\s*\d+(\s*,\s*\d+)*\s*$/']);

        $this->app->make(SettingsRegistry::class)->section('billing', 'Billing', [
            new SettingDefinition('billing.seller.legal_name', 'Seller legal name', help: 'Printed on invoices; defaults to the company name.', rules: ['max:200']),
            new SettingDefinition('billing.seller.address', 'Seller address', 'text', rules: ['max:1000']),
            new SettingDefinition('billing.seller.email', 'Seller billing email', 'email'),
            new SettingDefinition('billing.seller.phone', 'Seller phone', rules: ['max:50']),
            new SettingDefinition('billing.seller.gstin', 'Seller GSTIN (India)', rules: ['nullable', 'regex:/^\\d{2}[A-Z]{5}\\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/']),
            new SettingDefinition('billing.seller.pan', 'Seller PAN', rules: ['max:20']),
            new SettingDefinition('billing.seller.vat_number', 'Seller VAT/PAN number (Nepal VAT or EU VAT ID)', rules: ['max:30']),
            new SettingDefinition('billing.tax.default_sac', 'Default SAC code on invoice lines', help: '998315 = hosting and IT infrastructure provisioning', rules: ['max:10']),
            new SettingDefinition('billing.number_format', 'Invoice number format', help: 'Tokens: {NUMBER} {YEAR} {MONTH} {DAY}. Include {YEAR}/{MONTH} when numbers reset.', rules: ['max:40']),
            $int('number_padding', 'Number padding (digits)'),
            new SettingDefinition('billing.number_increment', 'Number increment', 'integer', rules: ['min:1']),
            new SettingDefinition('billing.number_reset', 'Reset numbering', 'select', options: ['never' => 'Never', 'yearly' => 'Yearly', 'monthly' => 'Monthly']),
            $bool('sequential_paid_numbering', 'Number invoices when paid', 'Unpaid invoices carry a proforma number; the legal number is assigned on payment.'),
            new SettingDefinition('billing.proforma_format', 'Proforma number format', rules: ['max:40']),
            $bool('set_issue_date_on_payment', 'Set issue date on payment (proforma mode)'),
            new SettingDefinition('billing.quote_number_format', 'Quote number format', rules: ['max:40']),
            $int('invoice_days_before_due', 'Days until due for manual invoices'),
            $bool('credit.auto_apply_on_generate', 'Apply credit to generated invoices'),
            $bool('credit.tax_add_funds', 'Charge tax on add funds'),
            $int('credit.min_deposit', 'Minimum deposit (minor units)'),
            $int('credit.max_deposit', 'Maximum deposit (minor units)'),
            $int('credit.max_balance', 'Maximum credit balance (minor units)'),
            $bool('credit.require_accepted_order', 'Require an accepted order before adding funds'),
            $bool('mass_pay_enabled', 'Allow paying several invoices at once'),
            $bool('separate_invoices', 'Separate invoice per renewal item', 'Off: renewal lines raised together are grouped on one invoice per client and due date. Clients can override this.'),
            $bool('clients_choose_gateway', 'Clients may choose the payment gateway'),
            $bool('allow_pay_method_removal', 'Clients may remove stored payment methods'),
            $bool('reversal.set_collections', 'Move reversed invoices to collections'),
            $bool('reversal.rollback_due_dates', 'Roll back service due dates on reversal'),
            $bool('tax.enabled', 'Enable tax'),
            new SettingDefinition('billing.tax.mode', 'Prices are', 'select', options: ['exclusive' => 'Exclusive of tax', 'inclusive' => 'Inclusive of tax']),
            $bool('tax.compound', 'Compound level 2 tax'),
            new SettingDefinition('billing.tax.calculation', 'Tax calculation', 'select', options: ['per_line' => 'Per line', 'per_invoice' => 'Per invoice']),
            new SettingDefinition('billing.tax.rounding', 'Tax rounding', 'select', options: ['half_up' => 'Half up', 'half_even' => 'Half even', 'down' => 'Down', 'up' => 'Up']),
            new SettingDefinition('billing.tax.apply_to', 'Taxed item kinds', help: 'Comma-separated: service, addon, domain, setup, late_fee, manual', rules: ['max:200']),
            new SettingDefinition('billing.tax.home_country', 'Seller tax country', help: 'ISO code used for intra-/inter-state rules, e.g. IN', rules: ['size:2']),
            new SettingDefinition('billing.tax.home_state', 'Seller state', help: 'State of registration, e.g. MH. India GST: CGST+SGST in this state, IGST elsewhere.', rules: ['max:100']),
            $bool('vat.enabled', 'Enable EU VAT rules'),
            new SettingDefinition('billing.vat.home_country', 'VAT home country', help: 'ISO code, e.g. DE', rules: ['size:2']),
            $bool('vat.auto_exempt_on_valid_id', 'Reverse charge for valid EU VAT IDs'),
            new SettingDefinition('billing.late_fee.type', 'Late fee type', 'select', options: ['percent' => 'Percent', 'fixed' => 'Fixed amount']),
            new SettingDefinition('billing.late_fee.amount', 'Late fee amount', help: 'Percent, or minor units when fixed. 0 turns late fees off.', rules: ['numeric', 'min:0']),
            $int('late_fee.minimum', 'Minimum late fee (minor units)'),
            $int('late_fee.days_overdue', 'Days overdue before the late fee'),
            new SettingDefinition('billing.late_fee.base', 'Late fee base', 'select', options: ['balance' => 'Balance', 'total' => 'Total']),
            $int('capture.days_before_due', 'Capture days before due'),
            $list('capture.retry_days', 'Capture retry days after first failure', 'Comma-separated, e.g. 3, 7'),
            $int('capture.max_failures_per_pay_method', 'Failures before a pay method is disabled'),
            $int('reminders.before_due_days', 'Reminder days before due (0 = off)'),
            $list('reminders.overdue_days', 'Overdue reminder days', 'Comma-separated, e.g. 1, 4, 7'),
            $int('auto_cancel.days_overdue', 'Auto-cancel after days overdue (0 = off)'),
            $int('gateways.lookup_interval_minutes', 'Hosted payments: minutes between status lookups'),
            $int('gateways.lookup_grace_minutes', 'Hosted payments: grace after expiry before giving up (minutes)'),
            $int('gateways.http_timeout_seconds', 'Gateway HTTP timeout (seconds)'),
            $int('gateways.ambiguous_alert_minutes', 'Alert staff about unresolved payments after (minutes)'),
            $bool('quotes.require_tos', 'Quotes require accepting terms'),
            $bool('quotes.auto_invoice_on_accept', 'Invoice quotes automatically on acceptance'),
            $int('quotes.valid_days', 'Default quote validity (days)'),
            $bool('pdf.attach_to_emails', 'Attach PDFs to invoice emails'),
            new SettingDefinition('billing.pdf.paper', 'PDF paper size', 'select', options: ['A4' => 'A4', 'letter' => 'Letter']),
        ], 'billing.settings.manage');
    }

    private function registerSearch(): void
    {
        $this->app->make(GlobalSearch::class)->provider('Invoices', fn (string $term): array => array_values(Invoice::query()
            ->with('client:id,first_name,last_name,company_name')
            ->search($term)->latest('id')->limit(5)->get()
            ->map(fn (Invoice $invoice): array => [
                'title' => $invoice->displayNumber(),
                'subtitle' => $invoice->client->display_name.' · '.app(MoneyFormatter::class)->format(Money::of($invoice->total, $invoice->currency)),
                'href' => route('admin.billing.invoices.show', $invoice),
            ])->all()), 'billing.invoices.view');
    }

    /**
     * The invoice as email merge fields: amounts formatted in its currency, and the lines, discount and taxes for the
     * "what you're paying for" block.
     *
     * @return array<string, mixed>
     */
    private function invoiceMergeFields(Invoice $invoice): array
    {
        $money = fn (int $amount): string => app(MoneyFormatter::class)->format(Money::of($amount, $invoice->currency));
        $rate = fn (string $value): string => rtrim(rtrim($value, '0'), '.');

        return [
            'client_id' => $invoice->client_id,
            'id' => $invoice->id,
            'number' => $invoice->displayNumber(),
            'status' => $invoice->displayStatus(),
            'currency' => $invoice->currency,
            'total' => $money($invoice->total),
            'balance' => $money($invoice->balance),
            'balance_minor' => $invoice->balance,
            'amount_paid' => $money($invoice->amount_paid),
            'subtotal' => $money($invoice->subtotal),
            'discount' => $invoice->discount_total > 0 ? $money(-$invoice->discount_total) : null,
            'credit_applied' => $invoice->credit_applied > 0 ? $money($invoice->credit_applied) : null,
            'taxes' => array_values(array_filter([
                $invoice->tax1_amount !== 0 && $invoice->tax1_name ? ['label' => $invoice->tax1_name.' '.$rate($invoice->tax1_rate).'%', 'amount' => $money($invoice->tax1_amount)] : null,
                $invoice->tax2_amount !== 0 && $invoice->tax2_name ? ['label' => $invoice->tax2_name.' '.$rate($invoice->tax2_rate).'%', 'amount' => $money($invoice->tax2_amount)] : null,
            ])),
            'items' => $invoice->items->whereNull('cancelled_at')->sortBy('sort_order')->map(fn (InvoiceItem $item): array => [
                'description' => $item->description,
                'period_start' => $item->period_start?->toDateString(),
                'period_end' => $item->period_end?->toDateString(),
                'amount' => $money($item->amount),
            ])->values()->all(),
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'payment_gateway' => $invoice->payment_gateway,
            'url' => route('client.invoices.show', $invoice),
            'pdf_url' => route('client.invoices.pdf', $invoice),
        ];
    }

    private function registerMergeFields(): void
    {
        $registry = $this->app->make(MergeFieldRegistry::class);

        $registry->register('invoice', fn (int $id): array => $this->invoiceMergeFields(Invoice::query()->with('items')->findOrFail($id)));

        // The oldest open invoice that bills a service (its overdue invoice, for the suspension email); [] when none.
        $registry->register('service_open_invoice', function (int $serviceId): array {
            $invoice = Invoice::query()->with('items')
                ->whereIn('status', array_map(fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::payable()))
                ->whereHas('items', fn ($items) => $items->where('type', BillableType::Service->value)->where('billable_id', $serviceId)->whereNull('cancelled_at'))
                ->orderBy('due_date')->orderBy('id')->first();

            return $invoice === null ? [] : $this->invoiceMergeFields($invoice);
        });

        $registry->register('quote', function (int $id): array {
            $quote = Quote::query()->findOrFail($id);

            return [
                'client_id' => $quote->client_id,
                'id' => $quote->id,
                'number' => $quote->displayNumber(),
                'subject' => $quote->subject,
                'stage' => $quote->stage->value,
                'total' => app(MoneyFormatter::class)->format(Money::of($quote->total, $quote->currency)),
                'valid_until' => $quote->valid_until?->toDateString(),
                'url' => route('client.quotes.show', $quote),
            ];
        });

        $registry->register('transaction', function (int $id): array {
            $transaction = Transaction::query()->findOrFail($id);

            return [
                'client_id' => $transaction->client_id,
                'id' => $transaction->id,
                'invoice_id' => $transaction->invoice_id,
                'type' => $transaction->type->value,
                'gateway' => $transaction->gateway,
                'reference' => $transaction->gateway_txn_id,
                'amount' => app(MoneyFormatter::class)->format(Money::of($transaction->amount_in > 0 ? $transaction->amount_in : $transaction->amount_out, $transaction->currency)),
                'date' => $transaction->occurred_at->toDateString(),
            ];
        });
    }
}
