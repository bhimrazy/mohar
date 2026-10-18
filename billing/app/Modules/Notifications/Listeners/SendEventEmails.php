<?php

namespace App\Modules\Notifications\Listeners;

use App\Contracts\Catalog\PriceBook;
use App\Events\Affiliates\AffiliateActivated;
use App\Events\Affiliates\AffiliateWithdrawalRequested;
use App\Events\Billing\InvoiceCreated;
use App\Events\Billing\InvoiceModified;
use App\Events\Billing\InvoicePaymentReminder;
use App\Events\Billing\InvoiceRefunded;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\PaymentReceived;
use App\Events\Billing\QuoteSent;
use App\Events\Core\AutomationRunCompleted;
use App\Events\Core\ClientRegistered;
use App\Events\Core\ClientUpdated;
use App\Events\Core\EmailVerificationRequested;
use App\Events\Core\PasswordResetRequested;
use App\Events\Core\StaffLoginFailuresThreshold;
use App\Events\Domains\DomainExpiryDigest;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainRenewed;
use App\Events\Domains\DomainSyncReport;
use App\Events\Domains\DomainTransferFailed;
use App\Events\Domains\DomainTransferInitiated;
use App\Events\Domains\DomainTransferred;
use App\Events\Domains\FreeDomainRenewalBlocked;
use App\Events\Domains\RegistrarActionFailed;
use App\Events\Notifications\WebhookEndpointDisabled;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\ProvisioningFailed;
use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceCancellationRequested;
use App\Events\Services\ServiceSuspended;
use App\Events\Services\ServiceTerminated;
use App\Events\Services\ServiceUnsuspended;
use App\Events\Services\ServiceUpgraded;
use App\Events\Support\InboundEmailBounced;
use App\Events\Support\TicketAssigned;
use App\Events\Support\TicketClosed;
use App\Events\Support\TicketDepartmentChanged;
use App\Events\Support\TicketNoteAdded;
use App\Events\Support\TicketOpened;
use App\Events\Support\TicketReplied;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\EmailCategory;
use App\Modules\Notifications\Enums\StaffCategory;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\MergeContext;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Support\EventKey;
use App\Modules\Notifications\Support\MoneyText;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

/**
 * Event → template mapping (spec §6). Runs after the originating transaction commits and never lets a
 * mail problem break the business flow that raised the event.
 */
