<?php

namespace App\Modules\Core\Support;

use App\Contracts\Billing\ClientDocuments;

/**
 * Default when Billing is not installed: no client has billing documents.
 */
class NullClientDocuments implements ClientDocuments
{
    public function clientsWithDocuments(array $clientIds): array
    {
        return [];
    }
}
