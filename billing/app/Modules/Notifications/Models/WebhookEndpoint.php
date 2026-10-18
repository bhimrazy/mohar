<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Database\Factories\WebhookEndpointFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outbound webhook subscriber (spec §2). Secrets are encrypted at rest and never sent to the browser
 * after creation/rotation.
 *
 * @property int $id
 * @property string $url
 * @property string|null $description
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property list<string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property string|null $disabled_reason
 * @property CarbonImmutable|null $last_success_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(WebhookEndpointFactory::class)]
#[Fillable(['url', 'description', 'secret', 'previous_secret', 'previous_secret_expires_at', 'events', 'is_active', 'consecutive_failures', 'disabled_reason', 'last_success_at', 'created_by'])]
#[Hidden(['secret', 'previous_secret'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array('*', $this->events, true) || in_array($eventType, $this->events, true);
    }

    /**
     * Secrets to sign with: the current one, plus the previous one while its rotation grace period runs.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [$this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at?->isFuture()) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }
}
