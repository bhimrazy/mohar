<?php

namespace App\Modules\Support\Database\Factories;

use App\Modules\Support\Models\KbCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KbCategory>
 */
class KbCategoryFactory extends Factory
{
    protected $model = KbCategory::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).' '.fake()->word();

        return ['name' => $name, 'slug' => Str::slug($name), 'description' => fake()->sentence(), 'is_hidden' => false, 'sort_order' => 0];
    }
}
