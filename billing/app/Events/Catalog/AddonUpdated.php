<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product addon's details or prices changed.
 */
final class AddonUpdated
{
    use Dispatchable;

    public function __construct(
        public int $addonId,
    ) {}
}
