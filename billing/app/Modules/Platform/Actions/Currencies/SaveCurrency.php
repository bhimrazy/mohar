<?php

namespace App\Modules\Platform\Actions\Currencies;

use App\Contracts\Billing\InvoiceQueries;
use App\Enums\PriceRounding;
use App\Events\Core\ExchangeRatesUpdated;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\ExchangeRateHistory;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Modules\Platform\Support\ExchangeRateLock;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Currencies\PriceConverters;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCurrency
{
    public function __construct(
        private ActivityLogger $activity,
        private InvoiceQueries $invoices,
        private ApplyExchangeRate $apply,
        private PriceConverters $converters,
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
            $currency->exchange_rate = $isFirst ? '1' : Decimal::round((string) ($data['exchange_rate'] ?? '1'), Currency::RATE_SCALE);
            $currency->rate_updated_at = now();
        }

        try {
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
        } catch (UniqueConstraintViolationException $exception) {
            // Two "first" currencies saved at once would both be the default; a duplicate code is validated earlier.
            if ($currency->is_default) {
                throw self::defaultConflict();
            }

            throw $exception;
        }

        return $currency;
    }

    private static function defaultConflict(): ValidationException
    {
        return ValidationException::withMessages(['currency' => __('Another default currency was just saved; reload and try again.')]);
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

        $changed = ExchangeRateLock::run(fn (): bool => DB::transaction(function () use ($currency, $data, $manual, $before): bool {
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
        }));

        if ($changed) {
            ExchangeRatesUpdated::dispatch([$currency->code], ExchangeRateSources::MANUAL);
        }

        return $changed;
    }

    /**
     * Make the currency the default. Other rates are rebased (exactly, to twelve decimals) so that
     * 1 new default = rate units.
     *
     * @throws ValidationException when invoices or transactions already exist
     */
    public function makeDefault(Currency $currency): void
    {
        if ($currency->is_default) {
            return;
        }

        if ($this->documentsExist()) {
            throw ValidationException::withMessages(['currency' => __('The default currency cannot change once invoices or transactions exist.')]);
        }

        $previous = Currency::default()->code;

        $rebased = ExchangeRateLock::run(fn (): array => $this->rebaseOnto($currency));

        $currency->refresh();
        $this->activity->log("Default currency changed to {$currency->code}", $currency, properties: ['old' => $previous, 'new' => $currency->code], event: 'currency.default_changed');
        ExchangeRatesUpdated::dispatch($rebased, 'rebase');
    }

    /**
     * @return list<string> codes whose rate changed
     *
     * @throws ValidationException when another default was saved meanwhile
     */
    private function rebaseOnto(Currency $currency): array
    {
        try {
            return DB::transaction(function () use ($currency): array {
                $currency = Currency::query()->whereKey($currency->id)->lockForUpdate()->firstOrFail();
                $base = (string) $currency->exchange_rate;
                $codes = [];

                Currency::query()->whereKeyNot($currency->id)->orderBy('id')->lockForUpdate()->get()->each(function (Currency $other) use ($base, &$codes): void {
                    $this->apply->seedHistory($other);
                    $other->forceFill(['is_default' => false])->save();
                    $this->apply->handle($other, Decimal::divide((string) $other->exchange_rate, $base, Currency::RATE_SCALE), 'rebase');
                    $codes[] = $other->code;
                });

                $this->apply->seedHistory($currency);
                $currency->forceFill(['is_default' => true, 'rate_source' => ExchangeRateSources::MANUAL, 'is_auto_rate' => false])->save();
                $this->apply->handle($currency, '1', 'rebase');

                // Proposals were computed against the old default.
                ExchangeRateReview::query()->whereNotNull('pending_currency')->update(['status' => ExchangeRateReview::SUPERSEDED, 'pending_currency' => null]);

                return [...$codes, $currency->code];
            });
        } catch (UniqueConstraintViolationException) {
            // Another default was saved meanwhile (currencies_single_default_unique allows only one).
            throw self::defaultConflict();
        }
    }

    /**
     * @throws ValidationException when the currency is the default, or clients, documents or prices use it
     */
    /**
     * Changing the default rebases every rate, so any invoice, quote or transaction in any currency locks it.
     */
    public function documentsExist(): bool
    {
        return Currency::query()->orderBy('code')->pluck('code')
            ->contains(fn (string $code): bool => $this->invoices->hasDocumentsInCurrency($code));
    }

    /**
     * Why this currency can't become the default (short, for a disabled action), or null.
     */
    public function defaultBlocker(Currency $currency, bool $documentsExist): ?string
    {
        return match (true) {
            $currency->is_default => __('Already the default'),
            $documentsExist => __('Not possible once invoices or payments exist'),
            default => null,
        };
    }

    /**
     * Why this currency can't be deleted (short, for a disabled action), or null.
     */
    public function deleteBlocker(Currency $currency, int $clients): ?string
    {
        if ($currency->is_default) {
            return __('The default currency can\'t be deleted');
        }

        if ($clients > 0) {
            return trans_choice('{1} In use by 1 client|[2,*] In use by :count clients', $clients);
        }

        if ($this->invoices->hasDocumentsInCurrency($currency->code)) {
            return __('Used by invoices or payments');
        }

        foreach ($this->converters->all() as $converter) {
            if ($converter->usesCurrency($currency->code)) {
                return __('Has prices: :prices', ['prices' => $converter->label()]);
            }
        }

        return null;
    }

    public function delete(Currency $currency): void
    {
        if ($currency->is_default) {
            throw ValidationException::withMessages(['currency' => __('The default currency cannot be deleted.')]);
        }

        if (Client::query()->where('currency', $currency->code)->exists()) {
            throw ValidationException::withMessages(['currency' => __('Clients use this currency. Move them to another currency first.')]);
        }

        if ($this->invoices->hasDocumentsInCurrency($currency->code)) {
            throw ValidationException::withMessages(['currency' => __('Invoices, quotes or transactions use this currency, so it cannot be deleted.')]);
        }

        foreach ($this->converters->all() as $converter) {
            if ($converter->usesCurrency($currency->code)) {
                throw ValidationException::withMessages(['currency' => __(':prices have prices in this currency. Remove those prices before deleting it.', ['prices' => $converter->label()])]);
            }
        }

        DB::transaction(function () use ($currency): void {
            // A currency created later with the same code starts a fresh rate history.
            ExchangeRateHistory::query()->where('currency', $currency->code)->delete();
            ExchangeRateReview::closePending($currency->code);
            $currency->delete();
        });
        $this->activity->log("Currency {$currency->code} deleted", properties: ['currency' => $currency->code, 'rate' => $currency->exchange_rate], event: 'currency.deleted');
    }
}
