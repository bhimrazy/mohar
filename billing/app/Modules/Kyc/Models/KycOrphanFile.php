<?php

namespace App\Modules\Kyc\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * An identity image file under kyc/ that no document refers to, recorded by FindOrphanFiles and deleted only by staff
 * (DeleteOrphanFiles). The path never leaves the server.
 *
 * @property int $id
 * @property string $path
 * @property int $client_id the id in the path
 * @property CarbonImmutable|null $modified_at
 * @property CarbonImmutable $found_at
 * @property CarbonImmutable $last_seen_at
 */
#[WithoutTimestamps]
#[Fillable(['path', 'client_id', 'modified_at', 'found_at', 'last_seen_at'])]
#[Hidden(['path'])]
class KycOrphanFile extends Model
{
    protected function casts(): array
    {
        return ['modified_at' => 'immutable_datetime', 'found_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
