<?php

namespace App\Modules\Themes\Database\Factories;

use App\Modules\Themes\Models\Theme;
use App\Modules\Themes\Support\ThemeManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    protected $model = Theme::class;

    public function definition(): array
    {
        $preset = app(ThemeManager::class)->presetDocument('calm');

        return [
            'key' => 'custom-'.Str::lower(Str::random(8)),
            'name' => fake()->words(2, true),
            'base_preset' => 'calm',
            'tokens' => $preset,
            'version' => 1,
        ];
    }
}
