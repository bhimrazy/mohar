<?php

namespace App\Modules\PaymentsStripe\Support;

use App\Data\Billing\CallbackEvent;
use Carbon\CarbonImmutable;

/**
 * The verified facts of one webhook delivery: Stripe's event id and type, the id of the object it names, and the
 * signed send time.
 */
final readonly class WebhookContext
{
    public function __construct(
        public string $eventId,
        public string $type,
        public string $objectId,
        public CarbonImmutable $sentAt,
    ) {}

    /**
     * Acknowledge without moving money. Billing logs the type, which carries the reason for staff.
     */
    public function ignored(string $reason): CallbackEvent
    {
        return new CallbackEvent(
            type: mb_substr('stripe '.$this->type.': '.$reason, 0, 90),
            invoiceId: null,
            txnId: $this->objectId,
            amount: 0,
            currency: '',
            raw: $this->raw(),
            sentAt: $this->sentAt,
        );
    }

    /**
     * @return array{event: string, type: string, object: string}
     */
    public function raw(): array
    {
        return ['event' => $this->eventId, 'type' => $this->type, 'object' => $this->objectId];
    }
}
