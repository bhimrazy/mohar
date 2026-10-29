<?php

namespace App\Modules\Core\Support;

use App\Contracts\Domains\DomainNameRules;

/**
 * Default when the Domains module is absent: no registry restricts a name.
 */
class NullDomainNameRules implements DomainNameRules
{
    public function registryLevelError(string $domain): ?string
    {
        return null;
    }

    public function registeredByClient(string $tld): bool
    {
        return false;
    }
}
