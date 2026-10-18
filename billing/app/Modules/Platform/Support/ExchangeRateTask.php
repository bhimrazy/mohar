<?php

namespace App\Modules\Platform\Support;

use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Actions\Currencies\UpdateExchangeRates;
use App\Support\Currencies\ExchangeRateUnavailable;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The daily exchange-rate update, recorded as the `platform.exchange-rates` automation task.
 *
 * Without platform.rates_update_time it runs inside the daily pipeline. With a time set, the pipeline records
 * "skipped" and the `platform:update-exchange-rates` command, scheduled at that time, does the fetch and replaces
 * the run record; a second run for the same date is a no-op.
 */
class ExchangeRateTask
{
    public const KEY = 'platform.exchange-rates';

    public function __construct(private Settings $settings, private UpdateExchangeRates $update) {}

    /** "HH:MM" when rates are fetched at their own time, else null. */
    public function separateTime(): ?string
    {
        $time = (string) rescue(fn (): mixed => $this->settings->get('platform.rates_update_time'), '', report: false);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : null;
    }

    /**
     * When rates are next fetched: the separate time, else the daily automation time (server time).
     */
    public function nextRun(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $time = $this->separateTime()
            ?? (string) rescue(fn (): mixed => $this->settings->get('core.automation_time', '03:00'), '03:00', report: false);
        [$hour, $minute] = preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) === 1 ? [(int) $parts[1], (int) $parts[2]] : [3, 0];
        $next = $now->setTime($hour, $minute);

        return $next->lte($now) ? $next->addDay() : $next;
    }

    /**
     * The pipeline handler.
     */
    public function pipeline(): string
    {
        $time = $this->separateTime();

        return $time !== null ? "skipped: rates update at {$time}" : $this->fetch();
    }

    /**
     * The dedicated scheduled run.
     *
     * @return array{status: string, summary: string}
     */
    public function scheduled(CarbonImmutable $date, bool $force = false): array
    {
        $run = AutomationRun::query()->whereDate('run_date', $date)->where('task', self::KEY)->first()
            ?? new AutomationRun(['run_date' => $date, 'task' => self::KEY]);

        if ($run->exists && $run->status === 'success' && ! str_starts_with((string) $run->summary, 'skipped') && ! $force) {
            return ['status' => 'done', 'summary' => 'already done'];
        }

        $started = hrtime(true);

        try {
            $run->fill(['status' => 'success', 'summary' => $this->fetch()]);
        } catch (Throwable $exception) {
            report($exception);
            $run->fill(['status' => 'failed', 'summary' => mb_substr($exception->getMessage(), 0, 255)]);
        }

        $run->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $run->save();

        return ['status' => (string) $run->status, 'summary' => (string) $run->summary];
    }

    /**
     * @throws ExchangeRateUnavailable when any currency failed (after applying the others)
     */
    private function fetch(): string
    {
        $result = $this->update->handle();
        $summary = sprintf('%d updated, %d unchanged, %d held for review', count($result['updated']), count($result['unchanged']), count($result['held']));

        if ($result['failed'] !== []) {
            throw new ExchangeRateUnavailable($summary.'; failed: '.implode('; ', array_map(
                fn (string $code, string $error): string => "{$code}: {$error}",
                array_keys($result['failed']),
                $result['failed'],
            )));
        }

        return $summary;
    }
}
