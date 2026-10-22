<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\AvailabilityResult;
use App\Data\Domains\Registrar\RegistrarConfig;

/**
 * Capability `suggestions`: name suggestions from the registrar. Without it the host builds suggestions itself.
 */
interface SuggestsNames
{
    /**
     * @param  list<string>  $tlds
     * @return list<AvailabilityResult> available names only, at most $limit
     */
    public function suggest(RegistrarConfig $config, string $term, array $tlds, int $limit): array;
}
