<?php

namespace App\Modules\Support\Actions;

use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Settings\SupportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes files of tickets closed longer than support.prune_attachments_after_months (0 = never);
 * metadata stays with pruned_at set (spec §4.3). Idempotent.
 */
class PruneAttachments
{
    public function __construct(private SupportSettings $settings) {}

    public function handle(?CarbonImmutable $today = null): int
    {
        $months = $this->settings->int('prune_attachments_after_months', 0);

        if ($months <= 0) {
            return 0;
        }

        $cutoff = ($today ?? CarbonImmutable::now())->subMonths($months);
        $pruned = 0;

        TicketAttachment::query()->whereNull('pruned_at')
            ->whereHas('ticket', fn (Builder $query) => $query->where('status', TicketStatus::Closed->value)->where('closed_at', '<', $cutoff))
            ->chunkById(500, function ($attachments) use (&$pruned): void {
                foreach ($attachments as $attachment) {
                    Storage::disk($attachment->disk)->delete($attachment->path);
                    $attachment->forceFill(['pruned_at' => now()])->save();
                    $pruned++;
                }
            });

        return $pruned;
    }
}
