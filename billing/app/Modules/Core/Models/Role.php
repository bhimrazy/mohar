<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Database\Factories\RoleFactory;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $key stable identity of a system role (SystemRoles::ROLES); null for custom roles. Not mass-assignable.
 * @property string $name
 * @property string|null $description
 * @property list<string> $permissions
 * @property bool $is_system
 */
#[UseFactory(RoleFactory::class)]
#[Fillable(['name', 'description', 'permissions', 'is_system'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Whether the role grants the permission. Supports "*" and "module.*" / "module.area.*" wildcards.
     * Sensitive permissions (PermissionRegistry::isSensitive) are granted only by their exact key or "*".
     */
    public function allows(string $permission): bool
    {
        $sensitive = app(PermissionRegistry::class)->isSensitive($permission);

        foreach ($this->permissions as $granted) {
            if ($granted === '*' || $granted === $permission) {
                return true;
            }

            if (! $sensitive && str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
