<?php

namespace App\Events\Catalog;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A catalog price row changed. Existing services keep their stored amounts (never retroactive).
 *
 * $priceableType is product|addon|config_option_value; amounts are minor units, null when the row was
 * added (old) or removed (new).
 */
final class ProductPriceChanged
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public string $priceableType,
        public int $priceableId,
        public string $currency,
        public string $cycle,
        public ?int $oldAmount,
        public ?int $newAmount,
    ) {}
}
