<?php

namespace App\Support\Currencies;

use App\Contracts\Currencies\PriceConverter;

/**
 * Registry of modules whose prices are converted from the default currency (Catalog products, Domains TLDs).
 * Admin → Currencies → Converted prices previews and applies all of them; an empty registry is valid.
 *
 *   app(PriceConverters::class)->register(CatalogPriceConverter::class);
 *
 * @api
 */
class PriceConverters
{
    /** @var array<string, class-string<PriceConverter>> */
    private array $classes = [];

    /**
     * @param  class-string<PriceConverter>  $class
     */
    public function register(string $class): void
    {
        $this->classes[$class] = $class;
    }

    /**
     * @return list<PriceConverter>
     */
    public function all(): array
    {
        return array_values(array_map(fn (string $class): PriceConverter => app($class), $this->classes));
    }

    public function get(string $key): ?PriceConverter
    {
        foreach ($this->all() as $converter) {
            if ($converter->key() === $key) {
                return $converter;
            }
        }

        return null;
    }
}
