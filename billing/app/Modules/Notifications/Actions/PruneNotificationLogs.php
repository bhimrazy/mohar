<?php

namespace App\Modules\Notifications\Actions;

use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\WebhookDelivery;
use Carbon\CarbonImmutable;

/**
 * Retention (spec §2, §11): email bodies are cleared after notifications.email_log_body_retention_days
 * (metadata kept); webhook deliveries are deleted after notifications.webhooks_delivery_retention_days.
 */
class PruneNotificationLogs
{
    public function __construct(private Settings $settings) {}

    public function handle(CarbonImmutable $today): int
    {
        $bodyDays = max(1, (int) $this->settings->get('notifications.email_log_body_retention_days', 365));
        $deliveryDays = max(1, (int) $this->settings->get('notifications.webhooks_delivery_retention_days', 30));

        $purged = EmailMessage::query()
            ->whereNull('body_purged_at')
            ->where('created_at', '<', $today->subDays($bodyDays)->startOfDay())
            ->update(['body_html' => null, 'body_text' => null, 'body_purged_at' => now()]);

        $deleted = WebhookDelivery::query()
            ->where('created_at', '<', $today->subDays($deliveryDays)->startOfDay())
            ->delete();

        return $purged + (int) $deleted;
    }
}
