<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Email categories for the account's primary address (scope "owner") or another client-area login
 * (scope "user:<id>"). Contacts keep theirs on contacts.email_preferences (Core). Missing keys default to on.
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $user_id
 * @property string $scope_key
 * @property array<string, bool> $categories
 */
#[Fillable(['client_id', 'user_id', 'scope_key', 'categories'])]
class ClientEmailPreference extends Model
{
    public const OWNER = 'owner';

    protected function casts(): array
    {
        return ['categories' => 'array'];
    }

    public static function scopeFor(?int $userId): string
    {
        return $userId === null ? self::OWNER : 'user:'.$userId;
    }

    public function wants(string $category): bool
    {
        return (bool) ($this->categories[$category] ?? true);
    }
}
