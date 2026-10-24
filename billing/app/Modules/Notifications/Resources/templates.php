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
 * @return list<array{key: string, type: string, audience: string, name: string, subject: string, body: string, description?: string|null, preheader?: string|null, heading?: string, action_label?: string}>
 */

// Client mail greets the reader by first name after the title (design §3 block 4).
$greet = static fn (string $body): string => (string) preg_replace('/^(# [^\n]*\n\n)/', "\$1{% if recipient.first_name %}Hi {{ recipient.first_name }},{% else %}Hello,{% endif %}\n\n", $body, 1);
$client = static fn (string $key, string $type, string $name, string $subject, string $body): array => [
    'key' => $key, 'type' => $type, 'audience' => 'client', 'name' => $name, 'subject' => $subject, 'body' => $greet($body),
];
// Client ticket mail: the system adds the "View your ticket" button (SecretTemplatePolicy::SYSTEM_ACTIONS); the
// ticket link is never in the text, because a guest's link carries the ticket's access key.
$ticket = static fn (string $key, string $name, string $subject, string $body, string $actionLabel): array => [
    // A reply carries the agent's own greeting, so it gets none of ours.
    'key' => $key, 'type' => 'support', 'audience' => 'client', 'name' => $name, 'subject' => $subject, 'body' => $key === 'ticket.reply' ? $body : $greet($body),
    'action_label' => $actionLabel,
];
$staff = static fn (string $key, string $name, string $subject, string $body): array => [
    'key' => $key, 'type' => 'admin', 'audience' => 'staff', 'name' => $name, 'subject' => $subject, 'body' => $body,
];

/*
 * Secret-bearing system templates are structure-only (security review B1): a plain-text heading, body paragraphs
 * and button label. The system renders the button and the text-part link from the one-time link.
 */
$secret = static fn (string $key, string $audience, string $type, string $name, string $subject, string $heading, string $actionLabel, string $body): array => [
    'key' => $key, 'type' => $type, 'audience' => $audience, 'name' => $name, 'subject' => $subject,
    'heading' => $heading, 'action_label' => $actionLabel, 'body' => $body,
];

$signOff = "\n\n{% if signature %}{{ signature }}{% endif %}";
$supportSubject = '[#{{ ticket.number }}] {{ ticket.subject }}';

