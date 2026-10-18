<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 */
#[Table('support_canned_reply_categories')]
#[Fillable(['parent_id', 'name'])]
class CannedReplyCategory extends Model
{
    /**
     * @return BelongsTo<CannedReplyCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<CannedReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(CannedReply::class, 'category_id');
    }
}
