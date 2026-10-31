<?php

namespace App\Modules\Support\Actions;

use App\Modules\Support\Models\InboundEmail;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Settings\SupportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes raw inbound payloads older than the retention window (spec §11). Idempotent.
 */
class PurgeInboundRaw
{
    public function __construct(private SupportSettings $settings) {}

    public function handle(?CarbonImmutable $today = null): int
    {
        $cutoff = ($today ?? CarbonImmutable::now())->subDays(max(1, $this->settings->int('inbound_raw_retention_days', 30)));
        $purged = 0;

        InboundEmail::query()->whereNotNull('raw_path')->where('received_at', '<', $cutoff)
            ->chunkById(500, function ($emails) use (&$purged): void {
                foreach ($emails as $email) {
                    Storage::disk(AttachmentStore::DISK)->delete((string) $email->raw_path);
                    $email->forceFill(['raw_path' => null])->save();
                    $purged++;
                }
            });

        return $purged;
    }
}
