<?php

namespace App\Modules\Support\Models;

use App\Modules\Support\Database\Factories\KbArticleFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property bool $is_published
 * @property bool $is_private
 * @property list<string>|null $tags
 * @property int $views
 * @property int $votes_up
 * @property int $votes_down
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('support_kb_articles')]
#[UseFactory(KbArticleFactory::class)]
#[Fillable(['title', 'slug', 'body', 'is_published', 'is_private', 'tags', 'views', 'votes_up', 'votes_down', 'sort_order'])]
class KbArticle extends Model
{
    /** @use HasFactory<KbArticleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_private' => 'boolean',
            'tags' => 'array',
            'views' => 'integer',
            'votes_up' => 'integer',
            'votes_down' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<KbCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(KbCategory::class, 'support_kb_article_category', 'article_id', 'category_id');
    }

    /**
     * Published articles; private ones only when a user is signed in.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, bool $signedIn): void
    {
        $query->where('is_published', true)->when(! $signedIn, fn (Builder $query) => $query->where('is_private', false));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        foreach (preg_split('/\s+/', trim($term)) ?: [] as $word) {
            $like = '%'.$word.'%';
            $query->where(fn (Builder $query) => $query->whereLike('title', $like)
                ->orWhereLike('body', $like)
                ->orWhereLike('tags', $like));
        }
    }
}
