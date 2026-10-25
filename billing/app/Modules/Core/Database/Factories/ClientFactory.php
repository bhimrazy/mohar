<?php

namespace App\Modules\Core\Database\Factories;

use App\Models\User;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
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
            'origin' => ClientOrigin::Staff->value,
            'email_source' => EmailSource::Staff->value,
        ];
    }

    /**
     * A generated Nepali client for the demo: NPR, a Bagmati or Gandaki address, a +977 mobile number, an
     * example.com address, and a (made-up) buyer PAN when it is a company. Names are generated, never real people.
     */
    public function nepal(): static
    {
        return $this->state(function (): array {
            [$city, $state, $postcode] = fake()->randomElement([
                ['Kathmandu', 'Bagmati', '44600'], ['Lalitpur', 'Bagmati', '44700'], ['Bhaktapur', 'Bagmati', '44800'], ['Pokhara', 'Gandaki', '33700'],
            ]);
            $first = fake()->randomElement(['Aarav', 'Anjali', 'Bikash', 'Dipika', 'Gita', 'Kiran', 'Laxmi', 'Manish', 'Nabin', 'Pooja', 'Rajan', 'Sabina', 'Sunil', 'Trishna']);
            $last = fake()->randomElement(['Shrestha', 'Gurung', 'Tamang', 'Thapa', 'Rai', 'Karki', 'Adhikari', 'Basnet', 'Pandey', 'Maharjan', 'Khadka']);
            $company = fake()->boolean(60);

            return [
                'first_name' => $first,
                'last_name' => $last,
                'company_name' => $company ? $last.' '.fake()->randomElement(['Digital', 'Traders', 'Studio', 'Foods', 'Treks', 'Education']).' Pvt. Ltd.' : null,
                'email' => strtolower($first.'.'.$last).fake()->unique()->numberBetween(10, 9999).'@example.com',
                'phone' => '+977 98'.fake()->numerify('########'),
                'address_1' => fake()->randomElement(['Naya Bazar', 'Jhamsikhel Road', 'Thamel Marg', 'Lakeside Road', 'Kupondole Height']).' '.fake()->numberBetween(1, 40),
                'city' => $city,
                'state' => $state,
                'postcode' => $postcode,
                'country' => 'NP',
                'currency' => 'NPR',
                'tax_id' => $company ? fake()->numerify('3########') : null,
            ];
        });
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
