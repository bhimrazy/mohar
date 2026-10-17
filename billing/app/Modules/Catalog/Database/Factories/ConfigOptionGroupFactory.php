<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Models\ConfigOptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfigOptionGroup>
 */
class ConfigOptionGroupFactory extends Factory
{
    protected $model = ConfigOptionGroup::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->word().' '.fake()->word()).' options',
            'description' => null,
        ];
    }
}