$templates = [
    // ── General (client) ─────────────────────────────────────────────────────────────────────────
    $secret('auth.password_reset', 'client', 'general', 'Password reset link', 'Reset your {{ company.name }} password', 'Reset your password', 'Choose a new password', <<<'MD'
Hello {{ user.first_name }}, someone asked to reset the password for your {{ company.name }} account. If that was you, choose a new one with the button below.

The link works until {{ auth.expires_at }} and only once. If you didn't ask for this, ignore this email and your password stays the same.

If this wasn't you, contact {{ support.email }}.
MD),
    $secret('auth.password_set', 'client', 'general', 'Set a password link', 'Set a password for your {{ company.name }} account', 'Set a password', 'Set a password', <<<'MD'
Hello {{ user.first_name }}, you asked to set a password for your {{ company.name }} account. Use the button below; sign in first if you are asked to.

The link works until {{ auth.expires_at }} and only once. If you didn't ask for this, ignore this email and nothing changes.

If this wasn't you, contact {{ support.email }}.
MD),
    $client('auth.password_changed', 'general', 'Password changed', 'Your {{ company.name }} password was changed', <<<'MD'
# Your password was changed

The password for {{ user.email }} was changed on {{ system.today | date }}.

If this wasn't you, reset your password now{% if support.email %} and contact {{ support.email }}{% endif %}.

[Open the client area]({{ system.client_area_url }})
MD),
    $secret('auth.email_verification', 'client', 'general', 'Verify email address', 'Confirm your {{ company.name }} email address', 'Confirm your email address', 'Confirm email address', <<<'MD'
Hello {{ user.first_name }}, confirm that this email address is yours so we can keep you informed about your account.

If you didn't create an account with {{ company.name }}, you can ignore this email. If this wasn't you, contact {{ support.email }}.
MD),
    $secret('auth.user_invitation', 'client', 'general', 'Account invitation', 'You\'re invited to a {{ company.name }} client account', 'You\'re invited', 'Accept invitation', <<<'MD'
Hello {{ user.first_name }}, you've been given access to a {{ company.name }} client account. Accept the invitation to choose your password and log in.

This invitation works once, for {{ auth.expires_in }}: until {{ auth.expires_at }}. If it runs out, ask the account owner or our team to send a new one.

If you weren't expecting this, you can ignore this email. If this wasn't you, contact {{ support.email }}.
MD),
    $secret('client.migration_invitation', 'client', 'general', 'Invitation after a client import', 'Set your password for the new {{ company.name }} client area', 'Set your password', 'Set your password', <<<'MD'
Hello {{ user.first_name }}, {{ company.name }} has moved to a new client area, and your account is already in it. Set a password to sign in; your old password does not work here.

This link works once, for {{ auth.expires_in }}: until {{ auth.expires_at }}. If it runs out, contact {{ support.email }} for a new one.

If you weren't expecting this, you can ignore this email. If this wasn't you, contact {{ support.email }}.
MD),
    $client('auth.email_changed', 'general', 'Sign-in email changed', 'The sign-in email for your {{ company.name }} account was changed', <<<'MD'
# Your sign-in email was changed

The sign-in email for your account is now {{ notice.new_email }}. This address no longer receives password resets.

| From | {{ notice.old_email }} |
|---|---|
| To | {{ notice.new_email }} |
| Date | {{ system.today | date }} |

If you didn't make this change, contact us straight away{% if support.email %} at {{ support.email }}{% endif %} so we can secure your account.

[Open the client area]({{ system.client_area_url }})
MD),
    $client('auth.new_login_alert', 'general', 'New login alert', 'New login to your {{ company.name }} account', <<<'MD'
# New login to your account

We noticed a new login to {{ user.email }}.

| IP address | {{ auth.ip }} |
|---|---|
| Date | {{ system.today | date }} |

If this wasn't you, change your password now{% if support.email %} and contact {{ support.email }}{% endif %}.

[Change password]({{ system.client_area_url }})
MD),
    $client('auth.login_added', 'general', 'Login added to your account', 'A login was added to your {{ company.name }} account', <<<'MD'
# A login was added to your account

{{ notice.email }} can now log in to the {{ notice.account }} account.

| Account | {{ notice.account }} |
|---|---|
| New login | {{ notice.email }} |
| Date | {{ system.today | date }} |

If you didn't expect this, remove the login from your account and contact us{% if support.email %} at {{ support.email }}{% endif %}.

[Review account logins]({{ system.client_area_url }})
MD),
    $client('auth.access_granted', 'general', 'Access to a client account', 'You now have access to {{ notice.account }}', <<<'MD'
# You have access to {{ notice.account }}

Our team gave your login access to the {{ notice.account }} account. Log in as usual to see it.

[Open the client area]({{ system.client_area_url }})

If you weren't expecting this, contact us{% if support.email %} at {{ support.email }}{% endif %}.
MD),
    $client('auth.owner_changed', 'general', 'Account owner changed', '{{ notice.account }} has a new owner', <<<'MD'
# {{ notice.account }} has a new owner

Ownership of the {{ notice.account }} account was transferred to {{ notice.email }}.

| Account | {{ notice.account }} |
|---|---|
| New owner | {{ notice.email }} |
| Date | {{ system.today | date }} |

If you didn't expect this, contact us{% if support.email %} at {{ support.email }}{% endif %} straight away.
MD),
    $client('auth.two_factor_reset', 'general', 'Two-factor authentication reset', 'Two-factor authentication was turned off on your {{ company.name }} account', <<<'MD'
# Two-factor authentication was reset

Our team turned off two-factor authentication on your account, for example after a lost phone. Set it up again from your security settings.

[Open security settings]({{ system.client_area_url }})

If you didn't ask for this, contact us{% if support.email %} at {{ support.email }}{% endif %} straight away.
MD),
    $client('client.welcome', 'general', 'Welcome to the client area', 'Welcome to {{ company.name }}', <<<'MD'
# Welcome, {{ client.first_name }}

Your {{ company.name }} account is ready.

From the client area you can order services, pay invoices, manage domains and contact our team.

[Open the client area]({{ system.client_area_url }})
MD.$signOff),
    $client('kyc.document_decided', 'general', 'Identity document decision', '{% if kyc.approved %}Your {{ kyc.document }} is approved{% else %}Your {{ kyc.document }} was declined{% endif %}', <<<'MD'
# {% if kyc.approved %}{{ kyc.document }} approved{% else %}{{ kyc.document }} declined{% endif %}

{% if kyc.approved %}{% if kyc.verified %}Thank you. Your identity is now verified, and there is nothing more to do.{% else %}Thank you. We checked your {{ kyc.document }} and approved it. The verification page shows what is left.{% endif %}{% else %}We could not accept your {{ kyc.document }}: {{ kyc.reason }} Please upload a new photo when you can. Your account keeps working as usual.{% endif %}

{% if kyc.approved %}[View your verification]({{ kyc.url }}){% else %}[Upload a new photo]({{ kyc.url }}){% endif %}

| Document | {{ kyc.document }} |
|---|---|
| Decision | {% if kyc.approved %}Approved{% else %}Declined{% endif %} |

Questions? Reply to this email and our team will help.
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

{{ service.product_name }} for {{ service.domain }} is active. Log in to see your username and set a password.

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

{{ service.product_name }} is active. Log in to see your username and set a password.

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

{{ service.product_name }} is ready to use. Log in to see your username and set a password.

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
    $client('service.suspended', 'product', 'Service suspended', '{{ service.product_name }} is paused', <<<'MD'
# {{ service.product_name }} is paused

{% if invoice.number %}Your invoice {{ invoice.number }} for {{ invoice.balance }} is overdue, so we've paused {{ service.product_name }}{% if service.domain %} for {{ service.domain }}{% endif %}. Pay it and the service starts again automatically.{% else %}We've paused {{ service.product_name }}{% if service.domain %} for {{ service.domain }}{% endif %}. Reply to this email and we'll help you get it running again.{% endif %}

{% if invoice.url %}[Pay {{ invoice.balance }}]({{ invoice.url }}){% else %}[Open the client area]({{ system.client_area_url }}){% endif %}

| Service | {{ service.product_name }} |
|---|---|
{% if service.domain %}| Domain | {{ service.domain }} |
{% endif %}{% if invoice.number %}| Overdue invoice | {{ invoice.number }} |
| Was due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |
{% endif %}

Your files and data are kept safe while the service is paused.
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

Here's your new invoice. Please pay it by {{ invoice.due_date | date }} to keep your services running.

[Pay {{ invoice.balance }}]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| Issued | {{ invoice.issue_date | date }}{% if invoice.issue_date_bs %} ({{ invoice.issue_date_bs }}){% endif %} |
| Due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |

Need a different billing address or a purchase order number on this invoice? Reply and we'll reissue it.
MD.$signOff),
    $client('invoice.created_autocharge', 'invoice', 'Invoice created (automatic payment)', 'Invoice {{ invoice.number }} from {{ company.name }}: {{ invoice.total }} charged {{ invoice.due_date | date }}', <<<'MD'
# Invoice {{ invoice.number }}

We'll charge your saved payment method on {{ invoice.due_date | date }}. There's nothing you need to do.

[View invoice]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| Issued | {{ invoice.issue_date | date }}{% if invoice.issue_date_bs %} ({{ invoice.issue_date_bs }}){% endif %} |
| Charge date | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |
MD.$signOff),
    $client('invoice.payment_reminder', 'invoice', 'Payment reminder', 'Reminder: invoice {{ invoice.number }} ({{ invoice.balance }}) is due {{ invoice.due_date | date }}', <<<'MD'
# Payment due soon

A friendly reminder that invoice {{ invoice.number }} is due on {{ invoice.due_date | date }}. Paying online takes a minute.

[Pay {{ invoice.balance }}]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| Issued | {{ invoice.issue_date | date }}{% if invoice.issue_date_bs %} ({{ invoice.issue_date_bs }}){% endif %} |
| Due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |

Already paid? Reply to this email and we'll check.
MD.$signOff),
    $client('invoice.overdue_first', 'invoice', 'First overdue notice', 'Payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})', <<<'MD'
# Invoice {{ invoice.number }} is overdue

We haven't received payment for invoice {{ invoice.number }}, which was due on {{ invoice.due_date | date }}. Please pay it soon so your services keep running.

[Pay {{ invoice.balance }}]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| Issued | {{ invoice.issue_date | date }}{% if invoice.issue_date_bs %} ({{ invoice.issue_date_bs }}){% endif %} |
| Was due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |

Already paid? Bank transfers can take up to two working days to reach us. Reply with your payment reference and we'll check.

Need more time, or having trouble paying? Reply to this email and we'll work it out with you.
MD.$signOff),
    $client('invoice.overdue_second', 'invoice', 'Second overdue notice', 'Second reminder, payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})', <<<'MD'
# Second notice: invoice {{ invoice.number }}

Invoice {{ invoice.number }} is now {{ invoice.days_overdue }} days overdue. Please pay it to avoid an interruption to your services.

[Pay {{ invoice.balance }}]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| Was due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |
| Days overdue | {{ invoice.days_overdue }} |

Need more time? Reply to this email and we'll work it out with you.
MD.$signOff),
    $client('invoice.overdue_third', 'invoice', 'Final overdue notice', 'Final reminder, payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})', <<<'MD'
# Final notice: invoice {{ invoice.number }}

Invoice {{ invoice.number }} is {{ invoice.days_overdue }} days overdue. If it stays unpaid, we'll pause the services on it. Your files and data stay safe.

[Pay {{ invoice.balance }}]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| Was due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |
| Days overdue | {{ invoice.days_overdue }} |

Already paid? Reply to this email and we'll check.
MD.$signOff),
    $client('invoice.payment_confirmation', 'invoice', 'Payment confirmation', 'Your receipt from {{ company.name }} #{{ payment.reference | default(invoice.number) }}', <<<'MD'
# Payment received

Thank you. We've received your payment of {{ payment.amount }}{% if invoice.balance_due %}, and {{ invoice.balance }} is still due on invoice {{ invoice.number }}{% else %}, and invoice {{ invoice.number }} is paid in full{% endif %}.

| Invoice | {{ invoice.number }} |
|---|---|
| Date | {{ payment.date | date }} |
| Reference | {{ payment.reference | default('—') }} |

Something not right? Reply to this email and our billing team will look into it.
MD.$signOff),
    $client('invoice.payment_failed', 'invoice', 'Payment failed', 'Payment failed: invoice {{ invoice.number }} ({{ invoice.balance }}) from {{ company.name }}', <<<'MD'
# Payment didn't go through

We couldn't charge your saved payment method for invoice {{ invoice.number }}. Pay with another method to keep your services running.

[Pay {{ invoice.balance }}]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| Due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |
| Reason | {{ payment.failure_reason | default('Declined by the payment provider') }} |
MD.$signOff),
    $client('invoice.refunded', 'invoice', 'Refund issued', 'Your refund from {{ company.name }}: {{ refund.amount }} for invoice {{ invoice.number }}', <<<'MD'
# Refund issued

We've refunded {{ refund.amount }} for invoice {{ invoice.number }}. Depending on your bank, it can take a few days to appear.

| Refund | {{ refund.amount }} |
|---|---|
| Invoice | {{ invoice.number }} |

[View invoice]({{ invoice.url }})
MD.$signOff),
    $client('invoice.modified', 'invoice', 'Invoice updated', 'Invoice {{ invoice.number }} from {{ company.name }} was updated: {{ invoice.balance }} due {{ invoice.due_date | date }}', <<<'MD'
# Invoice {{ invoice.number }} was updated

We've changed this invoice. {{ invoice.balance }} is now due by {{ invoice.due_date | date }}.

[Pay {{ invoice.balance }}]({{ invoice.url }})

| Invoice | {{ invoice.number }} |
|---|---|
| New total | {{ invoice.total }} |
| Due | {{ invoice.due_date | date }}{% if invoice.due_date_bs %} ({{ invoice.due_date_bs }}){% endif %} |
MD.$signOff),
    $client('payment_method.expiring', 'invoice', 'Payment method expiring', 'Your {{ company.name }} payment method expires soon', <<<'MD'
# Your payment method expires soon

Add a new one so renewals continue without interruption.

[Update payment method]({{ system.client_area_url }})
MD.$signOff),
    $client('credit_note.issued', 'invoice', 'Credit note issued', 'Credit note from {{ company.name }}: {{ credit_note.amount }} for invoice {{ invoice.number }}', <<<'MD'
# Credit note issued

We've issued a credit note for {{ credit_note.amount }} against invoice {{ invoice.number }}. The amount is now credit on your account.

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
    $ticket('ticket.autoresponse', 'Ticket received', $supportSubject, <<<'MD'
# We got your request

Ticket #{{ ticket.number }} is open with {{ ticket.department }}. We'll reply as soon as we can.

| Ticket | #{{ ticket.number }} |
|---|---|
| Subject | {{ ticket.subject }} |
| Priority | {{ ticket.priority }} |
MD.$signOff, 'View your ticket'),
    $ticket('ticket.opened_by_staff', 'Ticket opened by staff', $supportSubject, <<<'MD'
# We opened a ticket for you

{{ ticket.department }} opened ticket #{{ ticket.number }}: {{ ticket.subject }}.

{{ reply.body_html | raw }}
MD.$signOff, 'View and reply'),
    $ticket('ticket.reply', 'Ticket reply', $supportSubject, <<<'MD'
# {{ ticket.subject }}

{{ reply.author_name }} from {{ ticket.department }} replied to your ticket.

{{ reply.body_html | raw }}

| Ticket | #{{ ticket.number }} |
|---|---|
| Department | {{ ticket.department }} |
| Priority | {{ ticket.priority }} |

You can also reply to this email. Keep [#{{ ticket.number }}] in the subject so your reply reaches this ticket.
MD, 'View your ticket'),
    $ticket('ticket.auto_closed', 'Ticket closed automatically', $supportSubject, <<<'MD'
# Ticket #{{ ticket.number }} closed

We closed this ticket because we haven't heard back for a while. Reply to this email if you still need help and it will reopen.
MD.$signOff, 'View your ticket'),
    $ticket('ticket.link_replaced', 'Ticket link replaced', '[#{{ ticket.number }}] New link to your ticket', <<<'MD'
# Your ticket link has changed

We combined related tickets into ticket #{{ ticket.number }}, so we replaced its private link. The link in your earlier emails no longer opens it. Use the button below from now on, and keep it to yourself.

If you did not expect this, reply to this email and tell us.
MD.$signOff, 'View your ticket'),
    $client('ticket.closed_bounce', 'support', 'Reply to a closed ticket', 'Your email was not added to a ticket', <<<'MD'
# Your email wasn't added

The ticket you replied to is closed, so your message wasn't added. Please open a new ticket.

[Open a new ticket]({{ system.client_area_url }})
MD.$signOff),
    $client('ticket.unregistered_bounce', 'support', 'Email from an unknown sender', 'Your email was not received', <<<'MD'
# We couldn't accept your email

We only accept support email from registered addresses. Log in to open a ticket.

[Log in]({{ system.client_area_url }})
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

[Open ticket]({{ ticket.admin_url }})

| Priority | {{ ticket.priority }} |
|---|---|
| Department | {{ ticket.department }} |

<blockquote>{{ reply.body_html | raw }}</blockquote>
MD),
    $staff('staff.ticket_client_reply', 'Client replied to a ticket', '[#{{ ticket.number }}] Client reply: {{ ticket.subject }}', <<<'MD'
# Client reply on #{{ ticket.number }}

{{ reply.author_name }} replied to "{{ ticket.subject }}".

[Open ticket]({{ ticket.admin_url }})

<blockquote>{{ reply.body_html | raw }}</blockquote>
MD),
    $staff('staff.ticket_assigned', 'Ticket assigned to you', '[#{{ ticket.number }}] Assigned to you: {{ ticket.subject }}', <<<'MD'
# Ticket #{{ ticket.number }} is yours

{{ ticket.requester_name }}'s ticket "{{ ticket.subject }}" is now assigned to you.

[Open ticket]({{ ticket.admin_url }})
MD),
    $staff('staff.ticket_mention', 'Mentioned in a ticket note', '[#{{ ticket.number }}] You were mentioned', <<<'MD'
# You were mentioned

{{ reply.author_name }} mentioned you in a note on ticket #{{ ticket.number }}.

[Open ticket]({{ ticket.admin_url }})

<blockquote>{{ reply.body_html | raw }}</blockquote>
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
    $staff('staff.domain_sync_report', 'Domain sync report', 'Domain sync report: changes {{ sync.change_count }}, errors {{ sync.error_count }}', <<<'MD'
# Domain sync report

The registrar sync finished. Changes: {{ sync.change_count }}. Errors: {{ sync.error_count }}.

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
    $secret('staff.password_reset', 'staff', 'admin', 'Staff password reset', 'Reset your staff password', 'Reset your staff password', 'Choose a new password', <<<'MD'
Hello {{ staff.first_name }}, use the button below to choose a new password for your staff account.

The link expires at {{ auth.expires_at }}. If this wasn't you, contact {{ support.email }}.
MD),
    $secret('staff.invitation', 'staff', 'admin', 'Staff invitation', 'You are invited to the {{ company.name }} admin area', 'Join the {{ company.name }} team', 'Accept invitation', <<<'MD'
Hello, you've been invited to join the {{ company.name }} team in the admin area. Accept the invitation to choose your password.

This invitation works once, for {{ auth.expires_in }}: until {{ auth.expires_at }}. If it runs out, ask an administrator to send a new one. If you weren't expecting it, you can ignore this email.
MD),
    $staff('staff.two_factor_reset', 'Staff two-factor authentication reset', 'Two-factor authentication was reset on your staff account', <<<'MD'
# Two-factor authentication was reset

{{ notice.actor }} turned off two-factor authentication on your staff account. Set it up again the next time you log in.

If you didn't expect this, tell another administrator straight away.

[Open admin area]({{ system.admin_url }})
MD),
    $staff('staff.email_changed', 'Staff login email changed', 'Your staff login email was changed', <<<'MD'
# Your login email was changed

{{ notice.actor }} changed the login email of your staff account.

| From | {{ notice.old_email }} |
|---|---|
| To | {{ notice.new_email }} |
| Date | {{ system.today | date }} |

If you didn't expect this, tell another administrator straight away.
MD),
    $staff('staff.settings_changed', 'Security settings changed', '{{ notice.section }} settings were changed', <<<'MD'
# {{ notice.section }} settings were changed

{{ notice.actor }} changed these settings: {{ notice.changes }}.

If you didn't expect this, review the change in the activity log.

[Open admin area]({{ system.admin_url }})
MD),
    $staff('staff.login_failures', 'Repeated failed staff logins', 'Repeated failed logins for {{ security.email }}', <<<'MD'
# Repeated failed logins

The staff account {{ security.email }} had {{ security.failures }} failed login attempts. It may be under attack.

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
    $staff('staff.template_render_failed', 'Email template failed to render', 'Email template "{{ template.name }}" failed to render', <<<'MD'
# Email template error

Emails using "{{ template.name }}" are failing until the template is fixed.

| Template | {{ template.name }} |
|---|---|
| Error | {{ template.error }} |

[Fix template]({{ template.admin_url }})
MD),
];

/*
 * Inbox preview lines (design §6): the next most useful fact or the reassurance, never a repeat of the subject.
 */
$preheaders = [
    'auth.password_reset' => 'This link works until {{ auth.expires_at }}. If you didn\'t ask for it, ignore this email and your password stays the same.',
    'auth.password_set' => 'This link works until {{ auth.expires_at }}. If you didn\'t ask for it, ignore this email.',
    'auth.password_changed' => 'Changed on {{ system.today | date }}. If this wasn\'t you, reset your password now.',
    'auth.email_verification' => 'One click confirms this address, so your invoices and service details reach you.',
    'auth.user_invitation' => 'Accept within {{ auth.expires_in }} to choose your password and see this account\'s invoices, services and tickets.',
    'auth.email_changed' => 'Changed on {{ system.today | date }}. Not you? Contact us straight away.',
    'auth.new_login_alert' => 'From {{ auth.ip }} on {{ system.today | date }}. Not you? Change your password now.',
    'auth.login_added' => '{{ notice.email }} can now log in to {{ notice.account }}. Not expected? Remove the login.',
    'auth.access_granted' => 'Log in as usual to see {{ notice.account }}.',
    'auth.owner_changed' => '{{ notice.email }} now owns {{ notice.account }}.',
    'auth.two_factor_reset' => 'Set it up again from your security settings.',
    'kyc.document_decided' => '{% if kyc.approved %}Approved after review.{% else %}What to fix: {{ kyc.reason }}{% endif %}',
    'client.welcome' => 'Your account is ready. Log in to order services, pay invoices and get help.',
    'order.confirmation' => 'Thanks for your order. We\'ll email you as soon as each service is ready.',
    'quote.delivered' => '{{ quote.subject }}. Valid until {{ quote.valid_until | date }}. Accept it online to place the order.',
    'affiliate.activated' => 'Share your link and earn commission on every referred order.',
    'affiliate.withdrawal_processed' => 'It can take a few working days to reach you.',
    'service.welcome.shared_hosting' => 'Log in to see your details and nameservers. Point your domain to us to go live.',
    'service.welcome.reseller_hosting' => 'Log in to your control panel to create your first customer account.',
    'service.welcome.server' => 'Log in to see your details. Change the password after your first login.',
    'service.welcome.other' => 'Everything you need to get started is in the client area.',
    'service.suspended' => '{% if invoice.number %}Pay invoice {{ invoice.number }} to restore it.{% else %}Reply to this email and we\'ll help restore it.{% endif %} Your data is kept safe.',
    'service.unsuspended' => 'Everything is back as it was. Thanks for sorting this out.',
    'service.cancellation_requested' => 'Changed your mind? Reply before the cancellation date.',
    'service.upgrade_completed' => 'The new resources are live now. Your next invoice shows the new price.',
    'service.terminated' => 'Reply if you\'d like help setting up again.',
    'invoice.created' => 'Pay by {{ invoice.due_date | date }} to keep your services running.',
    'invoice.created_autocharge' => 'No action needed. We\'ll charge your saved payment method.',
    'invoice.payment_reminder' => 'A friendly reminder. Paying online takes a minute; reply if anything needs changing.',
    'invoice.overdue_first' => 'It was due on {{ invoice.due_date | date }}. Need more time? Reply and we\'ll work it out with you.',
    'invoice.overdue_second' => 'Now {{ invoice.days_overdue }} days overdue. Please pay it to avoid an interruption.',
    'invoice.overdue_third' => 'Services pause if it stays unpaid. Need more time? Reply and we\'ll work it out with you.',
    'invoice.payment_confirmation' => 'Thank you. {{ payment.amount }} paid on {{ payment.date | date }}.{% if invoice.balance_due %} {{ invoice.balance }} is still due.{% else %} Invoice {{ invoice.number }} is settled.{% endif %}',
    'invoice.payment_failed' => '{{ payment.failure_reason | default(\'Your bank declined it\') }}. Pay with another method to avoid interruption.',
    'invoice.refunded' => 'It can take 5–10 working days to appear, depending on your bank.',
    'invoice.modified' => '{{ invoice.balance }} is due by {{ invoice.due_date | date }}.',
    'payment_method.expiring' => 'Add a new one before then so renewals keep going through.',
    'credit_note.issued' => 'Issued against invoice {{ invoice.number }}.',
    'domain.registration_confirmation' => 'It\'s active until {{ domain.expiry_date | date }}. DNS changes can take a few hours to reach everyone.',
    'domain.transfer_initiated' => 'Most transfers finish within 5–7 days. Approving the request at your current registrar speeds it up.',
    'domain.transfer_completed' => 'Transfer complete. It renews on {{ domain.expiry_date | date }}.',
    'domain.transfer_failed' => 'Here\'s how to fix it and try again.',
    'domain.renewal_confirmation' => 'Nothing else to do. Your website and email keep working.',
    'domain.renewal_upcoming' => 'Renew now to keep your websites and email working.',
    'domain.expired_notice' => 'Renew soon to restore your websites and email at this domain.',
    'ticket.autoresponse' => 'We\'ve received your request and will reply soon. Reply to this email to add details.',
    'ticket.opened_by_staff' => '{{ ticket.department }} opened this ticket for you.',
    'ticket.reply' => '{{ reply.author_name }} from {{ ticket.department }} replied: {{ reply.excerpt }}',
    'ticket.auto_closed' => 'We closed this ticket because we haven\'t heard back. Reply to reopen it.',
    'ticket.link_replaced' => 'Your private ticket link has changed. Use the new one from now on.',
    'ticket.closed_bounce' => 'That ticket is closed. Open a new ticket and we\'ll pick it up from there.',
    'ticket.unregistered_bounce' => 'This address only takes email from customers with an account.',
    'ticket.replies_only_bounce' => 'This address only accepts replies to existing tickets.',
    'staff.password_reset' => 'This link works until {{ auth.expires_at }}. If you didn\'t ask for it, ignore this email.',
    'staff.invitation' => 'Accept within {{ auth.expires_in }} to set your password. The invitation works until {{ auth.expires_at }}.',
];

/*
 * What each template is for, shown in the template list. Staff-facing, one sentence.
 */
$descriptions = [
    'auth.password_reset' => 'Sent to a client who asks to reset a forgotten password.',
    'auth.password_set' => 'Sent to a client who needs to set a password, for example after signing up with a social login.',
    'auth.password_changed' => 'Confirms to the client that their password was changed.',
    'auth.email_verification' => 'Asks a new client to confirm their email address.',
    'auth.user_invitation' => 'Invites someone to join a client account.',
    'client.migration_invitation' => 'Invites a client moved from another billing system to set a password and sign in.',
    'auth.new_login_alert' => 'Warns the client about a sign-in from a new device or place.',
    'auth.login_added' => 'Tells the account owner that a new login was added to their account.',
    'auth.access_granted' => 'Tells a person they now have access to a client account.',
    'auth.owner_changed' => 'Tells the previous and new owner that account ownership moved.',
    'auth.two_factor_reset' => 'Confirms that two-factor authentication was turned off for a client login.',
    'client.welcome' => 'Welcomes a new client and links to the client area.',
    'kyc.document_decided' => 'Tells the client whether their identity document was approved or declined, and why.',
    'order.confirmation' => 'Confirms an order to the client with its items and total.',
    'quote.delivered' => 'Sends a quote to the client with a link to accept it.',
    'affiliate.activated' => 'Tells a client their affiliate account is ready.',
    'affiliate.withdrawal_processed' => 'Tells an affiliate that their withdrawal was processed.',
    'affiliate.monthly_summary' => 'Monthly summary of an affiliate\'s referrals and earnings.',
    'service.welcome.shared_hosting' => 'Sent when a shared hosting account is set up, with login details.',
    'service.welcome.reseller_hosting' => 'Sent when a reseller hosting account is set up, with login details.',
    'service.welcome.server' => 'Sent when a server is set up, with access details.',
    'service.welcome.other' => 'Sent when any other product is activated.',
    'service.suspended' => 'Tells the client their service was suspended and what to do.',
    'service.unsuspended' => 'Tells the client their service is active again.',
    'service.cancellation_requested' => 'Confirms that a cancellation request was received.',
    'service.upgrade_completed' => 'Confirms a plan upgrade or downgrade is complete.',
    'service.terminated' => 'Tells the client their service was removed.',
    'invoice.created' => 'Sent when a new invoice is issued to a client.',
    'invoice.created_autocharge' => 'Sent when an invoice is issued and will be charged to the saved payment method.',
    'invoice.payment_reminder' => 'Reminds the client before an invoice is due.',
    'invoice.overdue_first' => 'First notice after an invoice passes its due date.',
    'invoice.overdue_second' => 'Second notice for an invoice that is still unpaid.',
    'invoice.overdue_third' => 'Final notice for an invoice that is still unpaid.',
    'invoice.payment_confirmation' => 'Receipt sent when a payment is received.',
    'invoice.payment_failed' => 'Tells the client a payment did not go through and how to retry.',
    'invoice.refunded' => 'Tells the client a refund was issued.',
    'invoice.modified' => 'Sent when an issued invoice is changed.',
    'payment_method.expiring' => 'Warns the client that a saved payment method is about to expire.',
    'credit_note.issued' => 'Sent when a credit note is issued against an invoice.',
    'domain.registration_confirmation' => 'Confirms a domain registration.',
    'domain.transfer_initiated' => 'Confirms that a domain transfer to us has started.',
    'domain.transfer_completed' => 'Confirms a domain transfer finished.',
    'domain.transfer_failed' => 'Tells the client a domain transfer did not complete and what to do.',
    'domain.renewal_confirmation' => 'Confirms a domain renewal.',
    'domain.renewal_upcoming' => 'Reminds the client that domains are up for renewal.',
    'domain.expired_notice' => 'Tells the client that domains have expired.',
    'ticket.autoresponse' => 'Automatic reply confirming a support request was received.',
    'ticket.opened_by_staff' => 'Tells the client that staff opened a ticket for them.',
    'ticket.reply' => 'Sends a staff reply to the client.',
    'ticket.auto_closed' => 'Tells the client a ticket was closed because they did not reply.',
    'ticket.link_replaced' => 'Sends a guest the new private link of their ticket after tickets were merged into it.',
    'ticket.closed_bounce' => 'Reply to someone who emailed a ticket that is already closed.',
    'ticket.unregistered_bounce' => 'Reply to an email from an address that has no client account.',
    'ticket.replies_only_bounce' => 'Reply to an email sent to a department that only accepts replies.',
    'staff.order_new' => 'Tells staff a new order was placed.',
    'staff.order_fraud' => 'Tells staff an order was flagged as possible fraud.',
    'staff.ticket_new' => 'Tells staff a new ticket was opened.',
    'staff.ticket_client_reply' => 'Tells staff a client replied to a ticket.',
    'staff.ticket_assigned' => 'Tells a staff member a ticket was assigned to them.',
    'staff.ticket_mention' => 'Tells a staff member they were mentioned in a ticket note.',
    'staff.ticket_department_changed' => 'Tells staff a ticket was moved to their department.',
    'staff.provisioning_failed' => 'Tells staff that setting up a service failed.',
    'staff.registrar_action_failed' => 'Tells staff a registrar action failed.',
    'staff.domain_sync_report' => 'Report of domain changes found during a registrar sync.',
    'staff.free_domain_renewal_blocked' => 'Tells staff a free domain renewal was blocked and needs a decision.',
    'staff.cancellation_request' => 'Tells staff a client asked to cancel a service.',
    'staff.affiliate_withdrawal_request' => 'Tells staff an affiliate requested a withdrawal.',
    'staff.automation_summary' => 'Daily summary of what the automation run did and what failed.',
    'staff.password_reset' => 'Sent to a staff member who asks to reset their password.',
    'staff.invitation' => 'Invites a new staff member to the admin area.',
    'staff.two_factor_reset' => 'Tells a staff member their two-factor authentication was reset.',
    'staff.email_changed' => 'Tells a staff member their login email was changed.',
    'auth.email_changed' => 'Tells the previous address that a login\'s sign-in email was changed, with the new one partly masked.',
    'staff.settings_changed' => 'Tells staff that a security setting was changed.',
    'staff.login_failures' => 'Warns staff about repeated failed sign-ins.',
    'staff.client_details_changed' => 'Tells staff a client changed their details.',
    'staff.webhook_endpoint_disabled' => 'Tells staff a webhook endpoint was disabled after repeated failures.',
    'staff.template_render_failed' => 'Tells staff an email template failed to render, with the error.',
];

return array_map(fn (array $template): array => [...$template, 'preheader' => $preheaders[$template['key']] ?? null, 'description' => $descriptions[$template['key']] ?? null], $templates);