class SendEventEmails implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private NotificationMailer $mailer,
        private RecipientResolver $recipients,
        private MergeContext $context,
        private Settings $settings,
    ) {}

    /**
     * Event => handler method. Registered by the provider without instantiating this class at boot.
     *
     * @var array<class-string, string>
     */
    public const EVENTS = [
        PasswordResetRequested::class => 'passwordReset',
        EmailVerificationRequested::class => 'emailVerification',
        ClientRegistered::class => 'clientRegistered',
        ClientUpdated::class => 'clientUpdated',
        OrderPlaced::class => 'orderPlaced',
        OrderMarkedFraud::class => 'orderFraud',
        ServiceActivated::class => 'serviceActivated',
        ProvisioningFailed::class => 'provisioningFailed',
        ServiceSuspended::class => 'serviceSuspended',
        ServiceUnsuspended::class => 'serviceUnsuspended',
        ServiceCancellationRequested::class => 'cancellationRequested',
        ServiceUpgraded::class => 'serviceUpgraded',
        ServiceTerminated::class => 'serviceTerminated',
        InvoiceCreated::class => 'invoiceCreated',
        InvoicePaymentReminder::class => 'invoiceReminder',
        PaymentReceived::class => 'paymentReceived',
        PaymentFailed::class => 'paymentFailed',
        InvoiceRefunded::class => 'invoiceRefunded',
        InvoiceModified::class => 'invoiceModified',
        QuoteSent::class => 'quoteSent',
        DomainRegistered::class => 'domainRegistered',
        DomainTransferInitiated::class => 'domainTransferInitiated',
        DomainTransferred::class => 'domainTransferred',
        DomainTransferFailed::class => 'domainTransferFailed',
        DomainRenewed::class => 'domainRenewed',
        DomainExpiryDigest::class => 'domainExpiryDigest',
        RegistrarActionFailed::class => 'registrarActionFailed',
        FreeDomainRenewalBlocked::class => 'freeDomainRenewalBlocked',
        DomainSyncReport::class => 'domainSyncReport',
        TicketOpened::class => 'ticketOpened',
        TicketReplied::class => 'ticketReplied',
        TicketAssigned::class => 'ticketAssigned',
        TicketNoteAdded::class => 'ticketNoteAdded',
        TicketDepartmentChanged::class => 'ticketDepartmentChanged',
        TicketClosed::class => 'ticketClosed',
        InboundEmailBounced::class => 'inboundBounced',
        AffiliateActivated::class => 'affiliateActivated',
        AffiliateWithdrawalRequested::class => 'affiliateWithdrawalRequested',
        StaffLoginFailuresThreshold::class => 'staffLoginFailures',
        AutomationRunCompleted::class => 'automationCompleted',
        WebhookEndpointDisabled::class => 'webhookEndpointDisabled',
    ];

    // ── Auth (always sent, ignores preferences) ─────────────────────────────────────────────────

    public function passwordReset(PasswordResetRequested $event): void
    {
        $this->safely(function () use ($event): void {
            $user = User::query()->find($event->userId);

            if ($user === null) {
                return;
            }

            $client = $user->is_staff ? null : $user->clients()->orderBy('clients.id')->first();
            $recipient = $this->recipients->user($user->id, $client);

            $this->send($user->is_staff ? 'staff.password_reset' : 'auth.password_reset', $recipient === null ? [] : [$recipient], [
                ...$this->context->client($client?->id),
                'user' => ['first_name' => $recipient?->firstName(), 'email' => $user->email],
                'auth' => ['reset_url' => $event->resetUrl, 'expires_at' => $event->expiresAt],
            ], $event, $client?->id, 'user', $user->id);
        });
    }

    public function emailVerification(EmailVerificationRequested $event): void
    {
        $this->safely(function () use ($event): void {
            $user = User::query()->find($event->userId);

            if ($user === null) {
                return;
            }

            $client = $user->clients()->orderBy('clients.id')->first();
            $recipient = $this->recipients->user($user->id, $client);

            $this->send('auth.email_verification', $recipient === null ? [] : [$recipient], [
                ...$this->context->client($client?->id),
                'user' => ['first_name' => $recipient?->firstName(), 'email' => $user->email],
                'auth' => ['verify_url' => $event->verifyUrl],
            ], $event, $client?->id, 'user', $user->id);
        });
    }

    // ── Clients & orders ────────────────────────────────────────────────────────────────────────

    public function clientRegistered(ClientRegistered $event): void
    {
        $this->clientMail('client.welcome', $event->clientId, EmailCategory::General, [], $event, 'client', $event->clientId);
    }

    public function clientUpdated(ClientUpdated $event): void
    {
        $actor = auth()->user();

        // Only changes the client made themselves, and only when the install opted in (spec §6).
        if (! (bool) $this->settings->get('notifications.notify_client_changes', false) || ! $actor instanceof User || $actor->is_staff) {
            return;
        }

        $this->staffMail('staff.client_details_changed', StaffCategory::Account, $this->context->client($event->clientId), $event, $event->clientId, 'client', $event->clientId);
    }

    public function orderPlaced(OrderPlaced $event): void
    {
        $context = $this->context->order($event->orderId);
        $this->clientMail('order.confirmation', $event->clientId, EmailCategory::General, $context, $event, 'order', $event->orderId);
        $this->staffMail('staff.order_new', StaffCategory::Account, [...$this->context->client($event->clientId), ...$context], $event, $event->clientId, 'order', $event->orderId);
    }

    public function orderFraud(OrderMarkedFraud $event): void
    {
        $this->staffMail('staff.order_fraud', StaffCategory::Account, [...$this->context->client($event->clientId), ...$this->context->order($event->orderId)], $event, $event->clientId, 'order', $event->orderId);
    }

    // ── Services ────────────────────────────────────────────────────────────────────────────────

    public function serviceActivated(ServiceActivated $event): void
    {
        $this->safely(function () use ($event): void {
            $context = $this->context->service($event->serviceId, withCredentials: true);
            $templateKey = $this->welcomeTemplate((int) ($context['service']['product_id'] ?? 0));

            if ($templateKey !== null) {
                $this->clientMail($templateKey, $event->clientId, EmailCategory::Product, $context, $event, 'service', $event->serviceId);
            }
        });
    }

    public function provisioningFailed(ProvisioningFailed $event): void
    {
        $this->staffMail('staff.provisioning_failed', StaffCategory::System, [
            ...$this->context->client($event->clientId),
            ...$this->context->service($event->serviceId),
            'failure' => ['action' => $event->action, 'error' => $event->error],
        ], $event, $event->clientId, 'service', $event->serviceId);
    }

    public function serviceSuspended(ServiceSuspended $event): void
    {
        $context = $this->context->service($event->serviceId);
        data_set($context, 'service.suspension_reason', $event->reason ?: data_get($context, 'service.suspension_reason'));
        $this->clientMail('service.suspended', $event->clientId, EmailCategory::Product, $context, $event, 'service', $event->serviceId);
    }

    public function serviceUnsuspended(ServiceUnsuspended $event): void
    {
        $this->clientMail('service.unsuspended', $event->clientId, EmailCategory::Product, $this->context->service($event->serviceId), $event, 'service', $event->serviceId);
    }

    public function cancellationRequested(ServiceCancellationRequested $event): void
    {
        $context = [...$this->context->service($event->serviceId), 'cancellation' => ['type' => $event->type, 'reason' => $event->reason]];
        $this->clientMail('service.cancellation_requested', $event->clientId, EmailCategory::Product, $context, $event, 'service', $event->serviceId);
        $this->staffMail('staff.cancellation_request', StaffCategory::Account, [...$this->context->client($event->clientId), ...$context], $event, $event->clientId, 'service', $event->serviceId);
    }

    public function serviceUpgraded(ServiceUpgraded $event): void
    {
        $this->clientMail('service.upgrade_completed', $event->clientId, EmailCategory::Product, $this->context->service($event->serviceId), $event, 'service', $event->serviceId);
    }

    public function serviceTerminated(ServiceTerminated $event): void
    {
        $this->clientMail('service.terminated', $event->clientId, EmailCategory::Product, $this->context->service($event->serviceId), $event, 'service', $event->serviceId);
    }

    // ── Billing ─────────────────────────────────────────────────────────────────────────────────

    public function invoiceCreated(InvoiceCreated $event): void
    {
        if ($event->notify) {
            $this->clientMail('invoice.created', $event->clientId, EmailCategory::Invoice, $this->invoiceContext($event->invoiceId), $event, 'invoice', $event->invoiceId);
        }
    }

    /**
     * Billing's reminder stages: before_due, overdue_1, overdue_2, overdue_3+ (one per invoice and day).
     */
    public function invoiceReminder(InvoicePaymentReminder $event): void
    {
        $templateKey = match (true) {
            $event->stage === 'before_due' => 'invoice.payment_reminder',
            $event->stage === 'overdue_1' => 'invoice.overdue_first',
            $event->stage === 'overdue_2' => 'invoice.overdue_second',
            str_starts_with($event->stage, 'overdue_') => 'invoice.overdue_third',
            default => null,
        };

        if ($templateKey !== null) {
            $this->clientMail($templateKey, $event->clientId, EmailCategory::Invoice, $this->invoiceContext($event->invoiceId), $event, 'invoice', $event->invoiceId);
        }
    }

    public function paymentReceived(PaymentReceived $event): void
    {
        if ($event->invoiceId === null) {
            return;
        }

        $transaction = $this->context->resolve('transaction', $event->transactionId);
        $this->clientMail('invoice.payment_confirmation', $event->clientId, EmailCategory::Invoice, [
            ...$this->invoiceContext($event->invoiceId),
            'payment' => [
                'amount' => MoneyText::format($event->amount, $event->currency),
                'date' => $transaction['date'] ?? now()->toDateString(),
                'reference' => $transaction['reference'] ?? null,
                'gateway' => $event->gateway,
            ],
        ], $event, 'invoice', $event->invoiceId);
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        if ($event->invoiceId !== null) {
            $this->clientMail('invoice.payment_failed', $event->clientId, EmailCategory::Invoice, [
                ...$this->invoiceContext($event->invoiceId),
                'payment' => ['gateway' => $event->gateway, 'failure_reason' => $event->reason],
            ], $event, 'invoice', $event->invoiceId);
        }
    }

    public function invoiceRefunded(InvoiceRefunded $event): void
    {
        $this->clientMail('invoice.refunded', $event->clientId, EmailCategory::Invoice, [
            ...$this->invoiceContext($event->invoiceId),
            'refund' => ['amount' => MoneyText::format($event->amount, $event->currency)],
        ], $event, 'invoice', $event->invoiceId);
    }

    public function invoiceModified(InvoiceModified $event): void
    {
        $this->clientMail('invoice.modified', $event->clientId, EmailCategory::Invoice, $this->invoiceContext($event->invoiceId), $event, 'invoice', $event->invoiceId);
    }

    /**
     * An explicit staff action: always sent to the account's primary address.
     */
    public function quoteSent(QuoteSent $event): void
    {
        $this->safely(function () use ($event): void {
            $client = Client::query()->find($event->clientId);

            if ($client !== null) {
                $this->send('quote.delivered', [$this->recipients->owner($client)], [
                    ...$this->context->client($client->id),
                    'quote' => $this->context->resolve('quote', $event->quoteId),
                ], $event, $client->id, 'quote', $event->quoteId);
            }
        });
    }

    // ── Domains ─────────────────────────────────────────────────────────────────────────────────

    public function domainRegistered(DomainRegistered $event): void
    {
        $this->domainMail('domain.registration_confirmation', $event->domainId, $event->clientId, $event);
    }

    public function domainTransferInitiated(DomainTransferInitiated $event): void
    {
        $this->domainMail('domain.transfer_initiated', $event->domainId, $event->clientId, $event);
    }

    public function domainTransferred(DomainTransferred $event): void
    {
        $this->domainMail('domain.transfer_completed', $event->domainId, $event->clientId, $event);
    }

    public function domainTransferFailed(DomainTransferFailed $event): void
    {
        $context = $this->context->domain($event->domainId);
        data_set($context, 'domain.transfer_failure_reason', $event->reason);
        $this->clientMail('domain.transfer_failed', $event->clientId, EmailCategory::Domain, $context, $event, 'domain', $event->domainId);
        $this->staffMail('staff.registrar_action_failed', StaffCategory::System, [
            ...$this->context->client($event->clientId), ...$context, 'failure' => ['action' => 'transfer', 'error' => $event->reason],
        ], $event, $event->clientId, 'domain', $event->domainId);
    }

    public function domainRenewed(DomainRenewed $event): void
    {
        $this->domainMail('domain.renewal_confirmation', $event->domainId, $event->clientId, $event);
    }

    /**
     * One email per client and run (spec §6): domains still to expire get the reminder, expired ones the notice.
     */
    public function domainExpiryDigest(DomainExpiryDigest $event): void
    {
        $items = array_map(fn (array $domain): array => [
            'name' => $domain['domain'],
            'expiry_date' => $domain['expiry_date'],
            'days_until_expiry' => $domain['days_left'],
            'renew_url' => $this->context->link('client.domains.show', $domain['domain_id']),
        ], $event->domains);

        $upcoming = array_values(array_filter($items, fn (array $item): bool => $item['days_until_expiry'] >= 0));
        $expired = array_values(array_filter($items, fn (array $item): bool => $item['days_until_expiry'] < 0));

        if ($upcoming !== []) {
            $this->clientMail('domain.renewal_upcoming', $event->clientId, EmailCategory::Domain, ['domains' => $upcoming], $event, 'client', $event->clientId);
        }

        if ($expired !== []) {
            $this->clientMail('domain.expired_notice', $event->clientId, EmailCategory::Domain, ['domains' => $expired], $event, 'client', $event->clientId);
        }
    }

    public function registrarActionFailed(RegistrarActionFailed $event): void
    {
        $this->staffMail('staff.registrar_action_failed', StaffCategory::System, [
            ...$this->context->client($event->clientId), ...$this->context->domain($event->domainId),
            'failure' => ['action' => $event->action, 'error' => $event->error],
        ], $event, $event->clientId, 'domain', $event->domainId);
    }

    public function freeDomainRenewalBlocked(FreeDomainRenewalBlocked $event): void
    {
        $this->staffMail('staff.free_domain_renewal_blocked', StaffCategory::System, [
            ...$this->context->client($event->clientId), ...$this->context->domain($event->domainId),
            'failure' => ['action' => 'free_renewal', 'error' => $event->reason],
        ], $event, $event->clientId, 'domain', $event->domainId);
    }

    public function domainSyncReport(DomainSyncReport $event): void
    {
        if ($event->changes === [] && $event->errors === []) {
            return;
        }

        $lines = [
            ...array_map(fn (array $change): string => "{$change['domain']}: {$change['field']} ".($change['from'] ?? '—').' → '.($change['to'] ?? '—'), $event->changes),
            ...array_map(fn (array $error): string => "{$error['domain']}: {$error['error']}", $event->errors),
        ];

        $this->staffMail('staff.domain_sync_report', StaffCategory::System, [
            'sync' => ['change_count' => count($event->changes), 'error_count' => count($event->errors), 'lines' => array_slice($lines, 0, 200)],
        ], $event);
    }

    // ── Support ─────────────────────────────────────────────────────────────────────────────────

    public function ticketOpened(TicketOpened $event): void
    {
        $this->safely(function () use ($event): void {
            $context = $this->context->ticket($event->ticketId);

            if ($context === []) {
                return;
            }

            if ($event->byStaff) {
                $this->send('ticket.opened_by_staff', $this->ticketRecipients($context, $event->clientId), $context, $event, $event->clientId, 'ticket', $event->ticketId);

                return;
            }

            if ((bool) data_get($context, 'ticket.autoresponse', true)) {
                $this->send('ticket.autoresponse', $this->ticketRecipients($context, $event->clientId), $context, $event, $event->clientId, 'ticket', $event->ticketId);
            }

            $this->staffMail('staff.ticket_new', StaffCategory::Support, $context, $event, $event->clientId, 'ticket', $event->ticketId, 'support.tickets.view');
        });
    }

    public function ticketReplied(TicketReplied $event): void
    {
        $this->safely(function () use ($event): void {
            $context = $this->context->ticket($event->ticketId, $event->replyId);

            if ($context === [] || (bool) data_get($context, 'reply.is_note', false)) {
                return;
            }

            if ($event->byStaff) {
                $this->send('ticket.reply', $this->ticketRecipients($context, $event->clientId), $context, $event, $event->clientId, 'ticket', $event->ticketId);

                return;
            }

            $this->staffMail('staff.ticket_client_reply', StaffCategory::Support, $context, $event, $event->clientId, 'ticket', $event->ticketId, 'support.tickets.view');
        });
    }

    public function ticketAssigned(TicketAssigned $event): void
    {
        if (auth()->id() === $event->staffUserId) {
            return;
        }

        $this->staffMail('staff.ticket_assigned', null, $this->context->ticket($event->ticketId), $event, null, 'ticket', $event->ticketId, onlyUserIds: [$event->staffUserId]);
    }

    public function ticketNoteAdded(TicketNoteAdded $event): void
    {
        $mentions = array_values(array_diff($event->mentions, [$event->staffUserId]));

        if ($mentions !== []) {
            $this->staffMail('staff.ticket_mention', null, $this->context->ticket($event->ticketId, $event->replyId), $event, null, 'ticket', $event->ticketId, onlyUserIds: $mentions);
        }
    }

    public function ticketDepartmentChanged(TicketDepartmentChanged $event): void
    {
        $this->staffMail('staff.ticket_department_changed', StaffCategory::Support, $this->context->ticket($event->ticketId), $event, null, 'ticket', $event->ticketId, 'support.tickets.view');
    }

    public function ticketClosed(TicketClosed $event): void
    {
        if ($event->closedBy !== 'system') {
            return;
        }

        $this->safely(function () use ($event): void {
            $context = $this->context->ticket($event->ticketId);
            $requester = (string) data_get($context, 'ticket.requester_email', '');
            $client = $event->clientId !== null ? Client::query()->find($event->clientId) : null;

            $this->send('ticket.auto_closed', $this->recipients->addresses([$requester], $client, (string) data_get($context, 'ticket.requester_name', '')), $context, $event, $event->clientId, 'ticket', $event->ticketId);
        });
    }

    /**
     * At most one bounce per sender and hour (spec §6), whichever template it is.
     */
    public function inboundBounced(InboundEmailBounced $event): void
    {
        if (! str_starts_with($event->template, 'ticket.') || ! str_ends_with($event->template, '_bounce')) {
            return;
        }

        $this->safely(fn () => $this->mailer->send(new EmailRequest(
            $event->template,
            $this->recipients->addresses([$event->recipient]),
            [],
            'bounce|'.mb_strtolower($event->recipient).'|'.now()->format('YmdH'),
            relatedType: 'inbound_email',
            relatedId: $event->inboundEmailId,
        )));
    }

    // ── Affiliates, security, system ────────────────────────────────────────────────────────────

    public function affiliateActivated(AffiliateActivated $event): void
    {
        $this->clientMail('affiliate.activated', $event->clientId, EmailCategory::Affiliate, ['affiliate' => $this->context->resolve('affiliate', $event->affiliateId)], $event, 'affiliate', $event->affiliateId);
    }

    public function affiliateWithdrawalRequested(AffiliateWithdrawalRequested $event): void
    {
        $this->staffMail('staff.affiliate_withdrawal_request', StaffCategory::Account, [
            'affiliate' => [...$this->context->resolve('affiliate', $event->affiliateId), 'withdrawal_amount' => MoneyText::format($event->amount, $event->currency)],
        ], $event, null, 'affiliate', $event->affiliateId);
    }

    public function staffLoginFailures(StaffLoginFailuresThreshold $event): void
    {
        $this->safely(function () use ($event): void {
            $user = User::query()->find($event->userId);
            $this->send('staff.login_failures', $this->recipients->administrators(), ['security' => ['email' => $user?->email, 'failures' => $event->failures]], $event, null, 'user', $event->userId);
        });
    }

    public function automationCompleted(AutomationRunCompleted $event): void
    {
        if ((bool) $this->settings->get('notifications.daily_summary', true)) {
            $this->staffMail('staff.automation_summary', StaffCategory::System, [
                'automation' => ['run_at' => $event->runDate, 'status' => $event->failed ? 'failed' : 'completed'],
            ], $event);
        }
    }

    public function webhookEndpointDisabled(WebhookEndpointDisabled $event): void
    {
        $this->staffMail('staff.webhook_endpoint_disabled', StaffCategory::System, [
            'webhook' => [
                'url' => $event->url,
                'consecutive_failures' => $event->consecutiveFailures,
                'admin_url' => $this->context->link('admin.notifications.webhooks.show', $event->endpointId),
            ],
        ], $event, null, 'webhook_endpoint', $event->endpointId);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $context
     */
    private function clientMail(string $templateKey, int $clientId, EmailCategory $category, array $context, object $event, ?string $relatedType = null, ?int $relatedId = null): void
    {
        $this->safely(fn () => $this->send(
            $templateKey,
            $this->recipients->forClient($clientId, $category),
            [...$this->context->client($clientId), ...$context],
            $event, $clientId, $relatedType, $relatedId,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $onlyUserIds  explicit staff recipients (assignee, mentions) instead of a category
     */
    private function staffMail(string $templateKey, ?StaffCategory $category, array $context, object $event, ?int $clientId = null, ?string $relatedType = null, ?int $relatedId = null, ?string $permission = null, array $onlyUserIds = []): void
    {
        $this->safely(fn () => $this->send(
            $templateKey,
            $this->recipients->staff($category ?? StaffCategory::System, $permission, $onlyUserIds),
            $context, $event, $clientId, $relatedType, $relatedId,
        ));
    }

    private function domainMail(string $templateKey, int $domainId, int $clientId, object $event): void
    {
        $this->clientMail($templateKey, $clientId, EmailCategory::Domain, $this->context->domain($domainId), $event, 'domain', $domainId);
    }

    /**
     * @param  list<Recipient>  $recipients
     * @param  array<string, mixed>  $context
     */
    private function send(string $templateKey, array $recipients, array $context, object $event, ?int $clientId = null, ?string $relatedType = null, ?int $relatedId = null): void
    {
        if ($recipients === []) {
            return;
        }

        $this->mailer->send(new EmailRequest($templateKey, $recipients, $context, EventKey::of($event), $clientId, $relatedType, $relatedId));
    }

    /**
     * Requester + CCs, plus the account's primary address when someone else (a contact) opened it.
     * The support preference gate never applies to the requester's own ticket.
     *
     * @param  array<string, mixed>  $context
     * @return list<Recipient>
     */
    private function ticketRecipients(array $context, ?int $clientId): array
    {
        $client = $clientId !== null ? Client::query()->find($clientId) : null;
        $requester = mb_strtolower((string) data_get($context, 'ticket.requester_email', ''));
        $cc = array_values(array_filter((array) data_get($context, 'ticket.cc', []), 'is_string'));

        $recipients = [
            ...$this->recipients->addresses([$requester], $client, (string) data_get($context, 'ticket.requester_name', '')),
            ...$this->recipients->addresses($cc, $client),
        ];

        if ($client !== null && $requester !== mb_strtolower($client->email)) {
            if ($this->recipients->ownerWants($client, EmailCategory::Support)) {
                $recipients[] = $this->recipients->owner($client);
            }
        }

        return $this->recipients->unique($recipients);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceContext(int $invoiceId): array
    {
        $context = $this->context->invoice($invoiceId);
        $dueDate = data_get($context, 'invoice.due_date');

        if (is_string($dueDate) && $dueDate !== '') {
            data_set($context, 'invoice.days_overdue', max(0, (int) CarbonImmutable::parse($dueDate)->diffInDays(CarbonImmutable::today(), false)));
        }

        return $context;
    }

    /**
     * The product's welcome template ("none" turns it off), else service.welcome.<product type>.
     */
    private function welcomeTemplate(int $productId): ?string
    {
        $product = $productId > 0 && app()->bound(PriceBook::class) ? app(PriceBook::class)->product($productId) : null;

        if ($product?->welcomeEmail === 'none') {
            return null;
        }

        if ($product?->welcomeEmail !== null && EmailTemplate::query()->where('key', $product->welcomeEmail)->exists()) {
            return $product->welcomeEmail;
        }

        return 'service.welcome.'.($product !== null && in_array($product->type, ['shared_hosting', 'reseller_hosting', 'server'], true) ? $product->type : 'other');
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function safely(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
