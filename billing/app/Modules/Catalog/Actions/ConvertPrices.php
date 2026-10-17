<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Price;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Regenerate every non-default-currency price row from the default currency × exchange rate,
 * rounded half-up to the minor unit, and turn off converted rows whose default-currency source is gone.
 * Idempotent for unchanged rates; each run is activity-logged.
 */
class ConvertPrices
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @return int number of price rows written
     */
    public function handle(): int
    {
        $default = Currency::default();
        $targets = Currency::query()->where('code', '!=', $default->code)->get();

        if ($targets->isEmpty()) {
            return 0;
        }

        $written = 0;

        Price::query()->where('currency', $default->code)->orderBy('id')->chunkById(500, function ($prices) use ($targets, &$written): void {
            DB::transaction(function () use ($prices, $targets, &$written): void {
                foreach ($prices as $price) {
                    foreach ($targets as $currency) {
                        Price::query()->updateOrCreate(
                            ['priceable_type' => $price->priceable_type, 'priceable_id' => $price->priceable_id, 'currency' => $currency->code, 'cycle' => $price->cycle],
                            ['enabled' => $price->enabled, 'amount' => $currency->fromDefault($price->amount), 'setup_fee' => $currency->fromDefault($price->setup_fee)],
                        );
                        $written++;
                    }
                }
            });
        });

        $orphaned = Price::query()
            ->where('currency', '!=', $default->code)
            ->where('enabled', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('prices as source')
                ->whereColumn('source.priceable_type', 'prices.priceable_type')
                ->whereColumn('source.priceable_id', 'prices.priceable_id')
                ->whereColumn('source.cycle', 'prices.cycle')
                ->where('source.currency', $default->code))
            ->update(['enabled' => false]);

        $this->activity->log('Prices converted from the default currency', null, null, ['written' => $written, 'disabled' => $orphaned, 'currency' => $default->code]);

        return $written;
    }
}
