<?php

namespace App\Modules\Services\Database\Factories;

use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Models\ServerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerGroup>
 */
class ServerGroupFactory extends Factory
{
    protected $model = ServerGroup::class;

    public function definition(): array
    {
        return ['name' => fake()->words(2, true).' servers', 'fill_type' => FillType::LeastFull];
    }
}
