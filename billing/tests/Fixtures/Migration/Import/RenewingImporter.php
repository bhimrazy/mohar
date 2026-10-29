<?php

namespace Tests\Fixtures\Migration\Import;

use App\Modules\Domains\Jobs\RenewDomain;

/**
 * Probe for the record-importer Arch rule: an importer that queues its module's registrar job, which the rule must
 * refuse.
 */
final class RenewingImporter
{
    public function upsert(int $domainId): void
    {
        RenewDomain::dispatch($domainId);
    }
}
