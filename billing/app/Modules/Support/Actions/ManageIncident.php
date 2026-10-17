<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\NetworkIncidentOpened;
use App\Events\Support\NetworkIncidentResolved;
use App\Events\Support\NetworkIncidentUpdated;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Models\NetworkIncidentUpdate;
use Illuminate\Support\Facades\DB;

/**
 * Network status incidents (spec §7): open, edit, post updates, resolve (status=resolved, ends_at=now).
 */
class ManageIncident
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes): NetworkIncident
    {
        $incident = NetworkIncident::query()->create($attributes);
        NetworkIncidentOpened::dispatch($incident->id);

        if ($incident->status === IncidentStatus::Resolved) {
            NetworkIncidentResolved::dispatch($incident->id);
        }

        return $incident;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(NetworkIncident $incident, array $attributes): NetworkIncident
    {
        $wasResolved = $incident->status === IncidentStatus::Resolved;
        $resolvesNow = IncidentStatus::tryFrom((string) ($attributes['status'] ?? '')) === IncidentStatus::Resolved;
        $incident->update($attributes);

        if (! $wasResolved && $resolvesNow) {
            if ($incident->ends_at === null) {
                $incident->forceFill(['ends_at' => now()])->save();
            }

            NetworkIncidentResolved::dispatch($incident->id);
        }

        return $incident;
    }

    public function addUpdate(NetworkIncident $incident, string $body, IncidentStatus $status): NetworkIncidentUpdate
    {
        $update = DB::transaction(function () use ($incident, $body, $status): NetworkIncidentUpdate {
            $update = NetworkIncidentUpdate::query()->create(['incident_id' => $incident->id, 'body' => $body, 'status' => $status]);
            $incident->forceFill([
                'status' => $status,
                'ends_at' => $status === IncidentStatus::Resolved ? ($incident->ends_at ?? now()) : $incident->ends_at,
            ]);
            $incident->touch();

            return $update;
        });

        NetworkIncidentUpdated::dispatch($incident->id, $status->value);

        if ($status === IncidentStatus::Resolved) {
            NetworkIncidentResolved::dispatch($incident->id);
        }

        return $update;
    }

    public function resolve(NetworkIncident $incident): NetworkIncident
    {
        if ($incident->status !== IncidentStatus::Resolved) {
            $incident->forceFill(['status' => IncidentStatus::Resolved, 'ends_at' => now()])->save();
            NetworkIncidentResolved::dispatch($incident->id);
        }

        return $incident;
    }
}
