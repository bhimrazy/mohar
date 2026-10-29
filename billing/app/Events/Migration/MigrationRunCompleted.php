<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run finished applying (ADR 0019): dispatched once, on the applying → completed transition, after commit.
 * A run that stops dispatches MigrationRunPaused instead, never this. $sourceSystem is the kind of source ("csv",
 * "whmcs_api"). No per-record event is ever dispatched.
 *
 * The module that owns an imported entity may listen to bring its own projections up to date for what the run wrote
 * (Domains links the open invoices of imported domains), because imported records dispatch no domain event. Such a
 * listener follows the importers' rules: it sends no mail or notification, dispatches no domain event, calls no
 * driver, finds the run's records through its own tables, and does nothing the second time it runs for a run.
 */
final class MigrationRunCompleted implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * $counts is keyed by entity, the importer's registry key: `client`, `user`, `credit_balance`, `invoice`,
     * `invoice_item`, `payment`, `domain`. More keys come with later importers, and consumers ignore those they do not
     * know.
     *
     * @param  array<string, array{created: int, updated: int, unchanged: int, skipped: int, failed: int}>  $counts
     */
    public function __construct(
        public int $runId,
        public string $sourceSystem,
        public array $counts,
    ) {}
}
