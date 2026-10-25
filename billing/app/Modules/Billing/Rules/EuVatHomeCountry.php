<?php

namespace App\Modules\Billing\Rules;

use App\Support\Countries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The VAT home country is optional while EU VAT rules are off, and an EU member state while they are on (reverse
 * charge compares customers with it).
 */
class EuVatHomeCountry implements ValidationRule
{
    /** Runs on an empty value too. */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! request()->boolean('billing.vat.enabled')) {
            return;
        }

        if (! is_string($value) || ! in_array(Countries::normalize($value), Countries::EU, true)) {
            $fail(__('Choose an EU country for the :attribute while EU VAT rules are on.'));
        }
    }
}
