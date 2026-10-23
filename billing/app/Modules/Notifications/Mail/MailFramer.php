<?php

namespace App\Modules\Notifications\Mail;

use App\Contracts\Branding\BrandProvider;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Support\MailDates;

/**
 * The system part of each email (design §3 and §9: "the operator edits copy, the system computes state"): status,
 * amount, download links, line items and the footer's reason, by template key. It reads a context that has every
 * secret removed (SecretTemplatePolicy::prepareContext or textContext), so nothing here can carry a private link.
 */
final class MailFramer
{
    private string $locale = 'en';

    public function __construct(private Settings $settings, private BrandProvider $brand) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function frame(?string $templateKey, array $context, string $locale = 'en'): MailFrame
    {
        $this->locale = $locale;
        $key = (string) $templateKey;
        $family = strstr($key, '.', true) ?: $key;

        return match (true) {
            $key === 'invoice.payment_confirmation' => $this->receipt($context),
            in_array($key, ['invoice.refunded', 'credit_note.issued'], true) => $this->refund($key, $context),
            $key === 'payment_method.expiring' => new MailFrame(label: self::text('Billing'), statusTone: 'warning', statusLabel: self::text('Expires soon'), reason: $this->billingReason($context)),
            $family === 'invoice' => $this->invoice($key, $context),
            $key === 'quote.delivered' => $this->quote($context),
            $key === 'order.confirmation' => $this->order($context),
            $family === 'service' => $this->service($key, $context),
            $family === 'domain' => $this->domain($key, $context),
            $family === 'ticket' => $this->ticket($key, $context),
            $family === 'auth', $key === 'client.migration_invitation' => $this->account($key, $context),
            $key === 'kyc.document_decided' => new MailFrame(label: self::text('Identity verification'), statusTone: data_get($context, 'kyc.approved') ? 'success' : 'warning', statusLabel: data_get($context, 'kyc.approved') ? self::text('Approved') : self::text('Declined')),
            $key === 'client.welcome' => new MailFrame(label: self::text('Your account'), statusTone: 'success', statusLabel: self::text('Ready')),
            $family === 'affiliate' => new MailFrame(label: self::text('Affiliate programme'), statusTone: 'success', statusLabel: $key === 'affiliate.withdrawal_processed' ? self::text('Paid out') : self::text('Active')),
            $family === 'staff' => $this->staff($key, $context),
            default => new MailFrame,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function invoice(string $key, array $context): MailFrame
    {
        $dueDate = $this->string($context, 'invoice.due_date');
        $days = MailDates::daysFromToday($dueDate);
        $due = $this->date($dueDate);
        $paid = strtolower((string) data_get($context, 'invoice.status')) === 'paid';
        $auto = $key === 'invoice.created_autocharge';

        [$tone, $label, $when] = match (true) {
            $paid => ['success', self::text('Paid'), null],
            $days === null => ['accent', self::text('Open'), null],
            $auto => ['info', self::text('Paid automatically'), self::text('on :date', ['date' => $due])],
            $days > 1 => ['accent', self::text('Due in :days days', ['days' => $days]), $due],
            $days === 1 => ['accent', self::text('Due tomorrow'), $due],
            $days === 0 => ['warning', self::text('Due today'), $due],
            default => ['danger', self::choice('Overdue by :days day|Overdue by :days days', -$days, ['days' => -$days]), self::text('was due :date', ['date' => $due])],
        };

        return new MailFrame(
            label: self::text('Invoice from :company', ['company' => $this->legalName()]),
            amount: $this->string($context, $auto ? 'invoice.total' : 'invoice.balance'),
            amountLabel: $auto ? self::text('Amount') : self::text('Amount due'),
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: $when,
            document: true,
            downloads: $this->invoiceDownload($context),
            lines: $this->lines($context, $key === 'invoice.created' || $auto ? self::text('What you\'re paying for') : self::text('On this invoice'), $auto ? self::text('Total') : self::text('Total due')),
            reason: $this->billingReason($context),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function receipt(array $context): MailFrame
    {
        $settled = ! (bool) data_get($context, 'invoice.balance_due', false);

        return new MailFrame(
            label: self::text('Receipt from :company', ['company' => $this->legalName()]),
            amount: $this->string($context, 'payment.amount'),
            amountLabel: self::text('Amount paid'),
            statusTone: $settled ? 'success' : 'warning',
            statusLabel: $settled ? self::text('Paid') : self::text('Part paid'),
            statusWhen: $this->date($this->string($context, 'payment.date')),
            document: true,
            downloads: $this->invoiceDownload($context),
            lines: $this->lines($context, self::text('What you paid for'), self::text('Total'), receipt: true),
            reason: self::text('You\'re receiving this because a payment was made on your account.'),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function refund(string $key, array $context): MailFrame
    {
        return new MailFrame(
            label: self::text('Refund from :company', ['company' => $this->legalName()]),
            amount: $this->string($context, $key === 'invoice.refunded' ? 'refund.amount' : 'credit_note.amount'),
            amountLabel: $key === 'invoice.refunded' ? self::text('Refunded') : self::text('Credit'),
            statusTone: 'info',
            statusLabel: $key === 'invoice.refunded' ? self::text('Refunded') : self::text('Credit issued'),
            statusWhen: $this->date(MailDates::companyDate()),
            document: true,
            downloads: $this->invoiceDownload($context),
            reason: $this->billingReason($context),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function quote(array $context): MailFrame
    {
        $validUntil = $this->date($this->string($context, 'quote.valid_until'));

        return new MailFrame(
            label: self::text('Quote from :company', ['company' => $this->legalName()]),
            amount: $this->string($context, 'quote.total'),
            amountLabel: self::text('Quote total'),
            statusTone: 'accent',
            statusLabel: self::text('Ready to review'),
            statusWhen: $validUntil !== null ? self::text('valid until :date', ['date' => $validUntil]) : null,
            document: true,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function order(array $context): MailFrame
    {
        return new MailFrame(
            label: self::text('Order #:number', ['number' => $this->string($context, 'order.number')]),
            statusTone: 'success',
            statusLabel: self::text('Order received'),
            statusWhen: $this->date($this->string($context, 'order.date')),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function service(string $key, array $context): MailFrame
    {
        [$tone, $label] = match ($key) {
            'service.suspended' => ['danger', self::text('Paused')],
            'service.unsuspended' => ['success', self::text('Running again')],
            'service.cancellation_requested' => ['warning', self::text('Cancellation requested')],
            'service.terminated' => ['muted', self::text('Removed')],
            'service.upgrade_completed' => ['success', self::text('Upgrade complete')],
            default => ['success', self::text('Ready')],
        };
        $invoice = $key === 'service.suspended' ? $this->string($context, 'invoice.number') : null;

        return new MailFrame(
            label: $this->string($context, 'service.product_name') ?? self::text('Your service'),
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: $invoice !== null ? self::text('invoice :number is overdue', ['number' => $invoice]) : $this->string($context, 'service.domain'),
            downloads: $invoice !== null ? $this->invoiceDownload($context) : [],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function domain(string $key, array $context): MailFrame
    {
        $expiry = $this->date($this->string($context, 'domain.expiry_date'));

        [$tone, $label, $when] = match ($key) {
            'domain.registration_confirmation' => ['success', self::text('Registered'), $expiry !== null ? self::text('until :date', ['date' => $expiry]) : null],
            'domain.transfer_initiated' => ['info', self::text('Transfer in progress'), null],
            'domain.transfer_completed' => ['success', self::text('Transferred'), null],
            'domain.transfer_failed' => ['danger', self::text('Transfer didn\'t complete'), null],
            'domain.renewal_confirmation' => ['success', self::text('Renewed'), $expiry !== null ? self::text('until :date', ['date' => $expiry]) : null],
            'domain.renewal_upcoming' => ['warning', self::text('Renewal due'), null],
            'domain.expired_notice' => ['danger', self::text('Expired'), null],
            default => ['accent', self::text('Domain'), null],
        };

        return new MailFrame(label: $this->string($context, 'domain.domain') ?? self::text('Your domains'), statusTone: $tone, statusLabel: $label, statusWhen: $when);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ticket(string $key, array $context): MailFrame
    {
        $number = $this->string($context, 'ticket.number');
        $department = $this->string($context, 'ticket.department') ?? self::text('Support');

        [$tone, $label, $summary] = match ($key) {
            'ticket.reply' => ['accent', self::text('Waiting for your reply'), self::text('Reply from :department', ['department' => $department])],
            'ticket.autoresponse' => ['info', self::text('Received'), $department],
            'ticket.opened_by_staff' => ['info', self::text('Opened for you'), $department],
            'ticket.auto_closed' => ['muted', self::text('Closed'), $department],
            default => ['warning', self::text('Not delivered'), $department],
        };

        return new MailFrame(
            label: $summary,
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: $number !== null ? self::text('Ticket #:number', ['number' => $number]) : null,
            reason: $number !== null ? self::text('You\'re receiving this because you\'re on ticket #:number.', ['number' => $number]) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function account(string $key, array $context): MailFrame
    {
        $expires = $this->string($context, 'auth.expires_at');

        [$tone, $label, $when] = match ($key) {
            'auth.password_reset' => ['accent', self::text('Reset requested'), $expires !== null ? self::text('link works until :time', ['time' => $expires]) : null],
            'auth.email_verification' => ['accent', self::text('Confirm your email'), null],
            'auth.email_changed' => ['warning', self::text('Email changed'), $this->date(MailDates::today())],
            'auth.user_invitation', 'client.migration_invitation' => ['accent', self::text('Invitation'), $expires !== null ? self::text('link works until :time', ['time' => $expires]) : null],
            'auth.password_changed' => ['warning', self::text('Password changed'), $this->date(MailDates::companyDate())],
            'auth.new_login_alert' => ['warning', self::text('New login'), $this->date(MailDates::companyDate())],
            default => ['info', self::text('Account change'), $this->date(MailDates::companyDate())],
        };

        return new MailFrame(label: self::text('Account security'), statusTone: $tone, statusLabel: $label, statusWhen: $when, security: true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function staff(string $key, array $context): MailFrame
    {
        $expires = $this->string($context, 'auth.expires_at');

        [$tone, $label] = match (true) {
            in_array($key, ['staff.order_fraud', 'staff.provisioning_failed', 'staff.registrar_action_failed', 'staff.login_failures', 'staff.webhook_endpoint_disabled', 'staff.template_render_failed'], true) => ['danger', self::text('Needs attention')],
            $key === 'staff.automation_summary' => data_get($context, 'automation.status') === 'failed' ? ['danger', self::text('Failed')] : ['success', self::text('Completed')],
            $key === 'staff.free_domain_renewal_blocked' || $key === 'staff.cancellation_request' => ['warning', self::text('Review')],
            str_starts_with($key, 'staff.ticket_') => ['info', self::text('Support')],
            $key === 'staff.order_new' => ['accent', self::text('New order')],
            in_array($key, ['staff.password_reset', 'staff.invitation'], true) => ['accent', $key === 'staff.invitation' ? self::text('Invitation') : self::text('Reset requested')],
            default => ['info', self::text('Account change')],
        };
        $security = in_array($key, ['staff.password_reset', 'staff.invitation', 'staff.login_failures', 'staff.two_factor_reset', 'staff.email_changed', 'staff.settings_changed'], true);

        return new MailFrame(
            label: $security ? self::text('Account security') : self::text('Admin area'),
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: in_array($key, ['staff.password_reset', 'staff.invitation'], true) && $expires !== null ? self::text('link works until :time', ['time' => $expires]) : null,
            reason: $security ? null : self::text('You\'re receiving this because your staff role gets these alerts.'),
            security: $security,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{heading: string, items: list<array{name: string, meta: string|null, amount: string}>, totals: list<array{label: string, amount: string, total: bool}>}|null
     */
    private function lines(array $context, string $heading, string $totalLabel, bool $receipt = false): ?array
    {
        $items = data_get($context, 'invoice.items');

        if (! is_array($items) || $items === []) {
            return null;
        }

        $lines = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['description'] ?? null) || ! is_string($item['amount'] ?? null)) {
                continue;
            }

            $start = $this->date($item['period_start'] ?? null);
            $end = $this->date($item['period_end'] ?? null);
            $lines[] = ['name' => $item['description'], 'meta' => $start !== null && $end !== null ? $start.' – '.$end : null, 'amount' => $item['amount']];
        }

        $partlyPaid = $this->string($context, 'invoice.balance') !== $this->string($context, 'invoice.total');
        $totals = array_values(array_filter([
            ['label' => self::text('Subtotal'), 'amount' => $this->string($context, 'invoice.subtotal'), 'total' => false],
            ['label' => self::text('Discount'), 'amount' => $this->string($context, 'invoice.discount'), 'total' => false],
            ...array_map(fn (mixed $tax): array => ['label' => (string) data_get($tax, 'label'), 'amount' => is_string(data_get($tax, 'amount')) ? data_get($tax, 'amount') : null, 'total' => false], (array) data_get($context, 'invoice.taxes', [])),
            // "Total due" only when nothing is paid yet; otherwise the balance line says what is due.
            ['label' => $partlyPaid ? self::text('Total') : $totalLabel, 'amount' => $this->string($context, 'invoice.total'), 'total' => true],
            ['label' => self::text('Paid'), 'amount' => (int) data_get($context, 'invoice.amount_paid_minor', 0) > 0 && $this->string($context, 'invoice.amount_paid') !== null ? '−'.$this->string($context, 'invoice.amount_paid') : null, 'total' => false],
            ['label' => self::text('Credit applied'), 'amount' => $this->string($context, 'invoice.credit_applied') !== null ? '−'.$this->string($context, 'invoice.credit_applied') : null, 'total' => false],
            $receipt || $partlyPaid ? ['label' => $receipt ? self::text('Still to pay') : self::text('Balance due'), 'amount' => $this->string($context, 'invoice.balance'), 'total' => false] : null,
        ], fn (?array $row): bool => $row !== null && is_string($row['amount']) && $row['amount'] !== ''));

        /** @var list<array{label: string, amount: string, total: bool}> $totals */
        return $lines === [] ? null : ['heading' => $heading, 'items' => $lines, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{label: string, url: string}>
     */
    private function invoiceDownload(array $context): array
    {
        $url = $this->string($context, 'invoice.pdf_url');

        return $url === null ? [] : [['label' => self::text('Download invoice'), 'url' => $url]];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function billingReason(array $context): string
    {
        $account = $this->string($context, 'client.company_name') ?? $this->string($context, 'client.full_name');

        return $account !== null
            ? self::text('You\'re receiving this because you\'re the billing contact for :account.', ['account' => $account])
            : self::text('You\'re receiving this because you\'re the billing contact for this account.');
    }

    private function legalName(): string
    {
        $name = $this->settings->get('billing.seller.legal_name') ?: $this->settings->get('core.company_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : $this->brand->brand()->name;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function string(array $context, string $path): ?string
    {
        $value = data_get($context, $path);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function choice(string $key, int $number, array $replace = []): string
    {
        return trans_choice($key, $number, $replace);
    }

    /**
     * A date in the date setting's format, in the recipient's language and company time.
     */
    private function date(mixed $value): ?string
    {
        return MailDates::date($value, null, $this->locale);
    }
}
