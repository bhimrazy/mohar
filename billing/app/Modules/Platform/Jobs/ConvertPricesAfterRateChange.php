<?php

namespace App\Modules\Platform\Jobs;

use App\Contracts\Currencies\PriceConverter;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Currencies\PriceConverters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * After exchange rates change, regenerate converted prices for every module whose automatic conversion is on.
 * Queued after commit and unique per currency set until it starts: overlapping rate changes waiting in the queue
 * convert once, while a change arriving during a running conversion queues a new one (it is never dropped).
 * A module's failure never fails the staff member's rate save. The daily convert-prices tasks remain the backstop.
 */
class ConvertPricesAfterRateChange implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 600;

    /**
     * @param  list<string>  $currencies  ISO codes whose rate changed
     */
    public function __construct(public array $currencies)
    {
        $codes = array_values(array_unique(array_map('strtoupper', $currencies)));
        sort($codes);
        $this->currencies = $codes;
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return implode(',', $this->currencies);
    }

    public function handle(PriceConverters $converters, ActivityLogger $activity): void
    {
        foreach ($converters->all() as $converter) {
            if (! $converter->automatic()) {
                continue;
            }

            $this->convert($converter, $activity);
        }
    }

    /**
     * One module failing must not stop the others; the failure is reported and audited, and the daily
     * convert-prices task retries it.
     */
    private function convert(PriceConverter $converter, ActivityLogger $activity): void
    {
        try {
            $converter->apply();
        } catch (Throwable $exception) {
            report($exception);
            $activity->log('Automatic price conversion failed', properties: [
                'converter' => $converter->key(),
                'currencies' => $this->currencies,
            ], event: 'currency.prices_conversion_failed');
        }
    }
}
