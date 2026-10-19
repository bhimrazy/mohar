<?php

namespace App\Modules\Notifications\Console\Commands;

use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Jobs\DeliverWebhook;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Dispatches deliveries whose next attempt is due (spec §11) — scheduled retries and attempts whose job was
 * lost (the dispatch lease ran out). Each row is claimed with a conditional update that extends its lease, so
 * overlapping runs never dispatch the same attempt twice, and a job lost after the claim is taken again later.
 */
#[Signature('notifications:retry-webhooks {--limit=200}')]
#[Description('Dispatch webhook deliveries whose next attempt is due')]
class RetryWebhooks extends Command
{
    public function handle(): int
    {
        $dispatched = 0;

        $due = WebhookDelivery::query()
            ->whereIn('status', [WebhookDeliveryStatus::Pending, WebhookDeliveryStatus::Failed])
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'next_attempt_at']);

        foreach ($due as $delivery) {
            $claimed = WebhookDelivery::query()->whereKey($delivery->id)
                ->where('next_attempt_at', $delivery->next_attempt_at)
                ->update(['next_attempt_at' => WebhookPublisher::lease()]);

            if ($claimed === 1) {
                DeliverWebhook::dispatch($delivery->id);
                $dispatched++;
            }
        }

        $this->components->info("Dispatched {$dispatched} webhook deliveries.");

        return self::SUCCESS;
    }
}
