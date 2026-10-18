<?php

namespace App\Modules\Support\Database\Factories;

use App\Modules\Support\Enums\IncidentPriority;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Enums\IncidentType;
use App\Modules\Support\Models\NetworkIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkIncident>
 */
class NetworkIncidentFactory extends Factory
{
    protected $model = NetworkIncident::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'type' => IncidentType::Server,
            'affected' => 'web'.fake()->numberBetween(1, 20).'.example.net',
            'priority' => IncidentPriority::Medium,
            'status' => IncidentStatus::Investigating,
            'description' => fake()->paragraph(),
            'starts_at' => now()->subHour(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(['status' => IncidentStatus::Resolved, 'ends_at' => now()]);
    }
}
