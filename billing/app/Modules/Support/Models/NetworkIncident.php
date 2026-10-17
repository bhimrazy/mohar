<?php

namespace App\Modules\Support\Models;

use App\Modules\Support\Database\Factories\NetworkIncidentFactory;
use App\Modules\Support\Enums\IncidentPriority;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Enums\IncidentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property IncidentType $type
 * @property int|null $server_id
 * @property string|null $affected
 * @property IncidentPriority $priority
 * @property IncidentStatus $status
 * @property string $description
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('support_network_incidents')]
#[UseFactory(NetworkIncidentFactory::class)]
#[Fillable(['title', 'type', 'server_id', 'affected', 'priority', 'status', 'description', 'starts_at', 'ends_at'])]
class NetworkIncident extends Model
{
    /** @use HasFactory<NetworkIncidentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => IncidentType::class,
            'priority' => IncidentPriority::class,
            'status' => IncidentStatus::class,
            'server_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<NetworkIncidentUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(NetworkIncidentUpdate::class, 'incident_id')->latest('created_at')->latest('id');
    }
}
