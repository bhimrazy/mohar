<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Core\Support\DateFormats;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Modules\Notifications\Support\SupportAddresses;

/**
 * Fictional data for previews and test sends (spec §9, acceptance criterion 20). It covers every merge
 * field the seeded templates use, so it doubles as the documented field list in the editor sidebar.
 */
class SampleContext
{
    public function __construct(private MergeContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function build(bool $forStaff = false): array
    {
        $today = now()->toDateString();
        $due = now()->addDays(14)->toDateString();

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
                'reset_url' => url('/reset-password/SAMPLE-LINK-NOT-VALID'),
                'verify_url' => url('/email/verify/SAMPLE-LINK-NOT-VALID'),
                'invite_url' => url('/invitation/SAMPLE-LINK-NOT-VALID'),
                'expires_at' => now()->addHour()->format(DateFormats::current().' H:i'),
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
            'quote' => ['number' => 'Q-2026-0007', 'subject' => 'Website migration', 'total' => '$480.00', 'valid_until' => $due, 'url' => url('/client/quotes/7')],
            'invoice' => [
                'id' => 2101, 'number' => 'INV-2026-0101', 'status' => 'Unpaid', 'currency' => 'USD', 'total' => '$28.98',
                'balance' => '$28.98', 'amount_paid' => '$0.00', 'issue_date' => $today, 'due_date' => $due,
                'payment_gateway' => 'card', 'url' => url('/client/invoices/2101'), 'days_overdue' => 7, 'balance_due' => true,
            ],
            'payment' => ['amount' => '$28.98', 'date' => $today, 'reference' => 'TXN-58201', 'gateway' => 'card', 'failure_reason' => 'Card declined'],
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
                'status' => 'Open', 'requester_name' => 'Ann Example', 'url' => url('/client/support/tickets/482913'),
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
            'template' => ['key' => 'invoice.created', 'error' => 'Line 3: "{% if %}" is never closed.', 'admin_url' => url('/admin/notifications/templates/1/edit')],
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
            TemplateType::General => ['client', 'user', 'auth', 'order', 'quote', 'affiliate'],
            TemplateType::Product => ['client', 'service', 'cancellation'],
            TemplateType::Invoice => ['client', 'invoice', 'payment', 'refund'],
            TemplateType::Domain => ['client', 'domain', 'domains'],
            TemplateType::Support => ['ticket', 'reply'],
            TemplateType::Admin => ['staff', 'client', 'order', 'service', 'domain', 'ticket', 'reply', 'invoice', 'failure', 'sync', 'automation', 'security', 'webhook', 'template', 'auth', 'cancellation', 'affiliate'],
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
        if (SecretTemplatePolicy::isSecretPath($prefix)) {
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
