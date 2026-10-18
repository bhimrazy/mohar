<?php

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'uuid' => (string) Str::uuid(),
            'dedupe_key' => sha1((string) Str::uuid()),
            'template_key' => 'invoice.created',
            'recipient' => $email,
            'to' => [$email],
            'subject' => fake()->sentence(4),
            'body_html' => '<p>'.fake()->paragraph().'</p>',
            'body_text' => fake()->paragraph(),
            'locale' => 'en',
            'status' => EmailStatus::Sent,
            'attempts' => 1,
            'queued_at' => now(),
            'sent_at' => now(),
        ];
    }
}
