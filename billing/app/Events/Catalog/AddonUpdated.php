<?php

namespace App\Events\Catalog;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product addon's details or prices changed.
 */
final class AddonUpdated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $addonId,
    ) {}
}
