<?php

namespace App\Modules\Support\Database\Factories;

use App\Models\User;
use App\Modules\Support\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Technical Support', 'Billing', 'Sales', 'Abuse', 'Domains', 'Migrations', 'Partners', 'Security']).' '.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'email' => Str::slug($name).'@support.example.test',
            'is_hidden' => false,
            'clients_only' => false,
            'replies_only_by_email' => false,
            'send_autoresponse' => true,
            'prevent_client_close' => false,
            'sort_order' => 0,
        ];
    }

    public function clientsOnly(): static
    {
        return $this->state(['clients_only' => true]);
    }

    public function hidden(): static
    {
        return $this->state(['is_hidden' => true]);
    }

    /**
     * @param  list<User>  $staff
     */
    public function withStaff(array $staff): static
    {
        return $this->afterCreating(fn (Department $department) => $department->staff()->attach(array_map(fn (User $user): int => $user->id, $staff)));
    }
}
