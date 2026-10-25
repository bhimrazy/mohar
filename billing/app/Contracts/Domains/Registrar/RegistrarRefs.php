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
     * Record a remote object after the registrar created it, and return the ref that is stored: first writer wins.
     * When a ref for the same registrar, type, local record and payload hash exists already, nothing is changed
     * (remote id and secret included) and the stored ref comes back, so two callers that remember the same key,
     * even at the same moment, both receive the ref of whichever stored first. Use the returned `remoteId` and
     * `secret`, not the ones you passed.
     *
     * Lock rule: the store itself needs none; a unique key decides the winner atomically, and a caller whose insert
     * meets a concurrent uncommitted winner may block until that transaction ends. remember() cannot stop two workers
     * from both creating the remote object before either remembers. A driver that must not create duplicates holds
     * a cross-process lock (Cache::lock on a shared store, never the array driver) on the ref key (registrar, type,
     * local record, payload hash) around find, create and remember, with a TTL longer than the registrar call, and
     * re-reads with find() after taking it. Do not hold a database transaction open across the registrar call.
     *
     * When the returned `remoteId` differs from the one passed, the caller's remote object is an orphan at the
     * registrar: log it, and use the returned ref.
     */
    public function remember(RegistrarRef $ref): RegistrarRef;
}
