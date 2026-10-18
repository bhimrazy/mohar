<?php

namespace App\Modules\Domains\Actions;

use App\Contracts\Currencies\PriceConverter;
use App\Data\Currencies\PriceChange;
use App\Data\Currencies\PriceConversionPreview;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Models\TldPrice;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Regenerate TLD prices in other currencies from the default-currency rows (base and client-group slabs),
 * with each currency's buffer and rounding (Currency::priceFromDefault()). Rows staff set manually are
 * skipped; converted rows whose default-currency source is gone are removed. Registered domains keep their
 * stored recurring amounts.
 */
class ConvertTldPrices implements PriceConverter
{
    public function __construct(private ActivityLogger $activity, private Settings $settings) {}

    public function key(): string
    {
        return 'domains';
    }

    public function label(): string
    {
        return 'Domain (TLD) prices';
    }

    public function automatic(): bool
    {
        return (bool) $this->settings->get('domains.auto_convert_prices', false);
    }

    public function apply(): int
    {
        $written = 0;
        $removed = 0;

        DB::transaction(function () use (&$written, &$removed): void {
            $written = $this->plan(function (?TldPrice $existing, TldPrice $source, Currency $currency, array $amounts): void {
                if ($existing === null) {
                    TldPrice::query()->create(['tld_id' => $source->tld_id, 'currency' => $currency->code, 'client_group_id' => $source->client_group_id, 'years' => $source->years, ...$amounts]);
                } else {
                    $existing->update($amounts);
                }
            });

            $removed = $this->orphans()->delete();
        });

        $this->activity->log('TLD prices converted from the default currency', properties: ['written' => $written, 'removed' => $removed, 'currency' => Currency::default()->code]);

        return $written + $removed;
    }

    public function preview(int $samples = 10): PriceConversionPreview
    {
        $sampled = [];
        $unchanged = 0;
        $manual = 0;

        $changes = $this->plan(function (?TldPrice $existing, TldPrice $source, Currency $currency, array $amounts) use (&$sampled, $samples): void {
            if (count($sampled) < $samples) {
                $source->loadMissing('tld');
                $sampled[] = new PriceChange(
                    $source->tld->extension.' register'.($source->client_group_id !== null ? ' (group)' : ''),
                    $currency->code,
                    trans_choice('{1} 1 year|[2,*] :count years', $source->years),
                    $existing?->register,
                    (int) ($amounts['register'] ?? $amounts['renew'] ?? 0),
                );
            }
        }, $unchanged, $manual);

        return new PriceConversionPreview($this->key(), $this->label(), $changes + $this->orphans()->count(), $unchanged, $manual, $sampled, $this->automatic());
    }

    /**
     * @param  Closure(?TldPrice, TldPrice, Currency, array{register: int|null, transfer: int|null, renew: int|null}): void  $change
     */
    private function plan(Closure $change, int &$unchanged = 0, int &$manual = 0): int
    {
        $default = Currency::default();
        $targets = Currency::query()->where('code', '!=', $default->code)->orderBy('code')->orderBy('id')->get();

        if ($targets->isEmpty()) {
            return 0;
        }

        $existing = TldPrice::query()->where('currency', '!=', $default->code)->get()
            ->keyBy(fn (TldPrice $price): string => $this->rowKey($price->tld_id, $price->currency, $price->client_group_id, $price->years));
        $changes = 0;

        TldPrice::query()->where('currency', $default->code)->orderBy('id')->each(function (TldPrice $source) use ($targets, $existing, $change, &$changes, &$unchanged, &$manual): void {
            foreach ($targets as $currency) {
                /** @var TldPrice|null $row */
                $row = $existing->get($this->rowKey($source->tld_id, $currency->code, $source->client_group_id, $source->years));

                if ($row !== null && $row->is_manual) {
                    $manual++;

                    continue;
                }

                $amounts = [];

                foreach (TldPrice::ACTIONS as $action) {
                    $amount = $source->amountFor($action);
                    $amounts[$action] = $amount === null ? null : $currency->priceFromDefault($amount);
                }

                if ($row !== null && $row->register === $amounts['register'] && $row->transfer === $amounts['transfer'] && $row->renew === $amounts['renew']) {
                    $unchanged++;

                    continue;
                }

                $change($row, $source, $currency, $amounts);
                $changes++;
            }
        });

        return $changes;
    }

    private function rowKey(int $tldId, string $currency, ?int $groupId, int $years): string
    {
        return "{$tldId}|{$currency}|".($groupId ?? 'base')."|{$years}";
    }

    /**
     * Non-manual converted rows without a default-currency row for the same TLD, slab and term.
     *
     * @return Builder<TldPrice>
     */
    private function orphans(): Builder
    {
        $default = Currency::default()->code;

        return TldPrice::query()
            ->where('currency', '!=', $default)
            ->where('is_manual', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('tld_prices as source')
                ->whereColumn('source.tld_id', 'tld_prices.tld_id')
                ->whereColumn('source.years', 'tld_prices.years')
                ->where(fn ($slab) => $slab->whereColumn('source.client_group_id', 'tld_prices.client_group_id')
                    ->orWhere(fn ($both) => $both->whereNull('source.client_group_id')->whereNull('tld_prices.client_group_id')))
                ->where('source.currency', $default));
    }
}
