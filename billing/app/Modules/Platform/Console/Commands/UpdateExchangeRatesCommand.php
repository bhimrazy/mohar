<?php

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Support\ExchangeRateTask;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:update-exchange-rates {--date= : Run date (Y-m-d), defaults to today} {--force : Fetch again although today\'s update succeeded}')]
#[Description('Fetch exchange rates at the separate time set in platform.rates_update_time (recorded as the platform.exchange-rates task)')]
class UpdateExchangeRatesCommand extends Command
{
    public function handle(ExchangeRateTask $task): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay() : CarbonImmutable::today();
        $result = $task->scheduled($date, (bool) $this->option('force'));

        $this->components->twoColumnDetail(ExchangeRateTask::KEY, $result['summary']);

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
