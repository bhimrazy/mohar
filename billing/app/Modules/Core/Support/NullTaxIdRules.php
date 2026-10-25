<?php

namespace App\Modules\Core\Support;

use App\Contracts\Billing\TaxIdRules;

/**
 * Default when the Billing module is absent: numbers are kept as entered and nothing is checked.
 */
class NullTaxIdRules implements TaxIdRules
{
    public function normalize(string $country, string $taxId): string
    {
        return $taxId;
    }

    public function notes(string $country, ?string $taxId, ?string $state, bool $business): array
    {
        return [];
    }
}
