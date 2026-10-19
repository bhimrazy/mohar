<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Webhooks\WebhookSender;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One attempt for one delivery, on the dedicated "webhooks" queue so slow receivers never hold up mail.
 * Retries are scheduled on the row (next_attempt_at) and dispatched by notifications:retry-webhooks, which
 * also recovers attempts whose job was lost once their dispatch lease expires.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue(Queues::WEBHOOKS);
    }

    public function handle(WebhookSender $sender): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->status, [WebhookDeliveryStatus::Succeeded, WebhookDeliveryStatus::Abandoned], true)) {
            return;
        }

        $sender->attempt($delivery);
    }

    /**
     * The job died (timeout, worker lost): make the row due again instead of waiting out its lease.
     */
    public function failed(?Throwable $exception = null): void
    {
        WebhookDelivery::query()->whereKey($this->deliveryId)
            ->whereIn('status', [WebhookDeliveryStatus::Pending, WebhookDeliveryStatus::Failed])
            ->update(['next_attempt_at' => now()]);
    }
}
