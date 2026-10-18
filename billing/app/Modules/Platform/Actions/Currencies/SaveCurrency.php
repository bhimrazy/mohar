<?php

namespace App\Modules\Platform\Actions\Currencies;

use App\Contracts\Billing\InvoiceQueries;
use App\Enums\PriceRounding;
use App\Events\Core\ExchangeRatesUpdated;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\ExchangeRateSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCurrency
{
    public function __construct(
        private ActivityLogger $activity,
        private InvoiceQueries $invoices,
        private ApplyExchangeRate $apply,
    ) {}

    /**
     * Create a currency (with its starting rate) or save its display settings. Rates of existing currencies
     * change only through updateRates(), which is behind the sensitive core.currencies.rates permission.
     *
     * @param  array{code: string, name: string, prefix: ?string, suffix: ?string, decimals: int, format: string, exchange_rate?: string|float|int|null}  $data
     */
    public function handle(?Currency $currency, array $data): Currency
    {
        $creating = $currency === null;
        $currency ??= new Currency(['is_default' => false]);
        $before = $currency->exists ? $currency->only(['code', 'name', 'prefix', 'suffix', 'format']) : [];

        $currency->fill([
            'code' => $data['code'],
            'name' => $data['name'],
            'prefix' => $data['prefix'] ?? '',
            'suffix' => $data['suffix'] ?? '',
            'decimals' => $data['decimals'],
            'format' => $data['format'],
        ]);

        if ($creating) {
            $isFirst = ! Currency::query()->exists();
            $currency->is_default = $isFirst;
            $currency->exchange_rate = $isFirst ? '1' : Decimal::round((string) ($data['exchange_rate'] ?? '1'), 6);
            $currency->rate_updated_at = now();
        }

        DB::transaction(function () use ($currency, $creating, $before): void {
            $currency->save();

            if ($creating) {
                $this->apply->seedHistory($currency);
            }

            $this->activity->log(
                $creating ? "Currency {$currency->code} created" : "Currency {$currency->code} updated",
                $currency,
                properties: $creating
                    ? ['currency' => $currency->code, 'rate' => $currency->exchange_rate]
                    : ['old' => $before, 'new' => $currency->only(['code', 'name', 'prefix', 'suffix', 'format'])],
                event: $creating ? 'currency.created' : 'currency.updated',
            );
        });

        return $currency;
    }

    /**
     * Rate source, manual rate and price-conversion rules (sensitive: they move converted prices).
     *
     * @param  array{rate_source: string, exchange_rate: ?string, price_rounding: string, price_buffer_percent: string|int|float|null}  $data
     */
    public function updateRates(Currency $currency, array $data): bool
    {
        $before = $currency->only(['rate_source', 'price_rounding', 'price_buffer_percent']);
        $manual = $data['rate_source'] === ExchangeRateSources::MANUAL;

        $changed = DB::transaction(function () use ($currency, $data, $manual, $before): bool {
            $currency->fill([
                'rate_source' => $currency->is_default ? ExchangeRateSources::MANUAL : $data['rate_source'],
                'is_auto_rate' => ! $currency->is_default && ! $manual,
                'price_rounding' => PriceRounding::from($data['price_rounding']),
                'price_buffer_percent' => Decimal::round((string) ($data['price_buffer_percent'] ?? '0'), 2),
            ]);

            if ($currency->isDirty()) {
                $currency->save();
                $this->activity->log("Currency {$currency->code} rate settings updated", $currency, properties: [
                    'old' => $before,
                    'new' => $currency->only(['rate_source', 'price_rounding', 'price_buffer_percent']),
                ], event: 'currency.rate_settings_updated');
            }

            $this->apply->seedHistory($currency);

            return ! $currency->is_default && $manual && $data['exchange_rate'] !== null
                && $this->apply->handle($currency, $data['exchange_rate'], ExchangeRateSources::MANUAL);
        });

        if ($changed) {
            ExchangeRatesUpdated::dispatch([$currency->code], ExchangeRateSources::MANUAL);
        }

        return $changed;
    }

    /**
     * Make the currency the default. Other rates are rebased (exactly, to six decimals) so that
     * 1 new default = rate units.
     *
     * @throws ValidationException when invoices or transactions already exist
     */
    public function makeDefault(Currency $currency): void
    {
        if ($currency->is_default) {
            return;
        }

        // Changing the default rebases every rate, so any invoice, quote or transaction in any currency locks it.
        $locked = Currency::query()->orderBy('code')->pluck('code')
            ->contains(fn (string $code): bool => $this->invoices->hasDocumentsInCurrency($code));

        if ($locked) {
            throw ValidationException::withMessages(['currency' => __('The default currency cannot change once invoices or transactions exist.')]);
        }

        DB::transaction(function () use ($currency): void {
            $base = (string) $currency->exchange_rate;

            Currency::query()->whereKeyNot($currency->id)->orderBy('id')->get()->each(function (Currency $other) use ($base): void {
                $this->apply->seedHistory($other);
                $other->forceFill(['is_default' => false])->save();
                $this->apply->handle($other, Decimal::divide((string) $other->exchange_rate, $base, 6), 'rebase');
            });

            $this->apply->seedHistory($currency);
            $currency->forceFill(['is_default' => true, 'rate_source' => ExchangeRateSources::MANUAL, 'is_auto_rate' => false])->save();
            $this->apply->handle($currency, '1', 'rebase');
        });

        $this->activity->log("Default currency changed to {$currency->code}", $currency, event: 'currency.default_changed');
    }

    /**
     * @throws ValidationException when the currency is the default or used by clients
     */
    public function delete(Currency $currency): void
    {
        if ($currency->is_default) {
            throw ValidationException::withMessages(['currency' => __('The default currency cannot be deleted.')]);
        }

        if (Client::query()->where('currency', $currency->code)->exists()) {
            throw ValidationException::withMessages(['currency' => __('Clients use this currency. Move them to another currency first.')]);
        }

        $currency->delete();
        $this->activity->log("Currency {$currency->code} deleted", event: 'currency.deleted');
    }
}
