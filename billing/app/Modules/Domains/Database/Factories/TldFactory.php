<?php

namespace App\Modules\Domains\Database\Factories;

use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tld>
 */
class TldFactory extends Factory
{
    protected $model = Tld::class;

    public function definition(): array
    {
        return [
            'extension' => '.'.fake()->unique()->lexify('???'),
            'registrar_key' => 'log',
            'auto_register' => true,
            'epp_required' => true,
            'min_years' => 1,
            'max_years' => 10,
            'allow_register' => true,
            'allow_transfer' => true,
            'grace_days' => 0,
            'grace_fee' => 0,
            'redemption_days' => 0,
            'redemption_fee' => 0,
            'is_spotlight' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function extension(string $extension): static
    {
        return $this->state(['extension' => Tld::normaliseExtension($extension)]);
    }

    public function manual(): static
    {
        return $this->state(['registrar_key' => null]);
    }

    /**
     * Base prices for every term 1..$years: N × the yearly amounts.
     */
    public function priced(string $currency = 'USD', int $register = 1200, ?int $transfer = 1100, int $renew = 1400, int $years = 10): static
    {
        return $this->afterCreating(function (Tld $tld) use ($currency, $register, $transfer, $renew, $years): void {
            for ($term = 1; $term <= $years; $term++) {
                TldPrice::query()->create([
                    'tld_id' => $tld->id,
                    'currency' => $currency,
                    'years' => $term,
                    'register' => $register * $term,
                    'transfer' => $term === 1 ? $transfer : null,
                    'renew' => $renew * $term,
                ]);
            }
        });
    }
}
