<?php

namespace App\Modules\Notifications\Listeners;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Core\CompanyClock;
use App\Events\Affiliates\AffiliateActivated;
use App\Events\Affiliates\AffiliateWithdrawalRequested;
use App\Events\Billing\CreditNoteIssued;
use App\Events\Billing\DebitNoteIssued;
use App\Events\Billing\GatewayCredentialExpired;
use App\Events\Billing\GatewayCredentialExpiring;
use App\Events\Billing\InvoiceCreated;
use App\Events\Billing\InvoiceModified;
use App\Events\Billing\InvoicePaymentReminder;
use App\Events\Billing\InvoiceRefunded;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\PaymentReceived;
use App\Events\Billing\QuoteSent;
use App\Events\Billing\TaxNoteResendRequested;
use App\Events\Core\AutomationRunCompleted;
use App\Events\Core\ClientRegistered;
use App\Events\Core\ClientUpdated;
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
use App\Events\Kyc\DocumentDecided;
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
use App\Events\Support\TicketAccessLinkReplaced;
use App\Events\Support\TicketAssigned;
use App\Events\Support\TicketClosed;
use App\Events\Support\TicketDepartmentChanged;
use App\Events\Support\TicketNoteAdded;
use App\Events\Support\TicketOpened;
use App\Events\Support\TicketReplied;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\EmailCategory;
use App\Modules\Notifications\Enums\StaffCategory;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\MergeContext;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Services\TicketStaffRecipients;
use App\Modules\Notifications\Support\EventKey;
use App\Modules\Notifications\Support\MailDates;
use App\Modules\Notifications\Webhooks\WebhookUrlGuard;
use App\Support\Currencies\MoneyFormatter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Route;
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
     * Event => handler method. The provider registers handle() for each, without instantiating this class at boot.
     *
     * @var array<class-string, string>
     */
    public const EVENTS = [
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
        CreditNoteIssued::class => 'taxNoteIssued',
        DebitNoteIssued::class => 'taxNoteIssued',
        TaxNoteResendRequested::class => 'taxNoteResendRequested',
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
        TicketAccessLinkReplaced::class => 'ticketLinkReplaced',
        DocumentDecided::class => 'kycDocumentDecided',
        InboundEmailBounced::class => 'inboundBounced',
        AffiliateActivated::class => 'affiliateActivated',
        AffiliateWithdrawalRequested::class => 'affiliateWithdrawalRequested',
        StaffLoginFailuresThreshold::class => 'staffLoginFailures',
        AutomationRunCompleted::class => 'automationCompleted',
        WebhookEndpointDisabled::class => 'webhookEndpointDisabled',
        GatewayCredentialExpiring::class => 'gatewayCredentialExpiring',
        GatewayCredentialExpired::class => 'gatewayCredentialExpired',
    ];

    /**
     * Single entry point for every mapped event: whatever a handler does (resolving merge fields included),
     * an exception is reported and never reaches the request or automation run that raised the event.
     */
    public function handle(object $event): void
    {
        try {
            // Resolvers and templates build links (route(), url()): root them at APP_URL, never the request's Host.
            AccountLinks::withAppOrigin(fn () => match (true) {
                $event instanceof ClientRegistered => $this->clientRegistered($event),
                $event instanceof ClientUpdated => $this->clientUpdated($event),
                $event instanceof OrderPlaced => $this->orderPlaced($event),
                $event instanceof OrderMarkedFraud => $this->orderFraud($event),
                $event instanceof ServiceActivated => $this->serviceActivated($event),
                $event instanceof ProvisioningFailed => $this->provisioningFailed($event),
                $event instanceof ServiceSuspended => $this->serviceSuspended($event),
                $event instanceof ServiceUnsuspended => $this->serviceUnsuspended($event),
                $event instanceof ServiceCancellationRequested => $this->cancellationRequested($event),
                $event instanceof ServiceUpgraded => $this->serviceUpgraded($event),
                $event instanceof ServiceTerminated => $this->serviceTerminated($event),
                $event instanceof InvoiceCreated => $this->invoiceCreated($event),
                $event instanceof InvoicePaymentReminder => $this->invoiceReminder($event),
                $event instanceof PaymentReceived => $this->paymentReceived($event),
                $event instanceof PaymentFailed => $this->paymentFailed($event),
                $event instanceof InvoiceRefunded => $this->invoiceRefunded($event),
                $event instanceof InvoiceModified => $this->invoiceModified($event),
                $event instanceof CreditNoteIssued, $event instanceof DebitNoteIssued => $this->taxNoteIssued($event),
                $event instanceof TaxNoteResendRequested => $this->taxNoteResendRequested($event),
                $event instanceof QuoteSent => $this->quoteSent($event),
                $event instanceof DomainRegistered => $this->domainRegistered($event),
                $event instanceof DomainTransferInitiated => $this->domainTransferInitiated($event),
                $event instanceof DomainTransferred => $this->domainTransferred($event),
                $event instanceof DomainTransferFailed => $this->domainTransferFailed($event),
                $event instanceof DomainRenewed => $this->domainRenewed($event),
                $event instanceof DomainExpiryDigest => $this->domainExpiryDigest($event),
                $event instanceof RegistrarActionFailed => $this->registrarActionFailed($event),
                $event instanceof FreeDomainRenewalBlocked => $this->freeDomainRenewalBlocked($event),
                $event instanceof DomainSyncReport => $this->domainSyncReport($event),
                $event instanceof TicketOpened => $this->ticketOpened($event),
                $event instanceof TicketReplied => $this->ticketReplied($event),
                $event instanceof TicketAssigned => $this->ticketAssigned($event),
                $event instanceof TicketNoteAdded => $this->ticketNoteAdded($event),
                $event instanceof TicketDepartmentChanged => $this->ticketDepartmentChanged($event),
                $event instanceof TicketClosed => $this->ticketClosed($event),
                $event instanceof TicketAccessLinkReplaced => $this->ticketLinkReplaced($event),
                $event instanceof DocumentDecided => $this->kycDocumentDecided($event),
                $event instanceof InboundEmailBounced => $this->inboundBounced($event),
                $event instanceof AffiliateActivated => $this->affiliateActivated($event),
                $event instanceof AffiliateWithdrawalRequested => $this->affiliateWithdrawalRequested($event),
                $event instanceof StaffLoginFailuresThreshold => $this->staffLoginFailures($event),
                $event instanceof AutomationRunCompleted => $this->automationCompleted($event),
                $event instanceof WebhookEndpointDisabled => $this->webhookEndpointDisabled($event),
                $event instanceof GatewayCredentialExpiring => $this->gatewayCredentialExpiring($event),
                $event instanceof GatewayCredentialExpired => $this->gatewayCredentialExpired($event),
                default => null,
            });
        } catch (Throwable $exception) {
            report($exception);
        }
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
            $context = $this->context->service($event->serviceId);
            $templateKey = $this->welcomeTemplate((int) ($context['service']['product_id'] ?? 0));

            if ($templateKey !== null) {
                $this->clientMail($templateKey, $event->clientId, EmailCategory::Product, $context, $event, 'service', $event->serviceId);
            }
        });
    }

    /**
     * An unknown outcome (#147) gets its own notice: the account may exist, and a server check that confirms it
     * applies the command without any further mail.
     */
    public function provisioningFailed(ProvisioningFailed $event): void
    {
        $this->staffMail($event->outcomeUnknown ? 'staff.provisioning_unknown' : 'staff.provisioning_failed', StaffCategory::System, [
            ...$this->context->client($event->clientId),
            ...$this->context->service($event->serviceId),
            'failure' => [
                'action' => $event->action,
                'action_label' => self::provisioningActionLabel($event->action),
                'error' => $event->error,
                // Named route, not a Services class: Notifications works without Services loaded.
                'log_url' => Route::has('admin.services.module-log.index') ? route('admin.services.module-log.index') : null,
            ],
        ], $event, $event->clientId, 'service', $event->serviceId);
    }

    /**
     * The provisioning command in words for staff mail ("creating the account"); unknown commands read as their key.
     */
    private static function provisioningActionLabel(string $action): string
    {
        return match ($action) {
            'create' => __('creating the account'),
            'suspend' => __('suspending the account'),
            'unsuspend' => __('unsuspending the account'),
            'terminate' => __('terminating the account'),
            'change_package' => __('changing the package'),
            'change_password' => __('changing the password'),
            default => str_replace('_', ' ', $action),
        };
    }

    public function serviceSuspended(ServiceSuspended $event): void
    {
        $context = $this->context->service($event->serviceId);
        $overdue = in_array($event->reason, ['overdue', 'addon_overdue'], true);
        // The client reads a reason, never a code: an overdue suspension names the invoice to pay; a staff note
        // (staff:…) is internal and is not shown.
        data_set($context, 'service.suspension_reason', $overdue ? __('Overdue invoice') : null);

        if ($overdue) {
            $invoice = $this->overdueInvoice($event->serviceId, $event->clientId, $event->reason === 'addon_overdue' ? array_values(array_map('intval', (array) data_get($context, 'service.addon_ids', []))) : []);
            $context = [...$context, ...($invoice === [] ? [] : $this->invoiceContextFrom(['invoice' => [...$invoice, 'balance_due' => true]]))];
        }

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
     * Billing's reminder stages: before_due (automation), due (manual "Send reminder"), overdue_1, overdue_2, overdue_3+.
     */
    public function invoiceReminder(InvoicePaymentReminder $event): void
    {
        // Any stage that isn't an overdue notice (before_due, Billing's manual "due", ...) is the payment reminder.
        $templateKey = match (true) {
            $event->stage === 'overdue_1' => 'invoice.overdue_first',
            $event->stage === 'overdue_2' => 'invoice.overdue_second',
            str_starts_with($event->stage, 'overdue_') => 'invoice.overdue_third',
            default => 'invoice.payment_reminder',
        };

        $this->safely(fn () => $this->clientMail($templateKey, $event->clientId, EmailCategory::Invoice, $this->invoiceContext($event->invoiceId), $event, 'invoice', $event->invoiceId));
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
                'amount' => app(MoneyFormatter::class)->amount($event->amount, $event->currency),
                'date' => $transaction['date'] ?? MailDates::companyDate(),
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
            'refund' => ['amount' => app(MoneyFormatter::class)->amount($event->amount, $event->currency)],
        ], $event, 'invoice', $event->invoiceId);
    }

    public function invoiceModified(InvoiceModified $event): void
    {
        $this->clientMail('invoice.modified', $event->clientId, EmailCategory::Invoice, $this->invoiceContext($event->invoiceId), $event, 'invoice', $event->invoiceId);
    }

    /**
     * The note itself, with its PDF attached at send time (the message's related record), unless staff chose not to send it.
     */
    public function taxNoteIssued(CreditNoteIssued|DebitNoteIssued $event): void
    {
        if (! $event->notify) {
            return;
        }

        $this->noteMail($event instanceof CreditNoteIssued ? 'credit_note.issued' : 'debit_note.issued', $event);
    }

    /** The same email again, on staff's request, even when the note was first issued without one. */
    public function taxNoteResendRequested(TaxNoteResendRequested $event): void
    {
        $this->noteMail(($this->context->resolve('tax_note', $event->taxNoteId)['kind'] ?? 'credit') === 'debit' ? 'debit_note.issued' : 'credit_note.issued', $event);
    }

    private function noteMail(string $templateKey, CreditNoteIssued|DebitNoteIssued|TaxNoteResendRequested $event): void
    {
        $this->clientMail($templateKey, $event->clientId, EmailCategory::Invoice, [
            ...$this->invoiceContext($event->invoiceId),
            'tax_note' => [...$this->context->resolve('tax_note', $event->taxNoteId), 'pdf_attached' => (bool) ($this->context->resolve('tax_note_mail', $event->taxNoteId)['pdf_attached'] ?? false)],
        ], $event, 'tax_note', $event->taxNoteId);
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

            $this->send('staff.ticket_new', $this->ticketStaff()->forNewTicket($event->ticketId), $context, $event, $event->clientId, 'ticket', $event->ticketId);
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

            $this->send('staff.ticket_client_reply', $this->ticketStaff()->forCustomerReply($event->ticketId), $context, $event, $event->clientId, 'ticket', $event->ticketId);
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
        $this->safely(fn () => $this->send('staff.ticket_department_changed', $this->ticketStaff()->forDepartmentMove($event->ticketId), $this->context->ticket($event->ticketId), $event, null, 'ticket', $event->ticketId));
    }

    /**
     * Every staff decision on an identity document reaches the client, with the reason when declined (KYC spec §7), while
     * identity verification is on.
     */
    public function kycDocumentDecided(DocumentDecided $event): void
    {
        // While identity verification is off its client page answers 404, so the email's only link would be dead.
        if (! (bool) $this->settings->get('kyc.enabled', false)) {
            return;
        }

        $this->clientMail('kyc.document_decided', $event->clientId, EmailCategory::General, ['kyc' => $this->context->resolve('kyc_document', $event->documentId)], $event, 'kyc_document', $event->documentId);
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
     * The new link goes to the ticket's requester address only (no CC, no account owner): it opens the ticket.
     */
    public function ticketLinkReplaced(TicketAccessLinkReplaced $event): void
    {
        $this->safely(function () use ($event): void {
            $context = $this->context->ticket($event->ticketId);
            $requester = (string) data_get($context, 'ticket.requester_email', '');

            $this->send('ticket.link_replaced', $this->recipients->addresses([$requester], null, (string) data_get($context, 'ticket.requester_name', '')), $context, $event, $event->clientId, 'ticket', $event->ticketId);
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
            'affiliate' => [...$this->context->resolve('affiliate', $event->affiliateId), 'withdrawal_amount' => app(MoneyFormatter::class)->amount($event->amount, $event->currency)],
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
                'url' => WebhookUrlGuard::forLog($event->url),
                'consecutive_failures' => $event->consecutiveFailures,
                'admin_url' => $this->context->link('admin.notifications.webhooks.show', $event->endpointId),
            ],
        ], $event, null, 'webhook_endpoint', $event->endpointId);
    }

    public function gatewayCredentialExpiring(GatewayCredentialExpiring $event): void
    {
        $this->credentialMail('staff.gateway_credential_expiring', $event, $event->gateway, $event->gatewayName, $event->credentialLabel, $event->expiresAt, $event->daysLeft);
    }

    public function gatewayCredentialExpired(GatewayCredentialExpired $event): void
    {
        $this->credentialMail('staff.gateway_credential_expired', $event, $event->gateway, $event->gatewayName, $event->credentialLabel, $event->expiredAt, 0);
    }

    private function credentialMail(string $templateKey, object $event, string $gateway, string $gatewayName, string $label, CarbonImmutable $expiresAt, int $daysLeft): void
    {
        $this->staffMail($templateKey, StaffCategory::System, [
            'credential' => [
                'gateway' => $gatewayName,
                'label' => $label,
                'expires_on' => app(CompanyClock::class)->localDate($expiresAt),
                'days_left' => $daysLeft,
                'when' => match (true) {
                    $daysLeft <= 0 => __('today'),
                    $daysLeft === 1 => __('tomorrow'),
                    default => __('in :count days', ['count' => $daysLeft]),
                },
                'admin_url' => $this->context->link('admin.billing.gateways.edit', $gateway),
            ],
        ], $event, permission: 'billing.gateways.manage');
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

    private function ticketStaff(): TicketStaffRecipients
    {
        return app(TicketStaffRecipients::class);
    }

    private function domainMail(string $templateKey, int $domainId, int $clientId, object $event): void
    {
        $this->clientMail($templateKey, $clientId, EmailCategory::Domain, $this->context->domain($domainId), $event, 'domain', $domainId);
    }

    /**
     * @param  list<Recipient>  $recipients
     * @param  array<string, mixed>  $context
     * @return list<EmailMessage>
     */
    private function send(string $templateKey, array $recipients, array $context, object $event, ?int $clientId = null, ?string $relatedType = null, ?int $relatedId = null, bool $carriesSecret = false): array
    {
        if ($recipients === []) {
            return [];
        }

        return $this->mailer->send(new EmailRequest($templateKey, $recipients, $context, EventKey::of($event), $clientId, $relatedType, $relatedId, carriesSecret: $carriesSecret));
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
     * The oldest overdue invoice of the account that bills the service (or, for an add-on suspension, its add-ons).
     *
     * @param  list<int>  $addonIds
     * @return array<string, mixed>
     */
    private function overdueInvoice(int $serviceId, int $clientId, array $addonIds): array
    {
        $candidates = array_filter([
            $this->context->resolve('service_overdue_invoice', $serviceId),
            ...array_map(fn (int $addonId): array => $this->context->resolve('service_addon_overdue_invoice', $addonId), $addonIds),
        ], fn (array $invoice): bool => $invoice !== [] && (int) ($invoice['client_id'] ?? 0) === $clientId);
        usort($candidates, fn (array $a, array $b): int => strcmp((string) ($a['due_date'] ?? ''), (string) ($b['due_date'] ?? '')));

        return $candidates[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceContext(int $invoiceId): array
    {
        return $this->invoiceContextFrom($this->context->invoice($invoiceId));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function invoiceContextFrom(array $context): array
    {
        $dueDate = data_get($context, 'invoice.due_date');

        if (is_string($dueDate) && $dueDate !== '') {
            data_set($context, 'invoice.days_overdue', max(0, -(int) MailDates::daysFromToday($dueDate)));
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
