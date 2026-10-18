<?php

namespace App\Modules\Catalog\Http\Requests\Concerns;

use App\Enums\BillingCycle;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for a price matrix payload: [{currency, cycle, enabled, amount, setup_fee, manual?}] in minor units.
 */
trait ValidatesPriceMatrix
{
    /** Largest accepted amount in minor units (one billion currency units). */
    protected const MAX_AMOUNT = 100_000_000_000;

    /**
     * @param  list<BillingCycle>  $cycles  cycles that may carry a price
     * @return array<string, array<mixed>>
     */
    protected function priceMatrixRules(array $cycles, string $key = 'prices'): array
    {
        return [
            $key => ['present', 'array', 'max:500'],
            "{$key}.*.currency" => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            "{$key}.*.cycle" => ['required', 'string', Rule::in(array_map(fn (BillingCycle $cycle): string => $cycle->value, $cycles))],
            "{$key}.*.enabled" => ['required', 'boolean'],
            "{$key}.*.amount" => ['required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            "{$key}.*.setup_fee" => ['required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            "{$key}.*.manual" => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function priceMatrixMessages(string $key = 'prices'): array
    {
        $setupFee = __('Enter a valid setup fee, such as 5.00, or leave it empty.');

        return [
            "{$key}.*.cycle.in" => __('This billing cycle can\'t be priced for the selected payment type.'),
            "{$key}.*.currency.exists" => __('This currency is not configured.'),
            "{$key}.*.amount.required" => __('Enter a price, or turn this cycle off.'),
            "{$key}.*.amount.integer" => __('Enter a valid price, such as 12.50.'),
            "{$key}.*.amount.min" => __('Prices can\'t be negative.'),
            "{$key}.*.amount.max" => __('This price is too large.'),
            "{$key}.*.setup_fee.required" => $setupFee,
            "{$key}.*.setup_fee.integer" => $setupFee,
            "{$key}.*.setup_fee.min" => __('Setup fees can\'t be negative.'),
            "{$key}.*.setup_fee.max" => __('This setup fee is too large.'),
        ];
    }

    protected function validateDistinctPriceRows(Validator $validator, string $key = 'prices'): void
    {
        $seen = [];

        foreach ((array) $this->input($key, []) as $index => $row) {
            if (! is_array($row) || ! is_string($row['currency'] ?? null) || ! is_string($row['cycle'] ?? null)) {
                continue;
            }

            $pair = strtoupper($row['currency']).'|'.$row['cycle'];

            if (isset($seen[$pair])) {
                $validator->errors()->add("{$key}.{$index}.cycle", __('Each currency and billing cycle can only be priced once.'));
            }

            $seen[$pair] = true;
        }
    }

    /**
     * Enabled (currency, cycle) pairs from the validated payload.
     *
     * @return list<array{currency: string, cycle: string}>
     */
    protected function enabledPricePairs(string $key = 'prices'): array
    {
        $pairs = [];

        foreach ((array) $this->input($key, []) as $row) {
            if (is_array($row) && filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $pairs[] = ['currency' => strtoupper((string) $row['currency']), 'cycle' => (string) $row['cycle']];
            }
        }

        return $pairs;
    }
}
