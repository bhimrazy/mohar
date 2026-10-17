<?php

namespace Tests\Fakes\Domains;

use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\Tld;

/**
 * Common catalog setup for Domains feature tests.
 */
final class DomainFixtures
{
    /**
     * USD default currency + a priced ".com" handled by the given registrar.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function com(string $registrar = 'fake', array $attributes = []): Tld
    {
        if (Currency::query()->where('code', 'USD')->doesntExist()) {
            Currency::factory()->create();
        }

        return Tld::factory()->extension('.com')->priced()->create(['registrar_key' => $registrar, ...$attributes]);
    }
}
