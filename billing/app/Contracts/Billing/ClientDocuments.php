<?php

namespace App\Contracts\Billing;

/**
 * Whether clients already have billing documents. Implemented by Billing; Core binds a null default.
 */
interface ClientDocuments
{
    /**
     * The clients among $clientIds that have any invoice, quote, transaction or credit entry: amounts in their
     * currency exist, so the client's currency cannot change (client-import spec §6.4).
     *
     * @param  list<int>  $clientIds
     * @return list<int>
     */
    public function clientsWithDocuments(array $clientIds): array;
}
