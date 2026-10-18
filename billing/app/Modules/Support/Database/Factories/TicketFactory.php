<?php

namespace App\Modules\Support\Database\Factories;

use App\Modules\Core\Models\Client;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'number' => (string) fake()->unique()->numberBetween(100000, 999999),
            'access_key' => Str::random(32),
            'department_id' => Department::factory(),
            'client_id' => null,
            'requester_name' => fake()->name(),
            'requester_email' => fake()->unique()->safeEmail(),
            'subject' => fake()->sentence(6),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::Web,
            'cc' => [],
            'tags' => [],
            'last_reply_at' => now(),
            'last_client_reply_at' => now(),
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn (): array => [
            'client_id' => $client->id,
            'requester_name' => trim($client->first_name.' '.$client->last_name),
            'requester_email' => $client->email,
        ]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
            'closed_by' => $status === TicketStatus::Closed ? 'staff' : null,
        ]);
    }
}
