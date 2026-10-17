<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $client_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property array<string, bool> $email_preferences
 */
#[UseFactory(ContactFactory::class)]
#[Fillable(['client_id', 'first_name', 'last_name', 'email', 'phone', 'email_preferences'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /** Email categories a contact can opt into. */
    public const EMAIL_TYPES = ['general', 'invoice', 'support', 'product', 'domain'];

    protected function casts(): array
    {
        return ['email_preferences' => 'array'];
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function wants(string $type): bool
    {
        return (bool) ($this->email_preferences[$type] ?? false);
    }
}
