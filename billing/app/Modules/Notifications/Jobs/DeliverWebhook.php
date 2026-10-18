<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Webhooks\WebhookSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt for one delivery, on the dedicated "webhooks" queue so slow receivers never hold up mail.
 * Retries are scheduled on the row (next_attempt_at) and dispatched by notifications:retry-webhooks.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookSender $sender): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->status, [WebhookDeliveryStatus::Succeeded, WebhookDeliveryStatus::Abandoned], true)) {
            return;
        }

        $sender->attempt($delivery);
    }
}
