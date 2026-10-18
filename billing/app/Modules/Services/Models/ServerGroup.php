<?php

namespace App\Modules\Services\Models;

use App\Modules\Services\Database\Factories\ServerGroupFactory;
use App\Modules\Services\Enums\FillType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property FillType $fill_type
 */
#[UseFactory(ServerGroupFactory::class)]
#[Fillable(['name', 'fill_type'])]
class ServerGroup extends Model
{
    /** @use HasFactory<ServerGroupFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['fill_type' => FillType::class];
    }

    /**
     * @return BelongsToMany<Server, $this>
     */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_group_server')->withPivot('sort_order')->orderByPivot('sort_order')->orderBy('servers.id');
    }
}
