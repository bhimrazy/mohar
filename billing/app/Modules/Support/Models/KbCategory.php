<?php

namespace App\Modules\Support\Models;

use App\Modules\Support\Database\Factories\KbCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_hidden
 * @property int $sort_order
 * @property-read KbCategory|null $parent
 */
#[Table('support_kb_categories')]
#[UseFactory(KbCategoryFactory::class)]
#[Fillable(['parent_id', 'name', 'slug', 'description', 'is_hidden', 'sort_order'])]
class KbCategory extends Model
{
    /** @use HasFactory<KbCategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<KbCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<KbCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<KbArticle, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(KbArticle::class, 'support_kb_article_category', 'category_id', 'article_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
