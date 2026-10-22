<?php

namespace App\Modules\Notifications\Services;

use App\Data\Notifications\SensitiveValue;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Support\MailDates;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Modules\Notifications\Support\SupportAddresses;
use Carbon\CarbonImmutable;

/**
 * Fictional data for previews and test sends (spec §9, acceptance criterion 20). It covers every merge
 * field the seeded templates use, so it doubles as the documented field list in the editor sidebar.
 */
class SampleContext
{
    public function __construct(private MergeContext $context) {}

    /**
     * @param  string|null  $templateKey  the template previewed, so the sample fits it (an overdue notice's invoice is overdue)
     * @return array<string, mixed>
     */
    public function build(bool $forStaff = false, ?string $templateKey = null): array
    {
        $today = MailDates::today();
        $inviteDays = min(30, max(1, (int) app(Settings::class)->get('platform.invitation_expiry_days', 7)));
        // Overdue notices preview an invoice that is overdue, everything else one due in two weeks.
        $due = CarbonImmutable::parse($today)->addDays(str_starts_with((string) $templateKey, 'invoice.overdue') ? -7 : 14)->toDateString();
        $period = [CarbonImmutable::parse($today)->addDays(14)->toDateString(), CarbonImmutable::parse($today)->addDays(14)->addYear()->subDay()->toDateString()];

        return [
            ...$this->context->base($forStaff),
            // The real support address when configured, so previews match the footer.
            'support' => ['email' => app(SupportAddresses::class)->general() ?? 'support@example.test', 'url' => url('/client/support/tickets')],
            'recipient' => ['name' => 'Ann Example', 'first_name' => 'Ann', 'email' => 'ann@example.test'],
            'client' => [
                'id' => 1042, 'first_name' => 'Ann', 'last_name' => 'Example', 'full_name' => 'Ann Example',
                'company_name' => 'Example Studio', 'email' => 'ann@example.test', 'currency' => 'USD', 'country' => 'NP',
                'language' => 'en', 'admin_url' => url('/admin/clients/1042'),
            ],
            'user' => ['first_name' => 'Ann', 'email' => 'ann@example.test'],
            'staff' => ['first_name' => 'Sam', 'email' => 'sam@example.test'],
            // Previews and test sends never generate a real token: these links are visibly fake and do nothing.
            'auth' => [
                'reset_url' => new SensitiveValue(rtrim((string) config('app.url'), '/').'/reset-password/SAMPLE-LINK-NOT-VALID'),
                'verify_url' => new SensitiveValue(rtrim((string) config('app.url'), '/').'/email/verify/SAMPLE-LINK-NOT-VALID'),
                'invite_url' => new SensitiveValue(rtrim((string) config('app.url'), '/').'/invitation/SAMPLE-LINK-NOT-VALID'),
                // Account links last an hour; invitations the configured number of days (the invitation templates).
                'expires_at' => MailDates::dateTime(str_contains((string) $templateKey, 'invitation') ? now()->addDays($inviteDays) : now()->addHour()),
                'expires_in' => trans_choice(':count day|:count days', $inviteDays, ['count' => $inviteDays]),
                'ip' => '203.0.113.24',
            ],
            'order' => [
                'number' => '8312004561', 'date' => $today, 'total' => 2898, 'currency' => 'USD', 'payment_method' => 'Card',
                'notes' => null, 'admin_url' => url('/admin/orders/1'),
                'items' => [
                    ['description' => 'Starter Hosting — example.com', 'amount' => 1899, 'cycle' => 'annually'],
                    ['description' => 'Domain registration — example.com', 'amount' => 999, 'cycle' => null],
                ],
            ],
            'kyc' => ['document' => 'Citizenship certificate', 'approved' => false, 'verified' => false, 'reason' => 'The photo of the back is blurred, so the issue date cannot be read.', 'url' => url('/client/verification')],
            'quote' => ['number' => 'Q-2026-0007', 'subject' => 'Website migration', 'total' => '$480.00', 'valid_until' => $due, 'url' => url('/client/quotes/7')],
            'invoice' => [
                'id' => 2101, 'number' => 'INV-2026-0101', 'status' => 'Unpaid', 'currency' => 'USD', 'total' => '$28.98',
                'balance' => '$18.98', 'balance_minor' => 1898, 'amount_paid' => '$10.00', 'amount_paid_minor' => 1000, 'subtotal' => '$28.98', 'discount' => null, 'credit_applied' => null,
                'taxes' => [], 'issue_date' => $today, 'due_date' => $due, 'issue_date_bs' => null, 'due_date_bs' => null,
                'items' => [
                    ['description' => 'Starter Hosting — example.com', 'period_start' => $period[0], 'period_end' => $period[1], 'amount' => '$18.99'],
                    ['description' => 'Domain renewal — example.com', 'period_start' => $period[0], 'period_end' => $period[1], 'amount' => '$9.99'],
                ],
                'payment_gateway' => 'card', 'url' => url('/client/invoices/2101'), 'pdf_url' => url('/client/invoices/2101/pdf'), 'days_overdue' => 7, 'balance_due' => true,
            ],
            'payment' => ['amount' => '$10.00', 'date' => $today, 'reference' => 'TXN-58201', 'gateway' => 'card', 'failure_reason' => 'Card declined'],
            'refund' => ['amount' => '$28.98'],
            'service' => [
                'id' => 501, 'product_name' => 'Starter Hosting', 'domain' => 'example.com', 'username' => 'examplec',
                'status' => 'active', 'url' => url('/client/services/501'), 'cycle' => 'annually', 'currency' => 'USD',
                'recurring_amount' => 1899, 'next_due_date' => $due, 'suspension_reason' => 'Overdue invoice',
                'dedicated_ip' => null, 'server_hostname' => 'web01.example.net', 'server_ip' => '198.51.100.10',
                'nameservers' => ['ns1.example.net', 'ns2.example.net'], 'admin_url' => url('/admin/services/501'),
            ],
            'cancellation' => ['type' => 'end_of_billing_period', 'reason' => 'Moving to a larger plan'],
            'domain' => [
                'id' => 77, 'domain' => 'example.com', 'registration_date' => $today, 'expiry_date' => now()->addYear()->toDateString(),
                'next_due_date' => now()->addYear()->toDateString(), 'days_until_expiry' => 365, 'period_years' => 1,
                'nameservers' => ['ns1.example.net', 'ns2.example.net'], 'renew_url' => url('/client/domains/77'),
                'transfer_failure_reason' => 'Invalid authorisation code',
            ],
            'domains' => [
                ['name' => 'example.com', 'expiry_date' => now()->addDays(30)->toDateString(), 'days_until_expiry' => 30, 'renew_url' => url('/client/domains/77')],
                ['name' => 'example.org', 'expiry_date' => now()->addDays(14)->toDateString(), 'days_until_expiry' => 14, 'renew_url' => url('/client/domains/78')],
            ],
            'ticket' => [
                'number' => '482913', 'subject' => 'Email not working', 'department' => 'Technical Support', 'priority' => 'Medium',
                'status' => 'Open', 'requester_name' => 'Ann Example',
                // A guest's ticket link carries its access key: sensitive, linkable only from ticket.* mail.
                'url' => new SensitiveValue(url('/support/tickets/482913?key=SAMPLE-KEY'), secret: 'SAMPLE-KEY'),
                'admin_url' => url('/admin/support/tickets/1'),
            ],
            'reply' => [
                'author_name' => 'Sam Rivera',
                'body_html' => '<p>Thanks for the details — the mailbox quota was full. We have raised it, please try again.</p>',
                'excerpt' => 'Thanks for the details — the mailbox quota was full.',
            ],
            'affiliate' => ['referral_url' => url('/?ref=ANN42'), 'code' => 'ANN42', 'balance_available' => '$45.00', 'balance_pending' => '$12.00', 'withdrawal_amount' => '$45.00'],
            'failure' => ['action' => 'create', 'error' => 'Connection to the control panel timed out'],
            'sync' => ['change_count' => 2, 'error_count' => 1, 'lines' => ['example.com: expiry date updated', 'example.org: registrar timeout']],
            'automation' => ['run_at' => $today, 'status' => 'completed'],
            'security' => ['email' => 'sam@example.test', 'failures' => 10],
            'webhook' => ['url' => 'https://hooks.example.com/billing', 'consecutive_failures' => 20, 'admin_url' => url('/admin/notifications/webhooks/1')],
            'notice' => [
                'account' => 'Example Studio', 'email' => 'sam@example.test', 'actor' => 'Sam Rivera', 'section' => 'Security',
                'changes' => 'Require two-step sign-in', 'old_email' => 'sam@example.test', 'new_email' => 'sam.rivera@example.test',
            ],
            'credit_note' => ['amount' => '$10.00'],
            'template' => ['key' => 'invoice.created', 'name' => 'Invoice created', 'error' => 'Line 3: "{% if %}" is never closed.', 'admin_url' => url('/admin/notifications/templates/1/edit')],
        ];
    }

