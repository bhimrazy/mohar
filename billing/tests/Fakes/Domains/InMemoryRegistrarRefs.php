<?php

namespace Tests\Fakes\Domains;

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;

/**
 * RegistrarRefs without a database, for the driver contract (which fails any query a driver makes).
 */
final class InMemoryRegistrarRefs implements RegistrarRefs
{
    /** @var array<string, RegistrarRef> */
    public array $refs = [];

    public function find(string $registrarKey, string $refType, string $localType, int $localId, string $payloadHash = ''): ?RegistrarRef
    {
        return $this->refs[implode('|', [$registrarKey, $refType, $localType, $localId, $payloadHash])] ?? null;
    }

    public function remember(RegistrarRef $ref): RegistrarRef
    {
        return $this->refs[implode('|', [$ref->registrarKey, $ref->refType, $ref->localType, $ref->localId, $ref->payloadHash])] ??= $ref;
    }
}
