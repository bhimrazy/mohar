<?php

namespace App\Modules\Notifications\Webhooks;

use App\Events\Affiliates\AffiliateCommissionEarned;
use App\Events\Billing\InvoiceCancelled;
use App\Events\Billing\InvoiceCreated;
use App\Events\Billing\InvoiceOverdue;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\InvoiceRefunded;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\PaymentReceived;
use App\Events\Billing\QuoteAccepted;
use App\Events\Core\ClientRegistered;
use App\Events\Core\ClientUpdated;
use App\Events\Domains\DomainExpired;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainRenewed;
use App\Events\Domains\DomainTransferred;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceCancelled;
use App\Events\Services\ServiceRenewed;
use App\Events\Services\ServiceSuspended;
use App\Events\Services\ServiceTerminated;
use App\Events\Services\ServiceUnsuspended;
use App\Events\Services\ServiceUpgraded;
use App\Events\Support\TicketClosed;
use App\Events\Support\TicketOpened;
use App\Events\Support\TicketReplied;

/**
 * Domain events published as outbound webhooks (spec §8): event class => [type, entity, id property].
 * The entity snapshot comes from the owning module's merge-field resolver.
 */
final class WebhookEventTypes
{
    public const PING = 'webhook.ping';

    /** @var array<class-string, array{0: string, 1: string|null, 2: string|null}> */
    public const MAP = [
        ClientRegistered::class => ['client.registered', 'client', 'clientId'],
        ClientUpdated::class => ['client.updated', 'client', 'clientId'],
        OrderPlaced::class => ['order.placed', 'order', 'orderId'],
        OrderMarkedFraud::class => ['order.fraud', 'order', 'orderId'],
        OrderCancelled::class => ['order.cancelled', 'order', 'orderId'],
        InvoiceCreated::class => ['invoice.created', 'invoice', 'invoiceId'],
        InvoicePaid::class => ['invoice.paid', 'invoice', 'invoiceId'],
        InvoiceOverdue::class => ['invoice.overdue', 'invoice', 'invoiceId'],
        InvoiceCancelled::class => ['invoice.cancelled', 'invoice', 'invoiceId'],
        InvoiceRefunded::class => ['invoice.refunded', 'invoice', 'invoiceId'],
        PaymentReceived::class => ['payment.received', 'transaction', 'transactionId'],
        PaymentFailed::class => ['payment.failed', 'invoice', 'invoiceId'],
        QuoteAccepted::class => ['quote.accepted', 'quote', 'quoteId'],
        ServiceActivated::class => ['service.activated', 'service', 'serviceId'],
        ServiceSuspended::class => ['service.suspended', 'service', 'serviceId'],
        ServiceUnsuspended::class => ['service.unsuspended', 'service', 'serviceId'],
        ServiceRenewed::class => ['service.renewed', 'service', 'serviceId'],
        ServiceUpgraded::class => ['service.upgraded', 'service', 'serviceId'],
        ServiceCancelled::class => ['service.cancelled', 'service', 'serviceId'],
        ServiceTerminated::class => ['service.terminated', 'service', 'serviceId'],
        DomainRegistered::class => ['domain.registered', 'domain', 'domainId'],
        DomainRenewed::class => ['domain.renewed', 'domain', 'domainId'],
        DomainTransferred::class => ['domain.transferred', 'domain', 'domainId'],
        DomainExpired::class => ['domain.expired', 'domain', 'domainId'],
        TicketOpened::class => ['ticket.opened', 'ticket', 'ticketId'],
        TicketReplied::class => ['ticket.replied', 'ticket', 'ticketId'],
        TicketClosed::class => ['ticket.closed', 'ticket', 'ticketId'],
        AffiliateCommissionEarned::class => ['affiliate.commission_earned', 'affiliate', 'affiliateId'],
    ];

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_unique([...array_column(self::MAP, 0), self::PING]));
    }
}
