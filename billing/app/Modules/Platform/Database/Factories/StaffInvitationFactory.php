<?php

namespace App\Modules\Platform\Database\Factories;

use App\Modules\Core\Models\Role;
use App\Modules\Platform\Models\StaffInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffInvitation>
 */
class StaffInvitationFactory extends Factory
{
    protected $model = StaffInvitation::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'role_id' => Role::factory(),
            'token_hash' => StaffInvitation::hashToken(Str::random(40)),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Use a known plain-text token (tests open the accept link with it).
     */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => StaffInvitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
