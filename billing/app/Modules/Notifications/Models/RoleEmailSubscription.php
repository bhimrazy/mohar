<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Core\Models\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff email categories a role receives (spec §2). Roles without a row: full administrators ("*")
 * receive every category, other roles none.
 *
 * @property int $id
 * @property int $role_id
 * @property list<string> $categories
 * @property-read Role $role
 */
#[Fillable(['role_id', 'categories'])]
class RoleEmailSubscription extends Model
{
    protected function casts(): array
    {
        return ['categories' => 'array'];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
