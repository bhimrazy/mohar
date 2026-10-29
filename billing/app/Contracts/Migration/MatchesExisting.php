<?php

namespace App\Contracts\Migration;

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportRecord;

/**
 * Capability of a RecordImporter whose module can tell which record it already holds for an incoming one (ADR 0012,
 * ADR 0019): a domain by its name, a cPanel account by server and username. Migration cannot read those tables.
 *
 * A match is a proposal. Migration asks only for records the run's id map does not resolve (the id map wins), shows
 * the match on the review, and decides per row whether to update or skip; the matched id reaches upsert() as
 * $existingTargetId. matchExisting() writes nothing and takes no lock.
 */
interface MatchesExisting extends RecordImporter
{
    /**
     * @param  list<ImportRecord>  $records
     * @return array<string, int> target id keyed by ImportRecord::$sourceId; records without a match are left out
     */
    public function matchExisting(array $records, ImportContext $context): array;
}
