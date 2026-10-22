<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Database\Factories\ClientFactory;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Support\ClientCurrencyLock;
use App\Modules\Core\Support\Like;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int|null $client_group_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company_name
 * @property string $email
 * @property CarbonImmutable|null $email_changed_at when the email address last changed
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
 * @property int|null $merged_into_client_id
 * @property ClientOrigin $origin how the client was created; set by each creation path, never by a form
 * @property EmailSource $email_source who set the current email; set wherever the email is, never by a form
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $imported_at when the client was created by an import
 * @property list<array{label: string, value: string, source: string}>|null $imported_fields columns kept by an import
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

    /** Statuses the profile form may set; closing and reopening go through their own audited actions. */
    public const EDITABLE_STATUSES = ['active', 'inactive'];

    /** Who sets the email on the next save (setEmailBy()); not stored. */
    private ?EmailSource $emailSetBy = null;

    protected function casts(): array
    {
        return ['tax_exempt' => 'boolean', 'closed_at' => 'immutable_datetime', 'email_changed_at' => 'immutable_datetime', 'imported_at' => 'immutable_datetime', 'imported_fields' => 'array', 'origin' => ClientOrigin::class, 'email_source' => EmailSource::class];
    }

    /**
     * Update a client's details under a row lock, so two edits cannot interleave, recording who set the email.
     * A currency change is checked under the same lock (#218): a module that copies the client's currency into a new
     * row reads it under a share lock, so its row is either visible here and blocks the change, or reads the new one.
     *
     * @param  array<string, mixed>  $data  validated fields
     * @return array{0: self, 1: array<string, mixed>} the saved client, and the values of $data's fields before
     *
     * @throws ValidationException when the currency changes and a module already holds amounts in the old one
     */
    public static function updateDetails(int $id, array $data, EmailSource $emailSetBy): array
    {
        return DB::transaction(function () use ($id, $data, $emailSetBy): array {
            $client = self::query()->lockForUpdate()->findOrFail($id);

            if (isset($data['currency']) && $data['currency'] !== $client->currency && ($reason = app(ClientCurrencyLock::class)->reason($client)) !== null) {
                throw ValidationException::withMessages(['currency' => $reason]);
            }

            $before = $client->only(array_keys($data));
            $client->fill(Arr::except($data, 'email'));

            if (isset($data['email'])) {
                $client->setEmailBy($emailSetBy, (string) $data['email']);
            }

            $client->save();

            return [$client, $before];
        });
    }

    /**
     * Set the email and say who set it: the next save records that source with it.
     */
    public function setEmailBy(EmailSource $source, string $email): static
    {
        $this->email = $email;
        $this->emailSetBy = $source;

        return $this;
    }

    /**
     * Changing the email address records when, and who set it (account import trusts an address by its source). A
     * change nobody declared through setEmailBy() counts as the client's own. The source is always written in the
     * same UPDATE as the email, even when it equals the loaded value (which may be stale).
     */
    protected static function booted(): void
    {
        static::saving(function (self $client): void {
            if (! $client->isDirty('email')) {
                return;
            }

            if (! $client->exists) {
                // A new client: the source its creation path gave, else the cautious one.
                $client->email_source = $client->emailSetBy ?? $client->email_source ?? EmailSource::Client;

                return;
            }

            $client->email_source = $client->emailSetBy ?? EmailSource::Client;
            unset($client->original['email_source']);
            $client->email_changed_at = CarbonImmutable::now();
        });

        static::saved(function (self $client): void {
            $client->emailSetBy = null;
        });
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
            $like = Like::contains($term);

            foreach (['first_name', 'last_name', 'company_name', 'email'] as $index => $column) {
                Like::where($query, $column, $like, $index === 0 ? 'and' : 'or');
            }

            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
        });
    }
}
