<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Database\Factories\ClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $client_group_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $address_1
 * @property string|null $address_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property string|null $country
 * @property string|null $tax_id
 * @property bool $tax_exempt
 * @property string $currency
 * @property string $language
 * @property string $status
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $display_name
 */
#[UseFactory(ClientFactory::class)]
#[Fillable([
    'client_group_id', 'first_name', 'last_name', 'company_name', 'email', 'phone',
    'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
    'tax_id', 'tax_exempt', 'currency', 'language', 'status', 'notes',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public const STATUSES = ['active', 'inactive', 'closed'];

    protected function casts(): array
    {
        return ['tax_exempt' => 'boolean'];
    }

    /**
     * Emails are stored lowercase: unique indexes and equality lookups are case-sensitive on PostgreSQL.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower($value));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->buildDisplayName());
    }

    private function buildDisplayName(): string
    {
        $person = trim("{$this->first_name} {$this->last_name}");

        return $this->company_name ? "{$this->company_name} ({$person})" : $person;
    }

    /**
     * @return BelongsTo<ClientGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class, 'client_group_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['is_owner', 'permissions'])->withTimestamps();
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $like = '%'.$term.'%';
            $query->whereLike('first_name', $like)
                ->orWhereLike('last_name', $like)
                ->orWhereLike('company_name', $like)
                ->orWhereLike('email', $like);

            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
        });
    }
}
