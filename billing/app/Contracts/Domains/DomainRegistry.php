<?php

namespace App\Contracts\Domains;

use App\Data\Domains\DomainDraft;
use App\Data\Services\ProvisionResult;

/**
 * Implemented by the Domains module. Used by Orders.
 */
interface DomainRegistry
{
    public function createPending(DomainDraft $draft): int;

    /**
     * Submit the pending registration/transfer to the registrar.
     */
    public function process(int $domainId): ProvisionResult;

    public function cancelPending(int $domainId, string $status = 'cancelled'): void;
}
