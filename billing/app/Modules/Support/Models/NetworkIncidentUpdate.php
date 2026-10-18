<?php

namespace App\Modules\Support\Models;

use App\Modules\Support\Enums\IncidentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $incident_id
 * @property string $body
 * @property IncidentStatus $status
 * @property CarbonImmutable|null $created_at
 */
#[Table('support_network_incident_updates')]
#[Fillable(['incident_id', 'body', 'status'])]
class NetworkIncidentUpdate extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['status' => IncidentStatus::class];
    }
}
