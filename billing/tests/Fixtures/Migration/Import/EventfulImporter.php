<?php

namespace Tests\Fixtures\Migration\Import;

use App\Events\Core\ClientRegistered;

/**
 * Probe for the record-importer Arch rule: an importer that announces what it wrote, which the rule must refuse.
 */
final class EventfulImporter
{
    public function upsert(int $clientId): void
    {
        event(new ClientRegistered($clientId, null));
    }
}
