<?php

namespace App\Modules\Support\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $category_id
 * @property string $name
 * @property string $body
 * @property int $sort_order
 * @property CarbonImmutable|null $updated_at
 * @property-read CannedReplyCategory|null $category
 */
#[Table('support_canned_replies')]
#[Fillable(['category_id', 'name', 'body', 'sort_order'])]
class CannedReply extends Model
{
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<CannedReplyCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CannedReplyCategory::class, 'category_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('body', $like));
    }
}
