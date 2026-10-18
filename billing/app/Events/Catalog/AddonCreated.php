<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product addon was added to the catalog.
 */
final class AddonCreated
{
    use Dispatchable;

    public function __construct(
        public int $addonId,
    ) {}
}
