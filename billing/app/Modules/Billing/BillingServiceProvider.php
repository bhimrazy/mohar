<?php

namespace App\Modules\Billing;

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Events\Orders\OrderAccepted;
use App\Models\User;
use App\Modules\Billing\Drivers\Gateways\BankTransferDriver;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\ManualDriver;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Listeners\RecordAcceptedOrder;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\Tax\TaxEngine;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Automation\Automation;
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
use Illuminate\Support\Facades\Event;

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

        $this->app->singleton(GatewayManager::class, function (): GatewayManager {
            $manager = new GatewayManager;
            $manager->register(new BankTransferDriver);
            $manager->register(new ManualDriver);
            $manager->register(new SandboxCardDriver);

            return $manager;
        });
    }

    protected function bootModule(): void
    {
        Event::listen(OrderAccepted::class, RecordAcceptedOrder::class);

        $this->registerAutomation();
        $this->registerNavigation();
        $this->registerDashboard();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearch();
        $this->registerMergeFields();
    }

    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);
        $tasks = fn (): BillingAutomation => $this->app->make(BillingAutomation::class);

        $automation->task('billing.late_fees', Automation::LATE_FEES, fn (CarbonImmutable $today): int => $tasks()->lateFees($today));
        $automation->task('billing.auto_capture', Automation::AUTO_CHARGE, fn (CarbonImmutable $today): int => $tasks()->autoCapture($today));
        $automation->task('billing.reminders', Automation::REMINDERS, fn (CarbonImmutable $today): int => $tasks()->reminders($today));
        $automation->task('billing.auto_cancel', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => $tasks()->autoCancel($today));
        $automation->task('billing.housekeeping', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => $tasks()->housekeeping($today));
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);

        $navigation->add('admin', new NavItem('Invoices', 'admin.billing.invoices.index', 'FileText', 'Billing', 10, 'billing.invoices.view', 'admin.billing.invoices.*'));
        $navigation->add('admin', new NavItem('Transactions', 'admin.billing.transactions.index', 'ArrowLeftRight', 'Billing', 20, 'billing.transactions.view', 'admin.billing.transactions.*'));
        $navigation->add('admin', new NavItem('Quotes', 'admin.billing.quotes.index', 'ClipboardList', 'Billing', 30, 'billing.quotes.view', 'admin.billing.quotes.*'));
        $navigation->add('admin', new NavItem('Account credit', 'admin.billing.credit.index', 'Wallet', 'Billing', 40, 'billing.credit.manage', 'admin.billing.credit.*'));
        $navigation->add('admin', new NavItem('Gateways', 'admin.billing.gateways.index', 'CreditCard', 'Setup', 40, 'billing.gateways.manage', 'admin.billing.gateways.*'));
        $navigation->add('admin', new NavItem('Tax rules', 'admin.billing.tax-rules.index', 'Percent', 'Setup', 41, 'billing.tax.manage', 'admin.billing.tax-rules.*'));
        $navigation->add('admin', new NavItem('Gateway log', 'admin.billing.gateway-log.index', 'Webhook', 'Setup', 42, 'billing.gateway_log.view', 'admin.billing.gateway-log.*'));

        $navigation->add('client', new NavItem('Invoices', 'client.invoices.index', 'Receipt', 'Billing', 10, activePattern: 'client.invoices.*'));
        $navigation->add('client', new NavItem('Quotes', 'client.quotes.index', 'ClipboardList', 'Billing', 20, activePattern: 'client.quotes.*'));
        $navigation->add('client', new NavItem('Credit & funds', 'client.credit.index', 'Wallet', 'Billing', 30, activePattern: 'client.credit.*'));
        $navigation->add('client', new NavItem('Payment methods', 'client.pay-methods.index', 'CreditCard', 'Billing', 40, activePattern: 'client.pay-methods.*'));

        $navigation->add('admin-client', new NavItem('Invoices', 'admin.billing.invoices.index', 'FileText', order: 10, permission: 'billing.invoices.view'));
        $navigation->add('admin-client', new NavItem('Transactions', 'admin.billing.transactions.index', 'ArrowLeftRight', order: 11, permission: 'billing.transactions.view'));
        $navigation->add('admin-client', new NavItem('Quotes', 'admin.billing.quotes.index', 'ClipboardList', order: 12, permission: 'billing.quotes.view'));
        $navigation->add('admin-client', new NavItem('Account credit', 'admin.billing.credit.index', 'Wallet', order: 13, permission: 'billing.credit.manage'));
    }

    private function registerDashboard(): void
    {
        $dashboard = $this->app->make(Dashboard::class);
        $currency = fn (): string => Currency::default()->code;

        $dashboard->card('admin', 'billing.income', function (?User $user) use ($currency): array {
            $code = $currency();
            $start = CarbonImmutable::now()->startOfMonth();
            $income = (int) Transaction::query()->where('currency', $code)->where('occurred_at', '>=', $start)
                ->whereIn('type', [TransactionType::Payment, TransactionType::ManualIncome])->sum('amount_in');
            $out = (int) Transaction::query()->where('currency', $code)->where('occurred_at', '>=', $start)
                ->whereIn('type', [TransactionType::Refund, TransactionType::Chargeback])->sum('amount_out');

            return ['label' => 'Income this month', ...self::moneyValue(Money::of($income - $out, $code)), 'href' => route('admin.billing.transactions.index'), 'icon' => 'TrendingUp'];
        }, 'billing.transactions.view', 10);

        $dashboard->card('admin', 'billing.unpaid', fn (?User $user): array => [
            'label' => 'Unpaid invoices',
            ...self::moneyValue(Money::of((int) Invoice::query()->where('currency', $currency())->whereIn('status', InvoiceStatus::payable())->sum('balance'), $currency())),
            'hint' => Invoice::query()->whereIn('status', InvoiceStatus::payable())->count().' invoices',
            'href' => route('admin.billing.invoices.index', ['status' => 'unpaid']),
            'icon' => 'Receipt',
        ], 'billing.invoices.view', 20);

        $dashboard->card('admin', 'billing.overdue', fn (?User $user): array => [
            'label' => 'Overdue invoices',
            'value' => Invoice::query()->overdue()->count(),
            'href' => route('admin.billing.invoices.index', ['status' => 'overdue']),
            'icon' => 'AlertTriangle',
        ], 'billing.invoices.view', 30);

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
        $currency = Currency::query()->where('code', $money->currency)->first();

        return [
            'value' => $currency !== null ? $currency->format($money) : $money->format(),
            'money' => ['amount' => $money->amount, 'currency' => $money->currency],
        ];
    }

    private function registerPermissions(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Billing', [
            'billing.invoices.view' => 'View invoices',
            'billing.invoices.manage' => 'Create, publish, cancel invoices and send reminders',
            'billing.payments.manage' => 'Record payments, mark paid, capture, apply credit',
            'billing.refunds.manage' => 'Refund transactions',
            'billing.transactions.view' => 'View transactions',
            'billing.transactions.manage' => 'Record manual transactions',
            'billing.credit.manage' => 'View and adjust client credit',
            'billing.quotes.view' => 'View quotes',
            'billing.quotes.manage' => 'Create, send and convert quotes',
            'billing.gateways.manage' => 'Configure payment gateways',
            'billing.gateway_log.view' => 'View the gateway log',
            'billing.tax.manage' => 'Manage tax rules',
            'billing.settings.manage' => 'Change billing settings',
        ]);
    }

    private function registerSettings(): void
    {
        $bool = fn (string $key, string $label, ?string $help = null): SettingDefinition => new SettingDefinition('billing.'.$key, $label, 'boolean', $help);
        $int = fn (string $key, string $label, ?string $help = null): SettingDefinition => new SettingDefinition('billing.'.$key, $label, 'integer', $help, rules: ['min:0']);
        $list = fn (string $key, string $label, ?string $help = null): SettingDefinition => new SettingDefinition('billing.'.$key, $label, 'string', $help, rules: ['regex:/^\s*\d+(\s*,\s*\d+)*\s*$/']);

        $this->app->make(SettingsRegistry::class)->section('billing', 'Billing', [
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
                'subtitle' => $invoice->client->display_name.' · '.Money::of($invoice->total, $invoice->currency)->format(),
                'href' => route('admin.billing.invoices.show', $invoice),
            ])->all()), 'billing.invoices.view');
    }

    private function registerMergeFields(): void
    {
        $registry = $this->app->make(MergeFieldRegistry::class);

        $registry->register('invoice', function (int $id): array {
            $invoice = Invoice::query()->findOrFail($id);

            return [
                'client_id' => $invoice->client_id,
                'id' => $invoice->id,
                'number' => $invoice->displayNumber(),
                'status' => $invoice->displayStatus(),
                'currency' => $invoice->currency,
                'total' => Money::of($invoice->total, $invoice->currency)->format(),
                'balance' => Money::of($invoice->balance, $invoice->currency)->format(),
                'amount_paid' => Money::of($invoice->amount_paid, $invoice->currency)->format(),
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'payment_gateway' => $invoice->payment_gateway,
                'url' => route('client.invoices.show', $invoice),
            ];
        });

        $registry->register('quote', function (int $id): array {
            $quote = Quote::query()->findOrFail($id);

            return [
                'client_id' => $quote->client_id,
                'id' => $quote->id,
                'number' => $quote->displayNumber(),
                'subject' => $quote->subject,
                'stage' => $quote->stage->value,
                'total' => Money::of($quote->total, $quote->currency)->format(),
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
                'amount' => Money::of($transaction->amount_in > 0 ? $transaction->amount_in : $transaction->amount_out, $transaction->currency)->format(),
                'date' => $transaction->occurred_at->toDateString(),
            ];
        });
    }
}
