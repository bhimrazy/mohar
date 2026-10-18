<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event sent to one endpoint, with the outcome of its latest attempt (spec §2).
 *
 * @property int $id
 * @property string $event_id
 * @property int $webhook_endpoint_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property int $attempt
 * @property WebhookDeliveryStatus $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $error
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
#[Fillable(['event_id', 'webhook_endpoint_id', 'event_type', 'payload', 'attempt', 'status', 'response_status', 'response_body', 'error', 'duration_ms', 'next_attempt_at', 'delivered_at'])]
class WebhookDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt' => 'integer',
            'status' => WebhookDeliveryStatus::class,
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
