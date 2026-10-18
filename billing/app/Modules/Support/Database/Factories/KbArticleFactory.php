<?php

namespace App\Modules\Support\Database\Factories;

use App\Modules\Support\Models\KbArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KbArticle>
 */
class KbArticleFactory extends Factory
{
    protected $model = KbArticle::class;

    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(5), '.');

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'body' => fake()->paragraphs(3, true),
            'is_published' => true,
            'is_private' => false,
            'tags' => [],
        ];
    }

    public function private(): static
    {
        return $this->state(['is_private' => true]);
    }

    public function draft(): static
    {
        return $this->state(['is_published' => false]);
    }
}
