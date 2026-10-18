<?php

/*
 * v1 seed catalog (spec §5). Keys and copy are ours. Bodies are Markdown with the sandboxed merge syntax
 * ({{ path }}, {% if %}, {% for %}), written to the branded layout's structure:
 *
 *   # Heading                      → email title
 *   One-line summary.              → lead line and inbox preheader
 *   | Label | value |              → key-facts block (one row per fact)
 *   [Verb-first action](url)       → the primary button (first link on its own line)
 *
 * Every path used here exists in SampleContext, so previews render without warnings. Support-type client
 * subjects are fixed by EmailComposer and only listed for completeness.
 *
 * @return list<array{key: string, type: string, audience: string, name: string, subject: string, body: string}>
 */

$client = static fn (string $key, string $type, string $name, string $subject, string $body): array => [
    'key' => $key, 'type' => $type, 'audience' => 'client', 'name' => $name, 'subject' => $subject, 'body' => $body,
];
$staff = static fn (string $key, string $name, string $subject, string $body): array => [
    'key' => $key, 'type' => 'admin', 'audience' => 'staff', 'name' => $name, 'subject' => $subject, 'body' => $body,
];

$signOff = "\n\n{% if signature %}{{ signature }}{% endif %}";
$supportSubject = '[#{{ ticket.number }}] {{ ticket.subject }}';

