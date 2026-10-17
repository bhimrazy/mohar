<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Session key holding the staff user id while a staff member is signed in as a client
     * (set by the Platform impersonation flow). Every entry written meanwhile records it.
     */
    public const IMPERSONATOR_SESSION_KEY = 'impersonator_id';

    /**
     * @param  array<string, mixed>  $properties
     * @param  string|null  $event  machine key, e.g. "client.closed"
     */
    public function log(string $description, ?Model $subject = null, ?int $clientId = null, array $properties = [], ?string $event = null, ?User $actor = null): ActivityLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= $request?->user();
        $impersonatorId = $request?->hasSession() ? $request->session()->get(self::IMPERSONATOR_SESSION_KEY) : null;

        return ActivityLog::query()->create([
            'user_id' => $actor?->getKey(),
            'client_id' => $clientId,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => $request?->ip(),
            'actor_type' => match (true) {
                $actor === null => 'system',
                $actor->is_staff => 'staff',
                default => 'user',
            },
            'impersonator_id' => is_numeric($impersonatorId) ? (int) $impersonatorId : null,
            'event' => $event,
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) ?: null : null,
        ]);
    }
}
