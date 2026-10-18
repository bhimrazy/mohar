<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\AccountLookup;
use App\Data\Services\Provisioning\ServerConnection;

/**
 * Capability: look an account up on the server to settle a command whose outcome was unknown (ADR 0009 §4). The
 * driver decides whether a match is ours (same username, domain and owning user) and answers executed, conflict,
 * absent or unknown. Read-only; never creates or changes anything.
 */
interface FindsAccounts
{
    public function findAccount(ServerConnection $server, string $username, ?string $domain = null): AccountLookup;
}
