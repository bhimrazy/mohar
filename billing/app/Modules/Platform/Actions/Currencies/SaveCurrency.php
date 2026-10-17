<?php

namespace App\Modules\Platform\Actions\Currencies;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SaveCurrency
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array{code: string, name: string, prefix: ?string, suffix: ?string, decimals: int, format: string, exchange_rate: string|float|int}  $data
     */
    public function handle(?Currency $currency, array $data): Currency
    {
        $currency ??= new Currency(['is_default' => false]);
        $before = $currency->exists ? $currency->only(['code', 'exchange_rate', 'decimals']) : [];

        $currency->fill([
            ...$data,
            'prefix' => $data['prefix'] ?? '',
            'suffix' => $data['suffix'] ?? '',
            'exchange_rate' => $currency->is_default ? 1 : $data['exchange_rate'],
        ]);

        if ($currency->isDirty('exchange_rate')) {
            $currency->rate_updated_at = now();
        }

        $currency->save();

        $this->activity->log(
            $currency->wasRecentlyCreated ? "Currency {$currency->code} created" : "Currency {$currency->code} updated",
            $currency,
            properties: $before === [] ? [] : ['old' => $before, 'new' => $currency->only(['code', 'exchange_rate', 'decimals'])],
            event: $currency->wasRecentlyCreated ? 'currency.created' : 'currency.updated',
        );

        return $currency;
    }

    /**
     * Make the currency the default. Other rates are rebased so that 1 new default = rate units.
     *
     * @throws ValidationException when invoices or transactions already exist
     */
    public function makeDefault(Currency $currency): void
    {
        if ($currency->is_default) {
            return;
        }

        foreach ((array) config('platform.currency_lock_tables', []) as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw ValidationException::withMessages(['currency' => __('The default currency cannot change once invoices or transactions exist.')]);
            }
        }

        DB::transaction(function () use ($currency): void {
            $base = (float) $currency->exchange_rate;

            Currency::query()->whereKeyNot($currency->id)->get()->each(function (Currency $other) use ($base): void {
                $other->update([
                    'is_default' => false,
                    'exchange_rate' => round((float) $other->exchange_rate / $base, 6),
                    'rate_updated_at' => now(),
                ]);
            });

            $currency->update(['is_default' => true, 'exchange_rate' => 1, 'rate_updated_at' => now()]);
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
