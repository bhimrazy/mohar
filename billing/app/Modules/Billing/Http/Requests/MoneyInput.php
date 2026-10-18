<?php

namespace App\Modules\Billing\Http\Requests;

use App\Support\Money;

/**
 * Forms send decimal amounts ("40.00"); the server converts them to minor units.
 */
trait MoneyInput
{
    /** Positive decimal with at most two places. */
    public const AMOUNT = 'regex:/^\d{1,10}(\.\d{1,2})?$/';

    /** Signed decimal (discount lines). */
    public const SIGNED_AMOUNT = 'regex:/^-?\d{1,10}(\.\d{1,2})?$/';

    public function minor(string $key, string $currency = 'USD'): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? Money::fromDecimal((string) $value, $currency)->amount : 0;
    }

    public static function toMinor(mixed $value): int
    {
        return is_numeric($value) ? Money::fromDecimal((string) $value, 'USD')->amount : 0;
    }
}
