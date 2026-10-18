<?php

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'key' => 'custom.'.fake()->unique()->slug(2),
            'type' => TemplateType::General,
            'audience' => TemplateAudience::Client,
            'name' => fake()->sentence(3),
            'is_system' => false,
            'is_enabled' => true,
        ];
    }

    /**
     * Adds a translation row with the given subject and body.
     */
    public function withBody(string $subject, string $body, string $locale = 'en'): static
    {
        return $this->afterCreating(fn (EmailTemplate $template) => $template->translations()->create([
            'locale' => $locale, 'subject' => $subject, 'body' => $body,
        ]));
    }
}
