<?php

namespace App\Modules\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $gateway
 * @property string $direction callback|request|response
 * @property int|null $invoice_id
 * @property string $result
 * @property array<string, mixed>|null $payload redacted
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['gateway', 'direction', 'invoice_id', 'result', 'payload'])]
class GatewayLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'gateway_log';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
