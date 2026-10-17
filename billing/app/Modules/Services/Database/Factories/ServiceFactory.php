<?php

namespace App\Modules\Services\Database\Factories;

use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an active monthly service paid through next month.
 *
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $registered = CarbonImmutable::today()->subMonths(2);
        $nextDue = BillingCycle::Monthly->advance($registered, 3);

        return [
            'client_id' => Client::factory(),
            'product_id' => fake()->numberBetween(1, 5),
            'product_name' => fake()->randomElement(['Starter Hosting', 'Business Hosting', 'Reseller Pro', 'VPS Small']),
            'status' => ServiceStatus::Active,
            'cycle' => BillingCycle::Monthly,
            'currency' => 'USD',
            'quantity' => 1,
            'domain' => fake()->unique()->domainWord().'.'.fake()->randomElement(['com', 'net', 'io']),
            'first_payment_amount' => 1000,
            'recurring_amount' => 1000,
            'registration_date' => $registered,
            'billing_anchor_day' => $registered->day,
            'next_due_date' => $nextDue,
            'next_invoice_date' => $nextDue,
            'activated_at' => $registered,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ServiceStatus::Pending,
            'registration_date' => CarbonImmutable::today(),
            'billing_anchor_day' => CarbonImmutable::today()->day,
            'next_due_date' => CarbonImmutable::today(),
            'next_invoice_date' => BillingCycle::Monthly->advance(CarbonImmutable::today()),
            'activated_at' => null,
            'username' => null,
        ]);
    }

    public function suspended(string $reason = 'overdue'): static
    {
        return $this->state(fn (): array => ['status' => ServiceStatus::Suspended, 'suspended_at' => CarbonImmutable::now(), 'suspension_reason' => $reason]);
    }

    public function status(ServiceStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * Billing dates: paid through (and invoiced through) $nextDue, anchored on its day.
     */
    public function dueOn(string|CarbonImmutable $nextDue, ?int $anchorDay = null): static
    {
        $date = CarbonImmutable::parse($nextDue);

        return $this->state(fn (): array => ['next_due_date' => $date, 'next_invoice_date' => $date, 'billing_anchor_day' => $anchorDay ?? $date->day]);
    }

    public function free(): static
    {
        return $this->state(fn (): array => ['cycle' => BillingCycle::Free, 'recurring_amount' => 0, 'first_payment_amount' => 0, 'next_due_date' => null, 'next_invoice_date' => null, 'billing_anchor_day' => null]);
    }

    public function onServer(?Server $server = null): static
    {
        return $this->state(fn (): array => [
            'server_id' => $server ?? Server::factory(),
            'username' => fake()->unique()->lexify('user????'),
            'password' => 'S3cret-'.fake()->lexify('??????'),
        ]);
    }
}
