<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'permissions' => ['*'],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(['permissions' => $permissions]);
    }
}
