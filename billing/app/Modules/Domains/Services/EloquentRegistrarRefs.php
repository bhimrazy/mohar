<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use App\Modules\Domains\Models\RegistrarRefRecord;
use InvalidArgumentException;

/**
 * registrar_refs behind the kernel RegistrarRefs contract (registrars.md C4). Secrets are encrypted at rest.
 */
class EloquentRegistrarRefs implements RegistrarRefs
{
    /** Column lengths of registrar_refs, checked before writing so every database refuses an over-long value alike. */
    private const MAX_LENGTHS = ['registrarKey' => 50, 'refType' => 20, 'localType' => 40, 'remoteId' => 191, 'payloadHash' => 64];

    public function find(string $registrarKey, string $refType, string $localType, int $localId, string $payloadHash = ''): ?RegistrarRef
    {
        $record = RegistrarRefRecord::query()->where([
            'registrar_key' => $registrarKey, 'ref_type' => $refType, 'local_type' => $localType, 'local_id' => $localId, 'payload_hash' => $payloadHash,
        ])->first();

        return $record === null ? null : new RegistrarRef($record->registrar_key, $record->ref_type, $record->local_type, $record->local_id, $record->remote_id, $record->payload_hash, $record->secret);
    }

    /**
     * @throws InvalidArgumentException when a value is longer than its column (payloadHash is sha256 hex, 64 characters)
     */
    public function remember(RegistrarRef $ref): void
    {
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($ref->{$field}) > $max) {
                throw new InvalidArgumentException("RegistrarRef {$field} is longer than {$max} characters.");
            }
        }

        RegistrarRefRecord::query()->updateOrCreate(
            ['registrar_key' => $ref->registrarKey, 'ref_type' => $ref->refType, 'local_type' => $ref->localType, 'local_id' => $ref->localId, 'payload_hash' => $ref->payloadHash],
            ['remote_id' => $ref->remoteId, 'secret' => $ref->secret],
        );
    }
}
