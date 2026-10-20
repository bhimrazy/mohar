<?php

namespace App\Modules\Storefront\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of the website content (SiteContent schema). At most one draft exists; the newest published row is
 * what visitors see; older published rows are archived.
 *
 * @property int $id
 * @property string $status draft|published|archived
 * @property array<string, mixed> $content
 * @property int $schema_version
 * @property int|null $created_by
 * @property int|null $published_by
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $author
 */
#[Table('storefront_revisions')]
#[Fillable(['status', 'content', 'schema_version', 'created_by', 'published_by', 'published_at'])]
class Revision extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'schema_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
