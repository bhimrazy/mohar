<?php

namespace App\Modules\Notifications\Actions;

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Support\ChunkedDelete;
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
    public function __construct(private Settings $settings, private CompanyClock $clock) {}

    /**
     * @param  CarbonImmutable  $today  the company date as a date value (CompanyClock::todayDate())
     */
    public function handle(CarbonImmutable $today): int
    {
        $bodyDays = max(1, (int) $this->settings->get('notifications.email_log_body_retention_days', 365));
        $deliveryDays = max(1, (int) $this->settings->get('notifications.webhooks_delivery_retention_days', 30));

        // Chunked so the first run after an import doesn't hold one huge UPDATE/DELETE (indexed on
        // (body_purged_at, created_at) and created_at).
        $purged = 0;
        EmailMessage::query()
            ->whereNull('body_purged_at')
            ->where('created_at', '<', $this->clock->dayBounds($today->subDays($bodyDays))[0])
            ->select('id')
            ->chunkById(1000, function ($messages) use (&$purged): void {
                $purged += EmailMessage::query()->whereKey($messages->modelKeys())
                    ->update(['body_html' => null, 'body_text' => null, 'body_purged_at' => now()]);
            });

        $deleted = ChunkedDelete::run(WebhookDelivery::query()->where('created_at', '<', $this->clock->dayBounds($today->subDays($deliveryDays))[0]));

        return $purged + $deleted;
    }
}
