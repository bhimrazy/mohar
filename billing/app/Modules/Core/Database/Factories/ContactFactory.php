<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'email_preferences' => array_fill_keys(Contact::EMAIL_TYPES, true),
        ];
    }
}
