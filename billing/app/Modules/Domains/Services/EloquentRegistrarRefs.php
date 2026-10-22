<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use App\Modules\Domains\Models\RegistrarRefRecord;

/**
 * registrar_refs behind the kernel RegistrarRefs contract (registrars.md C4). Secrets are encrypted at rest.
 */
class EloquentRegistrarRefs implements RegistrarRefs
{
    public function find(string $registrarKey, string $refType, string $localType, int $localId, string $payloadHash = ''): ?RegistrarRef
    {
        $record = RegistrarRefRecord::query()->where([
            'registrar_key' => $registrarKey, 'ref_type' => $refType, 'local_type' => $localType, 'local_id' => $localId, 'payload_hash' => $payloadHash,
        ])->first();

        return $record === null ? null : new RegistrarRef($record->registrar_key, $record->ref_type, $record->local_type, $record->local_id, $record->remote_id, $record->payload_hash, $record->secret);
    }

    public function remember(RegistrarRef $ref): void
    {
        RegistrarRefRecord::query()->updateOrCreate(
            ['registrar_key' => $ref->registrarKey, 'ref_type' => $ref->refType, 'local_type' => $ref->localType, 'local_id' => $ref->localId, 'payload_hash' => $ref->payloadHash],
            ['remote_id' => $ref->remoteId, 'secret' => $ref->secret],
        );
    }
}
