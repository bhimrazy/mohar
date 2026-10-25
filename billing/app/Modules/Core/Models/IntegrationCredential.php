<?php

namespace App\Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Expiry of one stored integration credential (a certificate setting), kept outside the integration's encrypted
 * settings. `family` is the integration family ("gateway"), `owner_key` its record (the gateway key). `warned_stage`
 * is the last expiry warning sent (days before expiry, 0 for the expiry itself); a new upload clears it.
 *
 * @property int $id
 * @property string $family
 * @property string $owner_key
 * @property string $setting_key
 * @property string $serial
 * @property CarbonImmutable $not_after
 * @property int|null $warned_stage
 */
#[Fillable(['family', 'owner_key', 'setting_key', 'serial', 'not_after', 'warned_stage'])]
class IntegrationCredential extends Model
{
    public const GATEWAY = 'gateway';

    protected function casts(): array
    {
        return [
            'not_after' => 'immutable_datetime',
            'warned_stage' => 'integer',
        ];
    }
}
