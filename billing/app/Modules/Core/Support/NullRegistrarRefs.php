<?php

namespace App\Modules\Core\Support;

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;

/**
 * Default when the Domains module is absent: nothing is stored, so find() never finds and remember() hands the
 * ref back unchanged.
 */
class NullRegistrarRefs implements RegistrarRefs
{
    public function find(string $registrarKey, string $refType, string $localType, int $localId, string $payloadHash = ''): ?RegistrarRef
    {
        return null;
    }

    public function remember(RegistrarRef $ref): RegistrarRef
    {
        return $ref;
    }
}
