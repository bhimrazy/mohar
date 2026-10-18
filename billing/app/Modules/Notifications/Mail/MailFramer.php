<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Support\MailClock;

/**
 * The system part of each email (design §3 and §9: "the operator edits copy, the system computes state"): status,
 * amount, download links, line items and the footer's reason, by template key. It reads a context that has every
 * secret removed (SecretTemplatePolicy::prepareContext or textContext), so nothing here can carry a private link.
 */
final class MailFramer
{
    public function __construct(private Settings $settings) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function frame(?string $templateKey, array $context): MailFrame
    {
        $key = (string) $templateKey;
        $family = strstr($key, '.', true) ?: $key;

        return match (true) {
            $key === 'invoice.payment_confirmation' => $this->receipt($context),
            in_array($key, ['invoice.refunded', 'credit_note.issued'], true) => $this->refund($key, $context),
            $key === 'payment_method.expiring' => new MailFrame(label: __('Billing'), statusTone: 'warning', statusLabel: __('Expires soon'), reason: $this->billingReason($context)),
            $family === 'invoice' => $this->invoice($key, $context),
            $key === 'quote.delivered' => $this->quote($context),
            $key === 'order.confirmation' => $this->order($context),
            $family === 'service' => $this->service($key, $context),
            $family === 'domain' => $this->domain($key, $context),
            $family === 'ticket' => $this->ticket($key, $context),
            $family === 'auth' => $this->account($key, $context),
            $key === 'client.welcome' => new MailFrame(label: __('Your account'), statusTone: 'success', statusLabel: __('Ready')),
            $family === 'affiliate' => new MailFrame(label: __('Affiliate programme'), statusTone: 'success', statusLabel: $key === 'affiliate.withdrawal_processed' ? __('Paid out') : __('Active')),
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
        $days = MailClock::daysFromToday($dueDate);
        $due = MailClock::date($dueDate);
        $paid = strtolower((string) data_get($context, 'invoice.status')) === 'paid';
        $auto = $key === 'invoice.created_autocharge';

        [$tone, $label, $when] = match (true) {
            $paid => ['success', __('Paid'), null],
            $days === null => ['accent', __('Open'), null],
            $auto => ['info', __('Paid automatically'), __('on :date', ['date' => $due])],
            $days > 1 => ['accent', __('Due in :days days', ['days' => $days]), $due],
            $days === 1 => ['accent', __('Due tomorrow'), $due],
            $days === 0 => ['warning', __('Due today'), $due],
            default => ['danger', trans_choice('Overdue by :days day|Overdue by :days days', -$days, ['days' => -$days]), __('was due :date', ['date' => $due])],
        };

        return new MailFrame(
            label: __('Invoice from :company', ['company' => $this->legalName()]),
            amount: $this->string($context, $auto ? 'invoice.total' : 'invoice.balance'),
            amountLabel: $auto ? __('Amount') : __('Amount due'),
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: $when,
            document: true,
            downloads: $this->invoiceDownload($context),
            lines: $this->lines($context, $key === 'invoice.created' || $auto ? __('What you\'re paying for') : __('On this invoice'), $auto ? __('Total') : __('Total due')),
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
            label: __('Receipt from :company', ['company' => $this->legalName()]),
            amount: $this->string($context, 'payment.amount'),
            amountLabel: __('Amount paid'),
            statusTone: $settled ? 'success' : 'warning',
            statusLabel: $settled ? __('Paid') : __('Part paid'),
            statusWhen: MailClock::date($this->string($context, 'payment.date')),
            document: true,
            downloads: $this->invoiceDownload($context),
            lines: $this->lines($context, __('What you paid for'), __('Total'), receipt: true),
            reason: __('You\'re receiving this because a payment was made on your account.'),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function refund(string $key, array $context): MailFrame
    {
        return new MailFrame(
            label: __('Refund from :company', ['company' => $this->legalName()]),
            amount: $key === 'invoice.refunded' ? $this->string($context, 'refund.amount') : null,
            amountLabel: __('Refunded'),
            statusTone: 'info',
            statusLabel: $key === 'invoice.refunded' ? __('Refunded') : __('Credit issued'),
            statusWhen: MailClock::date(MailClock::today()),
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
        $validUntil = MailClock::date($this->string($context, 'quote.valid_until'));

        return new MailFrame(
            label: __('Quote from :company', ['company' => $this->legalName()]),
            amount: $this->string($context, 'quote.total'),
            amountLabel: __('Quote total'),
            statusTone: 'accent',
            statusLabel: __('Ready to review'),
            statusWhen: $validUntil !== null ? __('valid until :date', ['date' => $validUntil]) : null,
            document: true,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function order(array $context): MailFrame
    {
        return new MailFrame(
            label: __('Order #:number', ['number' => $this->string($context, 'order.number')]),
            statusTone: 'success',
            statusLabel: __('Order received'),
            statusWhen: MailClock::date($this->string($context, 'order.date')),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function service(string $key, array $context): MailFrame
    {
        [$tone, $label] = match ($key) {
            'service.suspended' => ['danger', __('Paused')],
            'service.unsuspended' => ['success', __('Running again')],
            'service.cancellation_requested' => ['warning', __('Cancellation requested')],
            'service.terminated' => ['muted', __('Removed')],
            'service.upgrade_completed' => ['success', __('Upgrade complete')],
            default => ['success', __('Ready')],
        };
        $invoice = $key === 'service.suspended' ? $this->string($context, 'invoice.number') : null;

        return new MailFrame(
            label: $this->string($context, 'service.product_name') ?? __('Your service'),
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: $this->string($context, 'service.domain'),
            downloads: $invoice !== null ? $this->invoiceDownload($context) : [],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function domain(string $key, array $context): MailFrame
    {
        $expiry = MailClock::date($this->string($context, 'domain.expiry_date'));

        [$tone, $label, $when] = match ($key) {
            'domain.registration_confirmation' => ['success', __('Registered'), $expiry !== null ? __('until :date', ['date' => $expiry]) : null],
            'domain.transfer_initiated' => ['info', __('Transfer in progress'), null],
            'domain.transfer_completed' => ['success', __('Transferred'), null],
            'domain.transfer_failed' => ['danger', __('Transfer didn\'t complete'), null],
            'domain.renewal_confirmation' => ['success', __('Renewed'), $expiry !== null ? __('until :date', ['date' => $expiry]) : null],
            'domain.renewal_upcoming' => ['warning', __('Renewal due'), null],
            'domain.expired_notice' => ['danger', __('Expired'), null],
            default => ['accent', __('Domain'), null],
        };

        return new MailFrame(label: $this->string($context, 'domain.domain') ?? __('Your domains'), statusTone: $tone, statusLabel: $label, statusWhen: $when);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ticket(string $key, array $context): MailFrame
    {
        $number = $this->string($context, 'ticket.number');
        $department = $this->string($context, 'ticket.department') ?? __('Support');

        [$tone, $label, $summary] = match ($key) {
            'ticket.reply' => ['accent', __('Waiting for your reply'), __('Reply from :department', ['department' => $department])],
            'ticket.autoresponse' => ['info', __('Received'), $department],
            'ticket.opened_by_staff' => ['info', __('Opened for you'), $department],
            'ticket.auto_closed' => ['muted', __('Closed'), $department],
            default => ['warning', __('Not delivered'), $department],
        };

        return new MailFrame(
            label: $summary,
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: $number !== null ? __('Ticket #:number', ['number' => $number]) : null,
            reason: $number !== null ? __('You\'re receiving this because you\'re on ticket #:number.', ['number' => $number]) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function account(string $key, array $context): MailFrame
    {
        $expires = $this->string($context, 'auth.expires_at');

        [$tone, $label, $when] = match ($key) {
            'auth.password_reset' => ['accent', __('Reset requested'), $expires !== null ? __('link works until :time', ['time' => $expires]) : null],
            'auth.email_verification' => ['accent', __('Confirm your email'), null],
            'auth.user_invitation' => ['accent', __('Invitation'), $expires !== null ? __('link works until :time', ['time' => $expires]) : null],
            'auth.password_changed' => ['warning', __('Password changed'), MailClock::date(MailClock::today())],
            'auth.new_login_alert' => ['warning', __('New sign-in'), MailClock::date(MailClock::today())],
            default => ['info', __('Account change'), MailClock::date(MailClock::today())],
        };

        return new MailFrame(label: __('Account security'), statusTone: $tone, statusLabel: $label, statusWhen: $when, security: true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function staff(string $key, array $context): MailFrame
    {
        $expires = $this->string($context, 'auth.expires_at');

        [$tone, $label] = match (true) {
            in_array($key, ['staff.order_fraud', 'staff.provisioning_failed', 'staff.registrar_action_failed', 'staff.login_failures', 'staff.webhook_endpoint_disabled', 'staff.template_render_failed'], true) => ['danger', __('Needs attention')],
            $key === 'staff.automation_summary' => data_get($context, 'automation.status') === 'failed' ? ['danger', __('Failed')] : ['success', __('Completed')],
            $key === 'staff.free_domain_renewal_blocked' || $key === 'staff.cancellation_request' => ['warning', __('Review')],
            str_starts_with($key, 'staff.ticket_') => ['info', __('Support')],
            $key === 'staff.order_new' => ['accent', __('New order')],
            in_array($key, ['staff.password_reset', 'staff.invitation'], true) => ['accent', $key === 'staff.invitation' ? __('Invitation') : __('Reset requested')],
            default => ['info', __('Account change')],
        };
        $security = in_array($key, ['staff.password_reset', 'staff.invitation', 'staff.login_failures', 'staff.two_factor_reset', 'staff.email_changed', 'staff.settings_changed'], true);

        return new MailFrame(
            label: $security ? __('Account security') : __('Admin area'),
            statusTone: $tone,
            statusLabel: $label,
            statusWhen: in_array($key, ['staff.password_reset', 'staff.invitation'], true) && $expires !== null ? __('link works until :time', ['time' => $expires]) : null,
            reason: $security ? null : __('You\'re receiving this because your staff role gets these alerts.'),
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

            $start = MailClock::date($item['period_start'] ?? null);
            $end = MailClock::date($item['period_end'] ?? null);
            $lines[] = ['name' => $item['description'], 'meta' => $start !== null && $end !== null ? $start.' – '.$end : null, 'amount' => $item['amount']];
        }

        $totals = array_values(array_filter([
            ['label' => __('Subtotal'), 'amount' => $this->string($context, 'invoice.subtotal'), 'total' => false],
            ['label' => __('Discount'), 'amount' => $this->string($context, 'invoice.discount'), 'total' => false],
            ...array_map(fn (mixed $tax): array => ['label' => (string) data_get($tax, 'label'), 'amount' => is_string(data_get($tax, 'amount')) ? data_get($tax, 'amount') : null, 'total' => false], (array) data_get($context, 'invoice.taxes', [])),
            ['label' => $totalLabel, 'amount' => $this->string($context, 'invoice.total'), 'total' => true],
            ['label' => __('Credit applied'), 'amount' => $this->string($context, 'invoice.credit_applied'), 'total' => false],
            $receipt || $this->string($context, 'invoice.balance') !== $this->string($context, 'invoice.total') ? ['label' => $receipt ? __('Still to pay') : __('Balance due'), 'amount' => $this->string($context, 'invoice.balance'), 'total' => false] : null,
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

        return $url === null ? [] : [['label' => __('Download invoice'), 'url' => $url]];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function billingReason(array $context): string
    {
        $account = $this->string($context, 'client.company_name') ?? $this->string($context, 'client.full_name');

        return $account !== null
            ? __('You\'re receiving this because you\'re the billing contact for :account.', ['account' => $account])
            : __('You\'re receiving this because you\'re the billing contact for this account.');
    }

    private function legalName(): string
    {
        $name = $this->settings->get('billing.seller.legal_name') ?: $this->settings->get('core.company_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) config('app.name');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function string(array $context, string $path): ?string
    {
        $value = data_get($context, $path);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