return [
    // ── General (client) ─────────────────────────────────────────────────────────────────────────
    $client('auth.password_reset', 'general', 'Password reset link', 'Reset your {{ company.name }} password', <<<'MD'
# Reset your password

Someone asked to reset the password for {{ user.email }}. If that was you, choose a new one below.

[Choose a new password]({{ auth.reset_url }})

The link works until {{ auth.expires_at }} and only once. If you didn't ask for this, ignore this email — your password stays the same.

{% if support.email %}If this wasn't you, contact {{ support.email }}.{% endif %}
MD),
    $client('auth.password_changed', 'general', 'Password changed', 'Your {{ company.name }} password was changed', <<<'MD'
# Your password was changed

The password for {{ user.email }} was changed on {{ system.today | date }}.

If this wasn't you, reset your password now{% if support.email %} and contact {{ support.email }}{% endif %}.

[Open the client area]({{ system.client_area_url }})
MD),
    $client('auth.email_verification', 'general', 'Verify email address', 'Confirm your {{ company.name }} email address', <<<'MD'
# Confirm your email address

Confirm that {{ user.email }} is yours so we can keep you informed about your account.

[Confirm email address]({{ auth.verify_url }})

If you didn't create an account with {{ company.name }}, you can ignore this email.

{% if support.email %}If this wasn't you, contact {{ support.email }}.{% endif %}
MD),
    $client('auth.user_invitation', 'general', 'Account invitation', 'You have been invited to manage {{ client.company_name | default(client.full_name) }}', <<<'MD'
# You're invited

You now have access to the {{ company.name }} account of {{ client.company_name | default(client.full_name) }}.

[Accept invitation]({{ auth.invite_url }})

The invitation expires at {{ auth.expires_at }}.
MD),
    $client('auth.new_login_alert', 'general', 'New sign-in alert', 'New sign-in to your {{ company.name }} account', <<<'MD'
# New sign-in to your account

We noticed a new sign-in to {{ user.email }}.

| IP address | {{ auth.ip }} |
|---|---|
| Date | {{ system.today | date }} |

If this wasn't you, change your password now{% if support.email %} and contact {{ support.email }}{% endif %}.

[Change password]({{ system.client_area_url }})
MD),
    $client('client.welcome', 'general', 'Welcome to the client area', 'Welcome to {{ company.name }}', <<<'MD'
# Welcome, {{ client.first_name }}

Your {{ company.name }} account is ready.

From the client area you can order services, pay invoices, manage domains and contact our team.

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('order.confirmation', 'general', 'Order confirmation', 'Order #{{ order.number }} from {{ company.name }}: {{ order.total | money(order.currency) }} received', <<<'MD'
# Thanks for your order

We received order #{{ order.number }} and will let you know as soon as everything is set up.

| Order | #{{ order.number }} |
|---|---|
{% for item in order.items %}| {{ item.description }} | {{ item.amount | money(order.currency) }} |
{% endfor %}| **Total** | **{{ order.total | money(order.currency) }}** |

[View your account]({{ system.client_area_url }})
MD.$signOff),
    $client('quote.delivered', 'general', 'Quote delivered', 'Quote {{ quote.number }} from {{ company.name }}: {{ quote.total }}, valid until {{ quote.valid_until | date }}', <<<'MD'
# Your quote is ready

We've prepared a quote for {{ quote.subject }}.

| Quote | {{ quote.number }} |
|---|---|
| Total | {{ quote.total }} |
| Valid until | {{ quote.valid_until | date }} |

[View quote]({{ quote.url }})
MD.$signOff),
    $client('affiliate.activated', 'general', 'Affiliate account activated', 'Your affiliate account is ready', <<<'MD'
# Your affiliate account is active

Share your referral link and earn commission on every qualifying order.

| Referral link | {{ affiliate.referral_url }} |
|---|---|

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('affiliate.withdrawal_processed', 'general', 'Affiliate withdrawal processed', 'Your affiliate withdrawal was processed', <<<'MD'
# Withdrawal processed

We've paid out your affiliate withdrawal.

| Amount | {{ affiliate.withdrawal_amount }} |
|---|---|
| Remaining balance | {{ affiliate.balance_available }} |
MD.$signOff),
    $client('affiliate.monthly_summary', 'general', 'Affiliate monthly summary', 'Your affiliate summary', <<<'MD'
# Your affiliate summary

Here's where your commission stands.

| Available | {{ affiliate.balance_available }} |
|---|---|
| Pending | {{ affiliate.balance_pending }} |
| Referral link | {{ affiliate.referral_url }} |
MD.$signOff),

    // ── Product ──────────────────────────────────────────────────────────────────────────────────
    $client('service.welcome.shared_hosting', 'product', 'Welcome: shared hosting', 'Your hosting for {{ service.domain | default(service.product_name) }} is ready', <<<'MD'
# Your hosting is ready

{{ service.product_name }} for {{ service.domain }} is active. Sign in to see your username and set a password.

| Domain | {{ service.domain }} |
|---|---|
| Username | {{ service.username }} |
| Server | {{ service.server_hostname }} ({{ service.server_ip }}) |
| Next renewal | {{ service.next_due_date | date }} |

Point your domain to these nameservers:

{% for nameserver in service.nameservers %}- {{ nameserver }}
{% endfor %}
[Open service]({{ service.url }})

For your security we never send passwords by email.
MD.$signOff),
    $client('service.welcome.reseller_hosting', 'product', 'Welcome: reseller hosting', 'Your reseller account is ready', <<<'MD'
# Your reseller account is ready

{{ service.product_name }} is active. Sign in to see your username and set a password.

| Username | {{ service.username }} |
|---|---|
| Server | {{ service.server_hostname }} ({{ service.server_ip }}) |

Nameservers for your customers:

{% for nameserver in service.nameservers %}- {{ nameserver }}
{% endfor %}
[Open service]({{ service.url }})

For your security we never send passwords by email.
MD.$signOff),
    $client('service.welcome.server', 'product', 'Welcome: server', 'Your server {{ service.domain | default(service.product_name) }} is ready', <<<'MD'
# Your server is online

{{ service.product_name }} is ready to use. Sign in to see your username and set a password.

| Hostname | {{ service.domain }} |
|---|---|
| Primary IP | {{ service.dedicated_ip | default(service.server_ip) }} |
| Username | {{ service.username }} |

[Open service]({{ service.url }})

For your security we never send passwords by email.
MD.$signOff),
    $client('service.welcome.other', 'product', 'Welcome: other products', 'Your {{ service.product_name }} is active', <<<'MD'
# {{ service.product_name }} is active

You can manage it from the client area at any time.

[Manage service]({{ service.url }})
MD.$signOff),
    $client('service.suspended', 'product', 'Service suspended', '{{ service.product_name }} has been suspended', <<<'MD'
# {{ service.product_name }} is suspended

We've suspended this service. Settle any open invoices, or reply to this email and we'll help you get it running again.

| Service | {{ service.product_name }} |
|---|---|
{% if service.domain %}| Domain | {{ service.domain }} |
{% endif %}| Reason | {{ service.suspension_reason | default('Not specified') }} |

[Review your invoices]({{ system.client_area_url }})
MD.$signOff),
    $client('service.unsuspended', 'product', 'Service reactivated', '{{ service.product_name }} is active again', <<<'MD'
# {{ service.product_name }} is active again

Your service{% if service.domain %} for {{ service.domain }}{% endif %} is running normally. Thanks for sorting it out.

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('service.cancellation_requested', 'product', 'Cancellation request received', 'We received your cancellation request', <<<'MD'
# Cancellation request received

We'll cancel {{ service.product_name }} as requested. Changed your mind? Contact us before the cancellation date.

| Service | {{ service.product_name }} |
|---|---|
{% if service.domain %}| Domain | {{ service.domain }} |
{% endif %}| When | {{ cancellation.type }} |

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('service.upgrade_completed', 'product', 'Upgrade completed', 'Your upgrade is complete', <<<'MD'
# Your upgrade is complete

Your service now runs on {{ service.product_name }}.

| Plan | {{ service.product_name }} |
|---|---|
| Renews at | {{ service.recurring_amount | money(service.currency) }} |

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('service.terminated', 'product', 'Service terminated', '{{ service.product_name }} has been removed', <<<'MD'
# {{ service.product_name }} was removed

This service{% if service.domain %} for {{ service.domain }}{% endif %} has been terminated and its data deleted.

If you think this is a mistake, reply to this email.
MD.$signOff),

    // ── Invoice ──────────────────────────────────────────────────────────────────────────────────
    $client('invoice.created', 'invoice', 'Invoice created', 'Invoice {{ invoice.number }} from {{ company.name }}: {{ invoice.balance }} due {{ invoice.due_date | date }}', <<<'MD'
# Invoice {{ invoice.number }}

Your new invoice is ready. Payment is due by {{ invoice.due_date | date }}.

| Invoice | {{ invoice.number }} |
|---|---|
| Amount due | {{ invoice.balance }} |
| Due date | {{ invoice.due_date | date }} |

[View and pay invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.created_autocharge', 'invoice', 'Invoice created (automatic payment)', 'Invoice {{ invoice.number }} from {{ company.name }}: {{ invoice.total }} charged {{ invoice.due_date | date }}', <<<'MD'
# Invoice {{ invoice.number }}

We'll charge your saved payment method on {{ invoice.due_date | date }}. No action needed.

| Invoice | {{ invoice.number }} |
|---|---|
| Amount | {{ invoice.total }} |
| Charge date | {{ invoice.due_date | date }} |

[View invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.payment_reminder', 'invoice', 'Payment reminder', 'Invoice {{ invoice.number }} from {{ company.name }}: {{ invoice.balance }} due {{ invoice.due_date | date }}', <<<'MD'
# Payment due soon

A friendly reminder that invoice {{ invoice.number }} is due on {{ invoice.due_date | date }}.

| Invoice | {{ invoice.number }} |
|---|---|
| Amount due | {{ invoice.balance }} |
| Due date | {{ invoice.due_date | date }} |

[Pay invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.overdue_first', 'invoice', 'First overdue notice', 'Payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})', <<<'MD'
# Invoice {{ invoice.number }} is overdue

The due date has passed and a balance is still open. Please pay it when you can.

| Invoice | {{ invoice.number }} |
|---|---|
| Amount due | {{ invoice.balance }} |
| Was due | {{ invoice.due_date | date }} |

[Pay invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.overdue_second', 'invoice', 'Second overdue notice', 'Second notice — payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})', <<<'MD'
# Second notice: invoice {{ invoice.number }}

This invoice is {{ invoice.days_overdue }} days overdue. Pay it now to avoid interruption to your services.

| Invoice | {{ invoice.number }} |
|---|---|
| Amount due | {{ invoice.balance }} |
| Days overdue | {{ invoice.days_overdue }} |

[Pay invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.overdue_third', 'invoice', 'Final overdue notice', 'Final notice — payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})', <<<'MD'
# Final notice: invoice {{ invoice.number }}

Related services may be suspended if this invoice stays unpaid.

| Invoice | {{ invoice.number }} |
|---|---|
| Amount due | {{ invoice.balance }} |
| Days overdue | {{ invoice.days_overdue }} |

[Pay invoice]({{ invoice.url }})

Already paid? Reply to this email and we'll check.
MD.$signOff),
    $client('invoice.payment_confirmation', 'invoice', 'Payment confirmation', 'Your receipt from {{ company.name }} #{{ payment.reference | default(invoice.number) }}', <<<'MD'
# Payment received

Thanks — we received {{ payment.amount }} for invoice {{ invoice.number }}.

| Amount paid | {{ payment.amount }} |
|---|---|
| Invoice | {{ invoice.number }} |
| Date | {{ payment.date | date }} |
| Reference | {{ payment.reference | default('—') }} |
{% if invoice.balance_due %}| Still due | {{ invoice.balance }} |
{% endif %}
[View receipt]({{ invoice.url }})
MD.$signOff),
    $client('invoice.payment_failed', 'invoice', 'Payment failed', 'Payment failed: invoice {{ invoice.number }} ({{ invoice.balance }}) from {{ company.name }}', <<<'MD'
# Payment didn't go through

We couldn't charge your saved payment method for invoice {{ invoice.number }}. Pay with another method to keep your services running.

| Invoice | {{ invoice.number }} |
|---|---|
| Amount due | {{ invoice.balance }} |
| Reason | {{ payment.failure_reason | default('Declined by the payment provider') }} |

[Pay with another method]({{ invoice.url }})
MD.$signOff),
    $client('invoice.refunded', 'invoice', 'Refund issued', 'Your refund from {{ company.name }}: {{ refund.amount }} for invoice {{ invoice.number }}', <<<'MD'
# Refund issued

We've refunded part or all of invoice {{ invoice.number }}. Depending on your bank, it can take a few days to appear.

| Refund | {{ refund.amount }} |
|---|---|
| Invoice | {{ invoice.number }} |

[View invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.modified', 'invoice', 'Invoice updated', 'Invoice {{ invoice.number }} from {{ company.name }} updated: {{ invoice.balance }} due', <<<'MD'
# Invoice {{ invoice.number }} was updated

We changed this invoice. Here are the new amounts.

| New total | {{ invoice.total }} |
|---|---|
| Amount due | {{ invoice.balance }} |

[View invoice]({{ invoice.url }})
MD.$signOff),
    $client('payment_method.expiring', 'invoice', 'Payment method expiring', 'Your {{ company.name }} payment method expires soon', <<<'MD'
# Your payment method expires soon

Add a new one so renewals continue without interruption.

[Update payment method]({{ system.client_area_url }})
MD.$signOff),
    $client('credit_note.issued', 'invoice', 'Credit note issued', 'Credit note from {{ company.name }} for invoice {{ invoice.number }}', <<<'MD'
# Credit note issued

We've issued a credit note against invoice {{ invoice.number }}.

[View invoice]({{ invoice.url }})
MD.$signOff),

    // ── Domain ───────────────────────────────────────────────────────────────────────────────────
    $client('domain.registration_confirmation', 'domain', 'Domain registered', '{{ domain.domain }} is registered', <<<'MD'
# {{ domain.domain }} is yours

The domain is registered and ready to use.

| Domain | {{ domain.domain }} |
|---|---|
| Period | {{ domain.period_years }} year(s) |
| Expires | {{ domain.expiry_date | date }} |

Nameservers:

{% for nameserver in domain.nameservers %}- {{ nameserver }}
{% endfor %}
[Manage domain]({{ domain.renew_url }})
MD.$signOff),
    $client('domain.transfer_initiated', 'domain', 'Domain transfer started', 'Transfer of {{ domain.domain }} has started', <<<'MD'
# Transfer of {{ domain.domain }} started

Your current registrar may email you to approve it. Transfers usually finish within five to seven days.

[Track transfer]({{ domain.renew_url }})
MD.$signOff),
    $client('domain.transfer_completed', 'domain', 'Domain transfer completed', '{{ domain.domain }} has been transferred', <<<'MD'
# {{ domain.domain }} transferred

The transfer is complete. The domain now renews with us.

| Domain | {{ domain.domain }} |
|---|---|
| Expires | {{ domain.expiry_date | date }} |

[Manage domain]({{ domain.renew_url }})
MD.$signOff),
    $client('domain.transfer_failed', 'domain', 'Domain transfer failed', 'Transfer of {{ domain.domain }} did not complete', <<<'MD'
# Transfer of {{ domain.domain }} didn't complete

Check that the domain is unlocked and the authorisation code is correct, then try again.

| Domain | {{ domain.domain }} |
|---|---|
| Reason | {{ domain.transfer_failure_reason | default('Rejected by the registry') }} |

[Retry transfer]({{ domain.renew_url }})
MD.$signOff),
    $client('domain.renewal_confirmation', 'domain', 'Domain renewed', '{{ domain.domain }} has been renewed', <<<'MD'
# {{ domain.domain }} renewed

Thanks — the domain is renewed.

| Domain | {{ domain.domain }} |
|---|---|
| New expiry date | {{ domain.expiry_date | date }} |

[Manage domain]({{ domain.renew_url }})
MD.$signOff),
    $client('domain.renewal_upcoming', 'domain', 'Domain renewal reminder', 'Your domains are up for renewal', <<<'MD'
# Domains expiring soon

Renew them to keep your websites and email working.

| Domain | Expires |
|---|---|
{% for item in domains %}| {{ item.name }} | {{ item.expiry_date | date }} ({{ item.days_until_expiry }} days) |
{% endfor %}
[Renew domains]({{ system.client_area_url }})
MD.$signOff),
    $client('domain.expired_notice', 'domain', 'Domain expired', 'Your domains have expired', <<<'MD'
# Domains expired

These domains have expired and may stop working. Renew them soon to avoid losing them.

| Domain | Expired |
|---|---|
{% for item in domains %}| {{ item.name }} | {{ item.expiry_date | date }} |
{% endfor %}
[Renew domains]({{ system.client_area_url }})
MD.$signOff),

    // ── Support (client) — subjects are fixed to the ticket thread format ───────────────────────
    $client('ticket.autoresponse', 'support', 'Ticket received', $supportSubject, <<<'MD'
# We got your request

Ticket #{{ ticket.number }} is open with {{ ticket.department }}. We'll reply as soon as we can.

| Ticket | #{{ ticket.number }} |
|---|---|
| Subject | {{ ticket.subject }} |
| Priority | {{ ticket.priority }} |

[View ticket]({{ ticket.url }})
MD.$signOff),
    $client('ticket.opened_by_staff', 'support', 'Ticket opened by staff', $supportSubject, <<<'MD'
# We opened a ticket for you

{{ ticket.department }} opened ticket #{{ ticket.number }}: {{ ticket.subject }}.

{{ reply.body_html | raw }}

[View and reply]({{ ticket.url }})
MD.$signOff),
    $client('ticket.reply', 'support', 'Ticket reply', $supportSubject, <<<'MD'
# New reply on ticket #{{ ticket.number }}

{{ reply.author_name }} from {{ ticket.department }} replied to "{{ ticket.subject }}".

{{ reply.body_html | raw }}

[View ticket]({{ ticket.url }})

You can also reply to this email.
MD),
    $client('ticket.auto_closed', 'support', 'Ticket closed automatically', $supportSubject, <<<'MD'
# Ticket #{{ ticket.number }} closed

We closed this ticket because we haven't heard back for a while. Reply to this email if you still need help and it will reopen.

[View ticket]({{ ticket.url }})
MD.$signOff),
    $client('ticket.closed_bounce', 'support', 'Reply to a closed ticket', 'Your email was not added to a ticket', <<<'MD'
# Your email wasn't added

The ticket you replied to is closed, so your message wasn't added. Please open a new ticket.

[Open a new ticket]({{ system.client_area_url }})
MD.$signOff),
    $client('ticket.unregistered_bounce', 'support', 'Email from an unknown sender', 'Your email was not received', <<<'MD'
# We couldn't accept your email

We only accept support email from registered addresses. Sign in to open a ticket.

[Sign in]({{ system.client_area_url }})
MD.$signOff),
    $client('ticket.replies_only_bounce', 'support', 'Department accepts replies only', 'Your email was not received', <<<'MD'
# We couldn't accept your email

This department only accepts email replies to existing tickets. Please open a new ticket in the client area.

[Open a new ticket]({{ system.client_area_url }})
MD.$signOff),

    // ── Admin (staff) ────────────────────────────────────────────────────────────────────────────
    $staff('staff.order_new', 'New order', 'New order #{{ order.number }} from {{ client.full_name }}', <<<'MD'
# New order #{{ order.number }}

{{ client.full_name }} ({{ client.email }}) placed an order.

| Order | #{{ order.number }} |
|---|---|
{% for item in order.items %}| {{ item.description }} | {{ item.amount | money(order.currency) }} |
{% endfor %}| Payment | {{ order.payment_method | default('—') }} |
| **Total** | **{{ order.total | money(order.currency) }}** |

[Review order]({{ order.admin_url }})
MD),
    $staff('staff.order_fraud', 'Order flagged as fraud', 'Order #{{ order.number }} was flagged as fraud', <<<'MD'
# Order #{{ order.number }} flagged as fraud

The fraud check put this order from {{ client.full_name }} on hold.

[Review order]({{ order.admin_url }})
MD),
    $staff('staff.ticket_new', 'New ticket', '[#{{ ticket.number }}] New ticket: {{ ticket.subject }}', <<<'MD'
# New ticket #{{ ticket.number }}

{{ ticket.requester_name }} opened "{{ ticket.subject }}" in {{ ticket.department }}.

| Priority | {{ ticket.priority }} |
|---|---|
| Department | {{ ticket.department }} |

{{ reply.body_html | raw }}

[Open ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_client_reply', 'Client replied to a ticket', '[#{{ ticket.number }}] Client reply: {{ ticket.subject }}', <<<'MD'
# Client reply on #{{ ticket.number }}

{{ reply.author_name }} replied to "{{ ticket.subject }}".

{{ reply.body_html | raw }}

[Open ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_assigned', 'Ticket assigned to you', '[#{{ ticket.number }}] Assigned to you: {{ ticket.subject }}', <<<'MD'
# Ticket #{{ ticket.number }} is yours

{{ ticket.requester_name }}'s ticket "{{ ticket.subject }}" is now assigned to you.

[Open ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_mention', 'Mentioned in a ticket note', '[#{{ ticket.number }}] You were mentioned', <<<'MD'
# You were mentioned

{{ reply.author_name }} mentioned you in a note on ticket #{{ ticket.number }}.

{{ reply.body_html | raw }}

[Open ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_department_changed', 'Ticket moved to your department', '[#{{ ticket.number }}] Moved to {{ ticket.department }}', <<<'MD'
# Ticket moved to {{ ticket.department }}

Ticket #{{ ticket.number }} ("{{ ticket.subject }}") is now in your department and unassigned.

[Open ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.provisioning_failed', 'Provisioning failed', 'Provisioning failed: {{ service.product_name }} ({{ failure.action }})', <<<'MD'
# Provisioning failed

The {{ failure.action }} action failed for {{ client.full_name }}'s {{ service.product_name }}.

| Service | {{ service.product_name }} |
|---|---|
| Domain | {{ service.domain | default('—') }} |
| Error | {{ failure.error }} |

[Open service]({{ service.admin_url }})
MD),
    $staff('staff.registrar_action_failed', 'Registrar action failed', 'Registrar action failed: {{ domain.domain }}', <<<'MD'
# Registrar action failed

The {{ failure.action }} action failed for {{ domain.domain }} ({{ client.full_name }}).

| Domain | {{ domain.domain }} |
|---|---|
| Error | {{ failure.error }} |
MD),
    $staff('staff.domain_sync_report', 'Domain sync report', 'Domain sync: {{ sync.change_count }} changes, {{ sync.error_count }} errors', <<<'MD'
# Domain sync report

The registrar sync found {{ sync.change_count }} change(s) and {{ sync.error_count }} error(s).

{% for line in sync.lines %}- {{ line }}
{% endfor %}
MD),
    $staff('staff.free_domain_renewal_blocked', 'Free domain renewal blocked', 'Free renewal blocked: {{ domain.domain }}', <<<'MD'
# Free renewal blocked

The free renewal of {{ domain.domain }} for {{ client.full_name }} didn't go ahead.

| Domain | {{ domain.domain }} |
|---|---|
| Reason | {{ failure.error }} |
MD),
    $staff('staff.cancellation_request', 'Cancellation request', 'Cancellation request: {{ service.product_name }}', <<<'MD'
# Cancellation request

{{ client.full_name }} asked to cancel {{ service.product_name }}.

| Service | {{ service.product_name }} |
|---|---|
| Domain | {{ service.domain | default('—') }} |
| When | {{ cancellation.type }} |
| Reason | {{ cancellation.reason | default('None given') }} |

[Open service]({{ service.admin_url }})
MD),
    $staff('staff.affiliate_withdrawal_request', 'Affiliate withdrawal request', 'Affiliate withdrawal request: {{ affiliate.withdrawal_amount }}', <<<'MD'
# Affiliate withdrawal request

An affiliate asked to withdraw {{ affiliate.withdrawal_amount }}. Review it in the affiliates area.
MD),
    $staff('staff.automation_summary', 'Daily automation summary', 'Automation run {{ automation.run_at | date }}: {{ automation.status }}', <<<'MD'
# Daily automation {{ automation.status }}

The run for {{ automation.run_at | date }} finished.

| Run date | {{ automation.run_at | date }} |
|---|---|
| Status | {{ automation.status }} |

[Open admin area]({{ system.admin_url }})
MD),
    $staff('staff.password_reset', 'Staff password reset', 'Reset your staff password', <<<'MD'
# Reset your staff password

Use the button below to choose a new password for {{ staff.email }}.

[Choose a new password]({{ auth.reset_url }})

The link expires at {{ auth.expires_at }}.

{% if support.email %}If this wasn't you, contact {{ support.email }}.{% endif %}
MD),
    $staff('staff.invitation', 'Staff invitation', 'You are invited to the {{ company.name }} admin area', <<<'MD'
# Join the {{ company.name }} team

You've been invited to the admin area.

[Accept invitation]({{ auth.invite_url }})

The invitation expires at {{ auth.expires_at }}.
MD),
    $staff('staff.login_failures', 'Repeated failed staff sign-ins', 'Repeated failed sign-ins for {{ security.email }}', <<<'MD'
# Repeated failed sign-ins

The staff account {{ security.email }} had {{ security.failures }} failed sign-in attempts. It may be under attack.

[Open admin area]({{ system.admin_url }})
MD),
    $staff('staff.client_details_changed', 'Client changed their details', '{{ client.full_name }} updated their details', <<<'MD'
# Client details changed

{{ client.full_name }} ({{ client.email }}) changed their account details.

[Open client]({{ client.admin_url }})
MD),
    $staff('staff.webhook_endpoint_disabled', 'Webhook endpoint disabled', 'Webhook endpoint disabled after repeated failures', <<<'MD'
# Webhook endpoint disabled

We stopped sending events to this endpoint after {{ webhook.consecutive_failures }} failed deliveries in a row. Fix the receiver, then turn the endpoint back on.

| Endpoint | {{ webhook.url }} |
|---|---|
| Failures | {{ webhook.consecutive_failures }} |

[Open endpoint]({{ webhook.admin_url }})
MD),
    $staff('staff.template_render_failed', 'Email template failed to render', 'Email template {{ template.key }} failed to render', <<<'MD'
# Email template error

Emails using {{ template.key }} are failing until the template is fixed.

| Template | {{ template.key }} |
|---|---|
| Error | {{ template.error }} |

[Fix template]({{ template.admin_url }})
MD),
];
