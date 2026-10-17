<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Price;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

/**
 * Regenerate every non-default-currency price row from the default currency × exchange rate,
 * rounded half-up to the minor unit. Idempotent for unchanged rates.
 */
class ConvertPrices
{
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

        return $written;
    }
}
