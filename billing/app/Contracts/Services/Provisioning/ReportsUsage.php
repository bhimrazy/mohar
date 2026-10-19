<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\UsageRow;

/**
 * Capability: disk and bandwidth usage of the accounts on a server, read by the daily usage sync. The host matches
 * rows to services by username and ignores unknown accounts. Throw on failure; the host logs it and moves on.
 */
interface ReportsUsage
{
    /**
     * @return array<string, UsageRow> keyed by account username
     */
    public function usage(ServerConnection $server): array;
}
