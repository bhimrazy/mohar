<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Append-only audit trail. Inputs are bounded and sanitised here, centrally: descriptions are plain
 * sentences (put user-supplied values such as emails in $properties, never interpolate them unbounded),
 * control characters are stripped and every string is truncated to fit its column.
 */
class ActivityLogger
{
    /**
     * Session key holding the staff user id while a staff member is signed in as a client
     * (set by the Platform impersonation flow). Every entry written meanwhile records it.
     */
    public const IMPERSONATOR_SESSION_KEY = 'impersonator_id';

    private const MAX_PROPERTY_LENGTH = 1000;

    /**
     * @param  array<string, mixed>  $properties
     * @param  string|null  $event  machine key, e.g. "client.closed"
     */
    public function log(string $description, ?Model $subject = null, ?int $clientId = null, array $properties = [], ?string $event = null, ?User $actor = null): ActivityLog
    {
        $request = $this->httpRequest();
        $actor ??= $request?->user();
        $impersonatorId = $request?->hasSession() ? $request->session()->get(self::IMPERSONATOR_SESSION_KEY) : null;

        return ActivityLog::query()->create([
            'user_id' => $actor?->getKey(),
            'client_id' => $clientId,
            'description' => self::clean($description, 255),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : self::cleanArray($properties),
            'ip_address' => $request?->ip(),
            'actor_type' => match (true) {
                $actor === null => 'system',
                $actor->is_staff => 'staff',
                default => 'user',
            },
            'impersonator_id' => is_numeric($impersonatorId) ? (int) $impersonatorId : null,
            'event' => $event === null ? null : self::clean($event, 100),
            'user_agent' => $request ? (self::clean((string) $request->userAgent(), 255) ?: null) : null,
        ]);
    }

    /**
     * The request currently being handled over HTTP, or null for console commands and queued jobs
     * (whose container request is a synthetic one with no matched route).
     */
    private function httpRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request->route() !== null ? $request : null;
    }

    /**
     * Strip control characters and truncate to a column length (multibyte-safe).
     */
    public static function clean(string $value, int $length): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function cleanArray(array $values): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            is_string($value) => self::clean($value, self::MAX_PROPERTY_LENGTH),
            is_array($value) => self::cleanArray($value),
            default => $value,
        }, $values);
    }
}
