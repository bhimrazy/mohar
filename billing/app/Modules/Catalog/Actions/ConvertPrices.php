<?php

namespace App\Modules\Catalog\Actions;

use App\Contracts\Currencies\PriceConverter;
use App\Data\Currencies\PriceChange;
use App\Data\Currencies\PriceConversionPreview;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CyclePicker;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Regenerate non-default-currency price rows from the default currency: exchange rate, the currency's FX
 * buffer and rounding rule (Currency::priceFromDefault()). Rows staff set manually are skipped, and enabled
 * converted rows whose default-currency source is gone are turned off. Only rows that change are written,
 * so a run with unchanged rates writes nothing. Existing services keep their stored recurring amounts.
 */
class ConvertPrices implements PriceConverter
{
    private const CHUNK = 500;

    public function __construct(private ActivityLogger $activity, private Settings $settings) {}

    public function key(): string
    {
        return 'catalog';
    }

    public function label(): string
    {
        return 'Products, addons and configurable options';
    }

    public function automatic(): bool
    {
        return (bool) $this->settings->get('catalog.auto_convert_prices', false);
    }

    /**
     * @return int number of price rows created or changed
     */
    public function handle(): int
    {
        return $this->apply();
    }

    public function apply(): int
    {
        $written = 0;
        $disabled = 0;

        DB::transaction(function () use (&$written, &$disabled): void {
            $written = $this->plan(function (?Price $existing, Price $source, Currency $currency, int $amount, int $setupFee): void {
                $attributes = ['enabled' => $source->enabled, 'amount' => $amount, 'setup_fee' => $setupFee];

                if ($existing === null) {
                    Price::query()->create(['priceable_type' => $source->priceable_type, 'priceable_id' => $source->priceable_id, 'currency' => $currency->code, 'cycle' => $source->cycle, ...$attributes]);
                } else {
                    $existing->update($attributes);
                }
            });

            $disabled = $this->orphans()->update(['enabled' => false]);
        });

        $this->activity->log('Prices converted from the default currency', null, null, ['written' => $written, 'disabled' => $disabled, 'currency' => Currency::default()->code]);

        return $written + $disabled;
    }

    public function preview(int $samples = 10): PriceConversionPreview
    {
        $sampled = [];
        $unchanged = 0;
        $manual = 0;

        $changes = $this->plan(function (?Price $existing, Price $source, Currency $currency, int $amount) use (&$sampled, $samples): void {
            if (count($sampled) < $samples) {
                $sampled[] = [$source, $currency->code, $existing?->amount, $amount];
            }
        }, $unchanged, $manual);

        $changes += $this->orphans()->count();

        return new PriceConversionPreview(
            $this->key(),
            $this->label(),
            $changes,
            $unchanged,
            $manual,
            array_map(fn (array $sample): PriceChange => new PriceChange(
                $this->itemLabel($sample[0]),
                $sample[1],
                CyclePicker::label($sample[0]->cycle),
                $sample[2],
                $sample[3],
            ), $sampled),
            $this->automatic(),
        );
    }

    /**
     * Walk every default-currency price × target currency and call $change for rows that would change.
     *
     * @param  Closure(?Price, Price, Currency, int, int): void  $change
     * @return int number of rows that would change
     */
    private function plan(Closure $change, int &$unchanged = 0, int &$manual = 0): int
    {
        $default = Currency::default();
        $targets = Currency::query()->where('code', '!=', $default->code)->orderBy('code')->orderBy('id')->get();

        if ($targets->isEmpty()) {
            return 0;
        }

        $changes = 0;

        Price::query()->where('currency', $default->code)->orderBy('id')->chunkById(self::CHUNK, function (Collection $sources) use ($targets, $change, &$changes, &$unchanged, &$manual): void {
            $existing = $this->existingFor($sources, $targets->pluck('code')->all());

            /** @var Price $source */
            foreach ($sources as $source) {
                foreach ($targets as $currency) {
                    $row = $existing[$this->rowKey($source->priceable_type, $source->priceable_id, $currency->code, $source->cycle->value)] ?? null;

                    if ($row !== null && $row->is_manual) {
                        $manual++;

                        continue;
                    }

                    $amount = $currency->priceFromDefault($source->amount);
                    $setupFee = $currency->priceFromDefault($source->setup_fee);

                    if ($row !== null && $row->amount === $amount && $row->setup_fee === $setupFee && $row->enabled === $source->enabled) {
                        $unchanged++;

                        continue;
                    }

                    $change($row, $source, $currency, $amount, $setupFee);
                    $changes++;
                }
            }
        });

        return $changes;
    }

    /**
     * Existing target-currency rows for a chunk of sources, keyed by priceable, currency and cycle.
     *
     * @param  Collection<int, Price>  $sources
     * @param  list<string>  $currencies
     * @return array<string, Price>
     */
    private function existingFor(Collection $sources, array $currencies): array
    {
        $rows = [];

        foreach ($sources->groupBy('priceable_type') as $type => $group) {
            Price::query()->where('priceable_type', $type)
                ->whereIn('priceable_id', $group->pluck('priceable_id')->unique()->values()->all())
                ->whereIn('currency', $currencies)
                ->get()
                ->each(function (Price $price) use (&$rows): void {
                    $rows[$this->rowKey($price->priceable_type, $price->priceable_id, $price->currency, $price->cycle->value)] = $price;
                });
        }

        return $rows;
    }

    private function rowKey(string $type, int $id, string $currency, string $cycle): string
    {
        return "{$type}|{$id}|{$currency}|{$cycle}";
    }

    /**
     * Enabled converted rows (not manual) whose default-currency source row no longer exists.
     *
     * @return Builder<Price>
     */
    private function orphans(): Builder
    {
        $default = Currency::default()->code;

        return Price::query()
            ->where('currency', '!=', $default)
            ->where('enabled', true)
            ->where('is_manual', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('prices as source')
                ->whereColumn('source.priceable_type', 'prices.priceable_type')
                ->whereColumn('source.priceable_id', 'prices.priceable_id')
                ->whereColumn('source.cycle', 'prices.cycle')
                ->where('source.currency', $default));
    }

    private function itemLabel(Price $price): string
    {
        $model = $price->priceable;

        return match (true) {
            $model instanceof Product, $model instanceof Addon => $model->name,
            $model instanceof ConfigOptionValue => $model->option->name.': '.$model->label,
            $model instanceof Model => class_basename($model).' #'.$model->getKey(),
            default => '#'.$price->priceable_id,
        };
    }
}
