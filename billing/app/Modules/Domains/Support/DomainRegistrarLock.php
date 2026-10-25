<?php

namespace App\Modules\Domains\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The one lock per domain that every registrar-mutating Domains job and sync holds while it talks to the registrar,
 * so a registration, a transfer, a renewal and a sync of the same domain never run their registrar calls at once.
 *
 * TTL is above the longest holder (a job's 180 s timeout; a staff sync is bounded by two driver calls) so a crashed
 * worker cannot hold it for long, and a holder never outlives it, so the lock cannot expire under a running call.
 *
 * Never take it inside a database transaction: on the database cache store with PostgreSQL, a lock that is already
 * taken makes the failed insert abort the surrounding transaction. No caller does (jobs, SyncDomain and the bulk
 * sync all run outside one).
 */
final class DomainRegistrarLock
{
    /** Seconds: above every holder's runtime cap (the registrar jobs' 180 s timeout), below the queue's default retry_after (240 s). */
    public const TTL = 220;

    public static function for(int $domainId): Lock
    {
        return Cache::lock('domains:registrar:'.$domainId, self::TTL);
    }
}
