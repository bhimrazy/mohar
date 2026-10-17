<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $description, ?Model $subject = null, ?int $clientId = null, array $properties = []): ActivityLog
    {
        $request = app()->runningInConsole() ? null : request();

        return ActivityLog::query()->create([
            'user_id' => $request?->user()?->getKey(),
            'client_id' => $clientId,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => $request?->ip(),
        ]);
    }
}
