<?php

namespace App\Modules\Support\Actions;

use App\Modules\Support\Models\KbArticle;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a knowledge base article and its category links.
 */
class SaveKbArticle
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $categoryIds
     */
    public function handle(?KbArticle $article, array $attributes, array $categoryIds): KbArticle
    {
        return DB::transaction(function () use ($article, $attributes, $categoryIds): KbArticle {
            $article ??= new KbArticle;
            $article->fill($attributes)->save();
            $article->categories()->sync($categoryIds);

            return $article;
        });
    }
}
