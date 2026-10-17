<?php

namespace App\Modules\Core\Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company_name' => fake()->boolean(40) ? fake()->company() : null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'address_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(['CA', 'NY', 'TX', 'WA', 'FL']),
            'postcode' => fake()->postcode(),
            'country' => 'US',
            'tax_exempt' => false,
            'currency' => 'USD',
            'language' => 'en',
            'status' => 'active',
        ];
    }

    /**
     * Attach a login user as the account owner.
     */
    public function withOwner(?User $user = null): static
    {
        return $this->afterCreating(function (Client $client) use ($user): void {
            $client->users()->attach($user ?? User::factory()->create(['email' => $client->email]), ['is_owner' => true]);
        });
    }
}
