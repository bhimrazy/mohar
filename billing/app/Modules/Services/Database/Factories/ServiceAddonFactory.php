<?php

namespace App\Modules\Services\Database\Factories;

use App\Enums\BillingCycle;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceAddon>
 */
class ServiceAddonFactory extends Factory
{
    protected $model = ServiceAddon::class;

    public function definition(): array
    {
        $nextDue = CarbonImmutable::today()->addMonth();

        return [
            'service_id' => Service::factory(),
            'addon_id' => fake()->numberBetween(1, 5),
            'name' => fake()->randomElement(['Daily backups', 'Dedicated IP', 'SSL certificate', 'Priority support']),
            'status' => ServiceStatus::Active,
            'cycle' => BillingCycle::Monthly,
            'currency' => 'USD',
            'setup_fee' => 0,
            'recurring_amount' => 300,
            'registration_date' => CarbonImmutable::today()->subMonth(),
            'billing_anchor_day' => $nextDue->day,
            'next_due_date' => $nextDue,
            'next_invoice_date' => $nextDue,
            'activated_at' => CarbonImmutable::now()->subMonth(),
        ];
    }

    public function dueOn(string|CarbonImmutable $nextDue): static
    {
        $date = CarbonImmutable::parse($nextDue);

        return $this->state(fn (): array => ['next_due_date' => $date, 'next_invoice_date' => $date, 'billing_anchor_day' => $date->day]);
    }
}
