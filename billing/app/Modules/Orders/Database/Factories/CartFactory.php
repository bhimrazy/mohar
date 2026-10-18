<?php

namespace App\Modules\Orders\Database\Factories;

use App\Modules\Orders\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'token' => (string) Str::uuid(),
            'client_id' => null,
            'currency' => 'USD',
            'promotion_code' => null,
            'items' => [],
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
