<?php

namespace App\Modules\Core\Support;

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\AutomationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Health of the scheduler and the daily automation pipeline (spec §13): a heartbeat written every minute by
 * the scheduler, and the last recorded automation run.
 */
class AutomationStatus
{
    public const HEARTBEAT_KEY = 'automation.last_heartbeat_at';

    /** Hours after which a missing daily run needs attention. */
    public const STALE_AFTER_HOURS = 26;

    public function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }

    public function heartbeatAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::HEARTBEAT_KEY);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    public function lastRunAt(): ?CarbonImmutable
    {
        $value = AutomationRun::query()->max('updated_at');

        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }

    public function failedToday(): int
    {
        return AutomationRun::query()->whereDate('run_date', app(CompanyClock::class)->today()->toDateString())->where('status', 'failed')->count();
    }

    public function isStale(): bool
    {
        $last = $this->lastRunAt();

        return $last === null || $last->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * @return array{last_run_at: string|null, heartbeat_at: string|null, failed_today: int, stale: bool}
     */
    public function summary(): array
    {
        return [
            'last_run_at' => $this->lastRunAt()?->toIso8601String(),
            'heartbeat_at' => $this->heartbeatAt()?->toIso8601String(),
            'failed_today' => $this->failedToday(),
            'stale' => $this->isStale(),
        ];
    }
}
