<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\RegistrarRef;

/**
 * The store of remote objects a registrar driver created for local records (registrars.md C4): one ResellerClub
 * customer per client, its contacts, OpenSRS profiles and their credentials. Consumer contract (ADR 0010),
 * implemented by Domains; a driver receives it by constructor injection.
 */
interface RegistrarRefs
{
    /**
     * The remote object made for this local record and payload, or null when there is none yet.
     */
    public function find(string $registrarKey, string $refType, string $localType, int $localId, string $payloadHash = ''): ?RegistrarRef;

    /**
     * Record a remote object after the registrar created it. Recording the same local record and payload again
     * replaces the remote id (and secret).
     */
    public function remember(RegistrarRef $ref): void;
}
