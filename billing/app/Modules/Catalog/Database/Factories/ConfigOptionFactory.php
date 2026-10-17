<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Enums\ConfigOptionType;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfigOption>
 */
class ConfigOptionFactory extends Factory
{
    protected $model = ConfigOption::class;

    public function definition(): array
    {
        return [
            'config_option_group_id' => ConfigOptionGroup::factory(),
            'name' => ucfirst(fake()->word()),
            'module_key' => fake()->slug(1),
            'type' => ConfigOptionType::Dropdown,
            'quantity_min' => null,
            'quantity_max' => null,
            'quantity_step' => 1,
            'is_hidden' => false,
            'is_required' => false,
            'sort_order' => 0,
        ];
    }

    public function quantity(?int $min = null, ?int $max = null, int $step = 1): static
    {
        return $this->state(['type' => ConfigOptionType::Quantity, 'quantity_min' => $min, 'quantity_max' => $max, 'quantity_step' => $step]);
    }

    public function yesNo(): static
    {
        return $this->state(['type' => ConfigOptionType::YesNo]);
    }

    public function hidden(): static
    {
        return $this->state(['is_hidden' => true]);
    }
}
