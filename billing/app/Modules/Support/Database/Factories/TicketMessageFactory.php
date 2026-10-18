<?php

namespace App\Modules\Support\Database\Factories;

use App\Modules\Support\Enums\AuthorType;
use App\Modules\Support\Enums\MessageType;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    protected $model = TicketMessage::class;

    public function definition(): array
    {
        $body = fake()->paragraph();

        return [
            'ticket_id' => Ticket::factory(),
            'type' => MessageType::Reply,
            'author_type' => AuthorType::Client,
            'author_name' => fake()->name(),
            'author_email' => fake()->safeEmail(),
            'body' => $body,
            'body_html' => '<p>'.e($body).'</p>',
            'source' => TicketSource::Web,
        ];
    }

    public function staff(): static
    {
        return $this->state(['author_type' => AuthorType::Staff, 'source' => TicketSource::Staff]);
    }

    public function note(): static
    {
        return $this->state(['type' => MessageType::Note, 'author_type' => AuthorType::Staff, 'source' => TicketSource::Staff]);
    }
}
