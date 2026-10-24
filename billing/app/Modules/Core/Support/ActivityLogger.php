<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use App\Support\Integrations\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Append-only audit trail. Inputs are bounded and sanitised here, centrally: descriptions are plain
 * sentences (put user-supplied values such as emails in $properties, never interpolate them unbounded),
 * control characters are stripped and every string is truncated to fit its column. Text in $properties
 * (provider messages, for one) is masked by the Redactor's value rules and the values of the driver calls
 * in progress (RedactionScope), before it is cut (and only its first MASKED_PROPERTY_LENGTH characters are read).
 * Property names are not read by the Redactor's key rule, so references such as a template's `key` stay readable;
 * only the exact names in SECRET_PROPERTIES withhold their value.
 */
class ActivityLogger
{
    /**
     * Session key holding the staff user id while a staff member is signed in as a client
     * (set by the Platform impersonation flow). Every entry written meanwhile records it.
     */
    public const IMPERSONATOR_SESSION_KEY = 'impersonator_id';

    private const MAX_PROPERTY_LENGTH = 1000;

    /** Text read for masking: enough that masks shorter than the text they replace still fill the cut. */
    private const MASKED_PROPERTY_LENGTH = 4 * self::MAX_PROPERTY_LENGTH;

    /** Property names that only ever hold a credential (case-insensitive); their value is withheld whole. */
    private const SECRET_PROPERTIES = ['password', 'secret', 'api_key', 'client_secret', 'private_key'];

    public function __construct(private readonly Redactor $redactor) {}

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
            'properties' => $properties === [] ? null : $this->cleanProperties($properties),
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
    private function cleanProperties(array $values, ?RedactionSet $set = null): array
    {
        $set ??= RedactionScope::current();

        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                is_string($key) && in_array(strtolower($key), self::SECRET_PROPERTIES, true) && (is_string($value) || is_int($value) || is_float($value) || is_array($value)) => Redactor::MASK,
                is_string($value) => self::clean($this->redactor->maskValues(mb_substr($value, 0, self::MASKED_PROPERTY_LENGTH), $set), self::MAX_PROPERTY_LENGTH),
                is_int($value), is_float($value) => $set->matches((string) $value) ? Redactor::MASK : $value,
                is_array($value) => $this->cleanProperties($value, $set),
                default => $value,
            };
        }

        return $values;
    }
}