    /**
     * Merge-field groups shown in the editor sidebar for a template type.
     *
     * @return array<string, list<string>>
     */
    public function fieldsFor(TemplateType $type): array
    {
        $groups = match ($type) {
            TemplateType::General => ['client', 'user', 'auth', 'order', 'quote', 'affiliate', 'notice'],
            TemplateType::Product => ['client', 'service', 'cancellation'],
            TemplateType::Invoice => ['client', 'invoice', 'payment', 'refund', 'credit_note'],
            TemplateType::Domain => ['client', 'domain', 'domains'],
            TemplateType::Support => ['ticket', 'reply'],
            TemplateType::Admin => ['staff', 'client', 'order', 'service', 'domain', 'ticket', 'reply', 'invoice', 'failure', 'sync', 'automation', 'security', 'webhook', 'template', 'auth', 'cancellation', 'affiliate', 'notice'],
        };

        $sample = $this->build($type === TemplateType::Admin);
        $fields = [
            'company' => $this->paths('company', $sample['company']),
            'support' => $this->paths('support', $sample['support']),
            'system' => $this->paths('system', $sample['system']),
            'recipient' => $this->paths('recipient', $sample['recipient']),
        ];

        foreach ($groups as $group) {
            $fields[$group] = $this->paths($group, $sample[$group]);
        }

        $fields['signature'] = ['signature'];

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function paths(string $prefix, mixed $value): array
    {
        if ($value instanceof SensitiveValue || SecretTemplatePolicy::isSecretName($prefix)) {
            return [];
        }

        if (! is_array($value) || array_is_list($value)) {
            return [$prefix];
        }

        $paths = [];

        foreach ($value as $key => $child) {
            $paths = [...$paths, ...$this->paths($prefix.'.'.$key, $child)];
        }

        return $paths;
    }
}
