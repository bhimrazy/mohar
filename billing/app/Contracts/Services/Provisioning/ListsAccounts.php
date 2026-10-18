<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\RemoteAccount;
use App\Data\Services\Provisioning\ServerConnection;

/**
 * Capability: every account that exists on a server, for importers and for reconciling services with the panel.
 */
interface ListsAccounts
{
    /**
     * @return list<RemoteAccount>
     */
    public function listAccounts(ServerConnection $server): array;
}
