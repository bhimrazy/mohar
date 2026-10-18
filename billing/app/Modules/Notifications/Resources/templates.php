<?php

/*
 * v1 seed catalog (spec §5). Keys and copy are ours. Bodies are Markdown with the sandboxed merge syntax
 * ({{ path }}, {% if %}, {% for %}); every path used here exists in SampleContext, so previews render
 * without warnings. Support-type client subjects are fixed by EmailComposer and only listed for completeness.
 *
 * @return list<array{key: string, type: string, audience: string, name: string, subject: string, body: string}>
 */

$client = static fn (string $key, string $type, string $name, string $subject, string $body): array => [
    'key' => $key, 'type' => $type, 'audience' => 'client', 'name' => $name, 'subject' => $subject, 'body' => $body,
];
$staff = static fn (string $key, string $name, string $subject, string $body): array => [
    'key' => $key, 'type' => 'admin', 'audience' => 'staff', 'name' => $name, 'subject' => $subject, 'body' => $body,
];

$signOff = "\n\n{{ signature | default(company.name) }}";
$supportSubject = '[#{{ ticket.number }}] {{ ticket.subject }}';

return [
    // ── General (client) ─────────────────────────────────────────────────────────────────────────
    $client('auth.password_reset', 'general', 'Password reset link', 'Reset your {{ company.name }} password', <<<'MD'
Hi {{ recipient.first_name }},

Someone asked to reset the password for {{ user.email }}. If that was you, choose a new password here:

[Choose a new password]({{ auth.reset_url }})

The link stops working at {{ auth.expires_at }}. If you didn't ask for this, you can ignore this email — your password stays the same.
MD),
    $client('auth.password_changed', 'general', 'Password changed', 'Your {{ company.name }} password was changed', <<<'MD'
Hi {{ recipient.first_name }},

The password for {{ user.email }} was changed on {{ system.today | date }}. If this wasn't you, reset your password straight away and contact us at {{ company.email }}.
MD),
    $client('auth.email_verification', 'general', 'Verify email address', 'Confirm your email address', <<<'MD'
Hi {{ recipient.first_name }},

Please confirm that {{ user.email }} is your address so we can keep you informed about your account.

[Confirm email address]({{ auth.verify_url }})

If you didn't create an account with {{ company.name }}, no action is needed.
MD),
    $client('auth.user_invitation', 'general', 'Account invitation', 'You have been invited to manage {{ client.company_name | default(client.full_name) }}', <<<'MD'
Hello,

You have been given access to the {{ company.name }} account of {{ client.company_name | default(client.full_name) }}.

[Accept the invitation]({{ auth.invite_url }})

The invitation expires at {{ auth.expires_at }}.
MD),
    $client('auth.new_login_alert', 'general', 'New sign-in alert', 'New sign-in to your {{ company.name }} account', <<<'MD'
Hi {{ recipient.first_name }},

We noticed a new sign-in to {{ user.email }} from IP address {{ auth.ip }} on {{ system.today | date }}. If this wasn't you, change your password now.
MD),
    $client('client.welcome', 'general', 'Welcome to the client area', 'Welcome to {{ company.name }}', <<<'MD'
Hi {{ client.first_name }},

Thanks for creating an account with {{ company.name }}. From the client area you can order services, pay invoices, manage domains and contact our team.

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('order.confirmation', 'general', 'Order confirmation', 'We received your order #{{ order.number }}', <<<'MD'
Hi {{ client.first_name }},

Thank you for your order. Here is a summary:

{% for item in order.items %}- {{ item.description }} — {{ item.amount | money(order.currency) }}{% if item.cycle %} ({{ item.cycle }}){% endif %}
{% endfor %}
**Total:** {{ order.total | money(order.currency) }}

We'll let you know as soon as everything is set up.
MD.$signOff),
    $client('quote.delivered', 'general', 'Quote delivered', 'Your quote {{ quote.number }}: {{ quote.subject }}', <<<'MD'
Hi {{ client.first_name }},

We've prepared quote **{{ quote.number }}** for you, totalling {{ quote.total }}. It is valid until {{ quote.valid_until | date }}.

[View the quote]({{ quote.url }})
MD.$signOff),
    $client('affiliate.activated', 'general', 'Affiliate account activated', 'Your affiliate account is ready', <<<'MD'
Hi {{ client.first_name }},

Your affiliate account is active. Share your referral link and earn commission on every qualifying order:

{{ affiliate.referral_url }}
MD.$signOff),
    $client('affiliate.withdrawal_processed', 'general', 'Affiliate withdrawal processed', 'Your affiliate withdrawal was processed', <<<'MD'
Hi {{ client.first_name }},

We've processed your withdrawal of {{ affiliate.withdrawal_amount }}. Your remaining available balance is {{ affiliate.balance_available }}.
MD.$signOff),
    $client('affiliate.monthly_summary', 'general', 'Affiliate monthly summary', 'Your affiliate summary', <<<'MD'
Hi {{ client.first_name }},

Available balance: {{ affiliate.balance_available }} · Pending: {{ affiliate.balance_pending }}.

Your referral link: {{ affiliate.referral_url }}
MD.$signOff),

    // ── Product ──────────────────────────────────────────────────────────────────────────────────
    $client('service.welcome.shared_hosting', 'product', 'Welcome: shared hosting', 'Your hosting for {{ service.domain | default(service.product_name) }} is ready', <<<'MD'
Hi {{ client.first_name }},

Your **{{ service.product_name }}** hosting account is active.

- Domain: {{ service.domain }}
- Username: {{ service.username }}
{% if service.password %}- Password: {{ service.password }}
{% endif %}- Server: {{ service.server_hostname }} ({{ service.server_ip }})

Point your domain to these nameservers:

{% for nameserver in service.nameservers %}- {{ nameserver }}
{% endfor %}
Your next renewal is due on {{ service.next_due_date | date }}.
MD.$signOff),
    $client('service.welcome.reseller_hosting', 'product', 'Welcome: reseller hosting', 'Your reseller account is ready', <<<'MD'
Hi {{ client.first_name }},

Your **{{ service.product_name }}** reseller account is active.

- Username: {{ service.username }}
{% if service.password %}- Password: {{ service.password }}
{% endif %}- Server: {{ service.server_hostname }} ({{ service.server_ip }})

Nameservers for your customers:

{% for nameserver in service.nameservers %}- {{ nameserver }}
{% endfor %}
MD.$signOff),
    $client('service.welcome.server', 'product', 'Welcome: server', 'Your server {{ service.domain | default(service.product_name) }} is ready', <<<'MD'
Hi {{ client.first_name }},

Your **{{ service.product_name }}** server is online.

- Hostname: {{ service.domain }}
- Primary IP: {{ service.dedicated_ip | default(service.server_ip) }}
- Username: {{ service.username }}
{% if service.password %}- Password: {{ service.password }}
{% endif %}
Please change the password after your first sign-in.
MD.$signOff),
    $client('service.welcome.other', 'product', 'Welcome: other products', 'Your {{ service.product_name }} is active', <<<'MD'
Hi {{ client.first_name }},

Your **{{ service.product_name }}** is now active. You can manage it from the client area at any time.

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('service.suspended', 'product', 'Service suspended', '{{ service.product_name }} has been suspended', <<<'MD'
Hi {{ client.first_name }},

Your **{{ service.product_name }}**{% if service.domain %} for {{ service.domain }}{% endif %} has been suspended.

Reason: {{ service.suspension_reason | default('not specified') }}

Settle any open invoices or reply to this email and we'll help you get it running again.
MD.$signOff),
    $client('service.unsuspended', 'product', 'Service reactivated', '{{ service.product_name }} is active again', <<<'MD'
Hi {{ client.first_name }},

Good news — your **{{ service.product_name }}**{% if service.domain %} for {{ service.domain }}{% endif %} is active again.
MD.$signOff),
    $client('service.cancellation_requested', 'product', 'Cancellation request received', 'We received your cancellation request', <<<'MD'
Hi {{ client.first_name }},

We've received your request to cancel **{{ service.product_name }}**{% if service.domain %} ({{ service.domain }}){% endif %}.

Type: {{ cancellation.type }}

If you change your mind, contact us before the cancellation date.
MD.$signOff),
    $client('service.upgrade_completed', 'product', 'Upgrade completed', 'Your upgrade is complete', <<<'MD'
Hi {{ client.first_name }},

Your service has been moved to **{{ service.product_name }}**. The new recurring amount is {{ service.recurring_amount | money(service.currency) }}.
MD.$signOff),
    $client('service.terminated', 'product', 'Service terminated', '{{ service.product_name }} has been removed', <<<'MD'
Hi {{ client.first_name }},

Your **{{ service.product_name }}**{% if service.domain %} for {{ service.domain }}{% endif %} has been terminated and its data removed.
MD.$signOff),

    // ── Invoice ──────────────────────────────────────────────────────────────────────────────────
    $client('invoice.created', 'invoice', 'Invoice created', 'Invoice {{ invoice.number }} is ready', <<<'MD'
Hi {{ client.first_name }},

Invoice **{{ invoice.number }}** for {{ invoice.total }} is ready. Payment is due by {{ invoice.due_date | date }}.

[View and pay the invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.created_autocharge', 'invoice', 'Invoice created (automatic payment)', 'Invoice {{ invoice.number }} will be charged automatically', <<<'MD'
Hi {{ client.first_name }},

Invoice **{{ invoice.number }}** for {{ invoice.total }} has been issued. We'll charge your saved payment method on {{ invoice.due_date | date }} — no action needed.

[View the invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.payment_reminder', 'invoice', 'Payment reminder', 'Reminder: invoice {{ invoice.number }} is due {{ invoice.due_date | date }}', <<<'MD'
Hi {{ client.first_name }},

A friendly reminder that invoice **{{ invoice.number }}** ({{ invoice.balance }} outstanding) is due on {{ invoice.due_date | date }}.

[Pay now]({{ invoice.url }})
MD.$signOff),
    $client('invoice.overdue_first', 'invoice', 'First overdue notice', 'Invoice {{ invoice.number }} is overdue', <<<'MD'
Hi {{ client.first_name }},

Invoice **{{ invoice.number }}** was due on {{ invoice.due_date | date }} and {{ invoice.balance }} is still outstanding.

[Pay now]({{ invoice.url }})
MD.$signOff),
    $client('invoice.overdue_second', 'invoice', 'Second overdue notice', 'Second notice: invoice {{ invoice.number }}', <<<'MD'
Hi {{ client.first_name }},

Invoice **{{ invoice.number }}** is now {{ invoice.days_overdue }} days overdue ({{ invoice.balance }} outstanding). Please pay it to avoid interruption of your services.

[Pay now]({{ invoice.url }})
MD.$signOff),
    $client('invoice.overdue_third', 'invoice', 'Final overdue notice', 'Final notice: invoice {{ invoice.number }}', <<<'MD'
Hi {{ client.first_name }},

This is our final notice for invoice **{{ invoice.number }}**, {{ invoice.days_overdue }} days overdue with {{ invoice.balance }} outstanding. Related services may be suspended if it remains unpaid.

[Pay now]({{ invoice.url }})
MD.$signOff),
    $client('invoice.payment_confirmation', 'invoice', 'Payment confirmation', 'Payment received for invoice {{ invoice.number }}', <<<'MD'
Hi {{ client.first_name }},

Thank you — we received {{ payment.amount }} on {{ payment.date | date }} for invoice **{{ invoice.number }}** (reference {{ payment.reference | default('—') }}).

{% if invoice.balance_due %}A balance of {{ invoice.balance }} remains outstanding.{% else %}The invoice is now paid in full.{% endif %}

[View the invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.payment_failed', 'invoice', 'Payment failed', 'We could not collect payment for invoice {{ invoice.number }}', <<<'MD'
Hi {{ client.first_name }},

We tried to charge your saved payment method for invoice **{{ invoice.number }}** ({{ invoice.balance }}) but the payment didn't go through.

Reason: {{ payment.failure_reason | default('declined by the payment provider') }}

[Pay with another method]({{ invoice.url }})
MD.$signOff),
    $client('invoice.refunded', 'invoice', 'Refund issued', 'Refund issued for invoice {{ invoice.number }}', <<<'MD'
Hi {{ client.first_name }},

We've refunded {{ refund.amount }} for invoice **{{ invoice.number }}**. Depending on your bank, it can take a few days to appear.
MD.$signOff),
    $client('invoice.modified', 'invoice', 'Invoice updated', 'Invoice {{ invoice.number }} was updated', <<<'MD'
Hi {{ client.first_name }},

Invoice **{{ invoice.number }}** has been updated. The new total is {{ invoice.total }}, with {{ invoice.balance }} outstanding.

[View the invoice]({{ invoice.url }})
MD.$signOff),
    $client('payment_method.expiring', 'invoice', 'Payment method expiring', 'Your saved payment method expires soon', <<<'MD'
Hi {{ client.first_name }},

A payment method saved on your account expires soon. Please add a new one so renewals continue without interruption.

[Manage payment methods]({{ system.client_area_url }})
MD.$signOff),
    $client('credit_note.issued', 'invoice', 'Credit note issued', 'Credit note for invoice {{ invoice.number }}', <<<'MD'
Hi {{ client.first_name }},

We've issued a credit note against invoice **{{ invoice.number }}**. You can find it in the client area.
MD.$signOff),

    // ── Domain ───────────────────────────────────────────────────────────────────────────────────
    $client('domain.registration_confirmation', 'domain', 'Domain registered', '{{ domain.domain }} is registered', <<<'MD'
Hi {{ client.first_name }},

**{{ domain.domain }}** is now registered to you for {{ domain.period_years }} year(s). It expires on {{ domain.expiry_date | date }}.

Nameservers:

{% for nameserver in domain.nameservers %}- {{ nameserver }}
{% endfor %}
MD.$signOff),
    $client('domain.transfer_initiated', 'domain', 'Domain transfer started', 'Transfer of {{ domain.domain }} has started', <<<'MD'
Hi {{ client.first_name }},

We've started the transfer of **{{ domain.domain }}**. The current registrar may email you to approve it; transfers usually finish within five to seven days.
MD.$signOff),
    $client('domain.transfer_completed', 'domain', 'Domain transfer completed', '{{ domain.domain }} has been transferred', <<<'MD'
Hi {{ client.first_name }},

The transfer of **{{ domain.domain }}** is complete. It now renews with us and expires on {{ domain.expiry_date | date }}.
MD.$signOff),
    $client('domain.transfer_failed', 'domain', 'Domain transfer failed', 'Transfer of {{ domain.domain }} did not complete', <<<'MD'
Hi {{ client.first_name }},

The transfer of **{{ domain.domain }}** didn't complete.

Reason: {{ domain.transfer_failure_reason | default('rejected by the registry') }}

Check that the domain is unlocked and the authorisation code is correct, then reply to this email.
MD.$signOff),
    $client('domain.renewal_confirmation', 'domain', 'Domain renewed', '{{ domain.domain }} has been renewed', <<<'MD'
Hi {{ client.first_name }},

**{{ domain.domain }}** has been renewed. Its new expiry date is {{ domain.expiry_date | date }}.
MD.$signOff),
    $client('domain.renewal_upcoming', 'domain', 'Domain renewal reminder', 'Your domains are up for renewal', <<<'MD'
Hi {{ client.first_name }},

These domains expire soon:

{% for item in domains %}- **{{ item.name }}** — expires {{ item.expiry_date | date }} ({{ item.days_until_expiry }} days)
{% endfor %}
[Renew in the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('domain.expired_notice', 'domain', 'Domain expired', 'Your domains have expired', <<<'MD'
Hi {{ client.first_name }},

These domains have expired and may stop working:

{% for item in domains %}- **{{ item.name }}** — expired {{ item.expiry_date | date }}
{% endfor %}
Renew them as soon as possible to avoid losing them.

[Renew in the client area]({{ system.client_area_url }})
MD.$signOff),

    // ── Support (client) — subjects are fixed to the ticket thread format ───────────────────────
    $client('ticket.autoresponse', 'support', 'Ticket received', $supportSubject, <<<'MD'
Hi {{ recipient.first_name }},

Thanks for contacting {{ ticket.department }}. We've opened ticket **#{{ ticket.number }}** and will reply as soon as we can.

[View the ticket]({{ ticket.url }})
MD.$signOff),
    $client('ticket.opened_by_staff', 'support', 'Ticket opened by staff', $supportSubject, <<<'MD'
Hi {{ recipient.first_name }},

We've opened ticket **#{{ ticket.number }}** for you:

{{ reply.body_html | raw }}

[View and reply]({{ ticket.url }})
MD.$signOff),
    $client('ticket.reply', 'support', 'Ticket reply', $supportSubject, <<<'MD'
{{ reply.body_html | raw }}

---

{{ reply.author_name }} · {{ ticket.department }}

[View the ticket]({{ ticket.url }}) or reply to this email.
MD),
    $client('ticket.auto_closed', 'support', 'Ticket closed automatically', $supportSubject, <<<'MD'
Hi {{ recipient.first_name }},

Ticket **#{{ ticket.number }}** was closed because we haven't heard back for a while. Reply to this email if you still need help and it will reopen.
MD.$signOff),
    $client('ticket.closed_bounce', 'support', 'Reply to a closed ticket', 'Your email was not added to a ticket', <<<'MD'
Hello,

The ticket you replied to is closed, so your message wasn't added. Please open a new ticket from the client area.
MD.$signOff),
    $client('ticket.unregistered_bounce', 'support', 'Email from an unknown sender', 'Your email was not received', <<<'MD'
Hello,

We only accept support emails from registered addresses. Please sign in to the client area to open a ticket.
MD.$signOff),
    $client('ticket.replies_only_bounce', 'support', 'Department accepts replies only', 'Your email was not received', <<<'MD'
Hello,

This department only accepts replies to existing tickets by email. Please open a new ticket from the client area.
MD.$signOff),

    // ── Admin (staff) ────────────────────────────────────────────────────────────────────────────
    $staff('staff.order_new', 'New order', 'New order #{{ order.number }} from {{ client.full_name }}', <<<'MD'
A new order was placed by **{{ client.full_name }}** ({{ client.email }}).

{% for item in order.items %}- {{ item.description }} — {{ item.amount | money(order.currency) }}
{% endfor %}
Total: {{ order.total | money(order.currency) }} · Payment: {{ order.payment_method | default('—') }}

[Review the order]({{ order.admin_url }})
MD),
    $staff('staff.order_fraud', 'Order flagged as fraud', 'Order #{{ order.number }} was flagged as fraud', <<<'MD'
Order **#{{ order.number }}** from {{ client.full_name }} was flagged by the fraud check and is on hold.

[Review the order]({{ order.admin_url }})
MD),
    $staff('staff.ticket_new', 'New ticket', '[#{{ ticket.number }}] New ticket: {{ ticket.subject }}', <<<'MD'
**{{ ticket.requester_name }}** opened a ticket in {{ ticket.department }} (priority {{ ticket.priority }}).

{{ reply.body_html | raw }}

[Open the ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_client_reply', 'Client replied to a ticket', '[#{{ ticket.number }}] Client reply: {{ ticket.subject }}', <<<'MD'
**{{ reply.author_name }}** replied to ticket #{{ ticket.number }}.

{{ reply.body_html | raw }}

[Open the ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_assigned', 'Ticket assigned to you', '[#{{ ticket.number }}] Assigned to you: {{ ticket.subject }}', <<<'MD'
Hi {{ staff.first_name }}, ticket **#{{ ticket.number }}** from {{ ticket.requester_name }} is now assigned to you.

[Open the ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_mention', 'Mentioned in a ticket note', '[#{{ ticket.number }}] You were mentioned', <<<'MD'
Hi {{ staff.first_name }}, {{ reply.author_name }} mentioned you in a note on ticket #{{ ticket.number }}.

{{ reply.body_html | raw }}

[Open the ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_department_changed', 'Ticket moved to your department', '[#{{ ticket.number }}] Moved to {{ ticket.department }}', <<<'MD'
Ticket **#{{ ticket.number }}** ({{ ticket.subject }}) was moved to {{ ticket.department }} and is unassigned.

[Open the ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.provisioning_failed', 'Provisioning failed', 'Provisioning failed: {{ service.product_name }} ({{ failure.action }})', <<<'MD'
The **{{ failure.action }}** action failed for {{ service.product_name }}{% if service.domain %} ({{ service.domain }}){% endif %}, client {{ client.full_name }}.

Error: {{ failure.error }}

[Open the service]({{ service.admin_url }})
MD),
    $staff('staff.registrar_action_failed', 'Registrar action failed', 'Registrar action failed: {{ domain.domain }}', <<<'MD'
The registrar action **{{ failure.action }}** failed for {{ domain.domain }} (client {{ client.full_name }}).

Error: {{ failure.error }}
MD),
    $staff('staff.domain_sync_report', 'Domain sync report', 'Domain sync: {{ sync.change_count }} changes, {{ sync.error_count }} errors', <<<'MD'
The domain sync finished with {{ sync.change_count }} change(s) and {{ sync.error_count }} error(s).

{% for line in sync.lines %}- {{ line }}
{% endfor %}
MD),
    $staff('staff.free_domain_renewal_blocked', 'Free domain renewal blocked', 'Free renewal blocked: {{ domain.domain }}', <<<'MD'
The free renewal of **{{ domain.domain }}** for {{ client.full_name }} was blocked.

Reason: {{ failure.error }}
MD),
    $staff('staff.cancellation_request', 'Cancellation request', 'Cancellation request: {{ service.product_name }}', <<<'MD'
**{{ client.full_name }}** asked to cancel {{ service.product_name }}{% if service.domain %} ({{ service.domain }}){% endif %}.

Type: {{ cancellation.type }} · Reason: {{ cancellation.reason | default('none given') }}

[Open the service]({{ service.admin_url }})
MD),
    $staff('staff.affiliate_withdrawal_request', 'Affiliate withdrawal request', 'Affiliate withdrawal request: {{ affiliate.withdrawal_amount }}', <<<'MD'
An affiliate asked to withdraw **{{ affiliate.withdrawal_amount }}**. Review it in the affiliates area.
MD),
    $staff('staff.automation_summary', 'Daily automation summary', 'Automation run {{ automation.run_at | date }}: {{ automation.status }}', <<<'MD'
The daily automation for {{ automation.run_at | date }} finished with status **{{ automation.status }}**.

[Open the automation log]({{ system.admin_url }})
MD),
    $staff('staff.password_reset', 'Staff password reset', 'Reset your staff password', <<<'MD'
Hi {{ staff.first_name }},

Use this link to choose a new password for {{ staff.email }}:

[Choose a new password]({{ auth.reset_url }})

It expires at {{ auth.expires_at }}. If you didn't ask for this, tell an administrator.
MD),
    $staff('staff.invitation', 'Staff invitation', 'You are invited to the {{ company.name }} admin area', <<<'MD'
Hello,

You have been invited to join the {{ company.name }} team.

[Accept the invitation]({{ auth.invite_url }})

The invitation expires at {{ auth.expires_at }}.
MD),
    $staff('staff.login_failures', 'Repeated failed staff sign-ins', 'Repeated failed sign-ins for {{ security.email }}', <<<'MD'
There were {{ security.failures }} failed sign-in attempts for the staff account **{{ security.email }}**. The account may be under attack.
MD),
    $staff('staff.client_details_changed', 'Client changed their details', '{{ client.full_name }} updated their details', <<<'MD'
**{{ client.full_name }}** ({{ client.email }}) changed their account details.

[Open the client]({{ client.admin_url }})
MD),
    $staff('staff.webhook_endpoint_disabled', 'Webhook endpoint disabled', 'Webhook endpoint disabled after repeated failures', <<<'MD'
The webhook endpoint **{{ webhook.url }}** was disabled after {{ webhook.consecutive_failures }} consecutive failed deliveries.

Fix the receiver, then re-enable the endpoint.

[Open the endpoint]({{ webhook.admin_url }})
MD),
    $staff('staff.template_render_failed', 'Email template failed to render', 'Email template {{ template.key }} failed to render', <<<'MD'
An email using the template **{{ template.key }}** could not be sent because the template has an error:

{{ template.error }}

Messages using this template are marked failed until it is fixed.

[Edit the template]({{ template.admin_url }})
MD),
];
