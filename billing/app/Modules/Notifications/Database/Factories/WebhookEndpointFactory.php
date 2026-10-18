<?php

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Notifications\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'url' => 'https://hooks.example.com/'.Str::lower(Str::random(8)),
            'description' => fake()->sentence(3),
            'secret' => 'whsec_'.Str::random(40),
            'events' => ['*'],
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }
}
