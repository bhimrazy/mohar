<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Modules\Support\Database\Factories\DepartmentFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $email
 * @property bool $is_hidden
 * @property bool $clients_only
 * @property bool $replies_only_by_email
 * @property bool $send_autoresponse
 * @property string|null $autoresponse_message
 * @property bool $prevent_client_close
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('support_departments')]
#[UseFactory(DepartmentFactory::class)]
#[Fillable([
    'name', 'slug', 'description', 'email', 'is_hidden', 'clients_only', 'replies_only_by_email',
    'send_autoresponse', 'autoresponse_message', 'prevent_client_close', 'sort_order',
])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'clients_only' => 'boolean',
            'replies_only_by_email' => 'boolean',
            'send_autoresponse' => 'boolean',
            'prevent_client_close' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Stored lowercase: unique indexes are case-sensitive on PostgreSQL.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * Staff assigned to the department (they see and act on its tickets).
     *
     * @return BelongsToMany<User, $this>
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'support_department_user', 'department_id', 'user_id')
            ->withPivot(['notify_new', 'notify_replies']);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
