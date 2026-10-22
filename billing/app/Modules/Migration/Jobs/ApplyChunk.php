<?php

namespace App\Modules\Migration\Jobs;

use App\Contracts\Migration\RecordImporter;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportIssue;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Enums\ImportStopReason;
use App\Events\Migration\MigrationRunCompleted;
use App\Events\Migration\MigrationRunPaused;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\SourceIds;
use App\Support\Migration\ImportRegistry;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies CHUNK rows of a confirmed run (client-import spec §5.6), then queues the next chunk. Each row runs in its
 * own transaction with its outcome, so a retried chunk skips the rows already applied and never creates a client,
 * login or id-map row twice. A new client whose email another client took after the review fails with that reason.
 */
final class ApplyChunk implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const CHUNK = 200;

    public int $tries = 3;

    public int $timeout = Queues::IMPORT_JOB_TIMEOUT_SECONDS;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $runId, public int $afterRow = 0)
    {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(ImportRegistry $registry, ActivityLogger $audit, Settings $settings): void
    {
        $run = MigrationRun::query()->with('source')->find($this->runId);

        if ($run?->status !== MigrationRun::APPLYING || $run->applied_at === null) {
            return;
        }

        $records = $run->records()->where('row_number', '>', $this->afterRow)->orderBy('row_number')->limit(self::CHUNK)->get();

        if ($records->isEmpty()) {
            $this->finish($run, $audit, (int) $settings->get('migration.undo_days'));

            return;
        }

        $context = new ImportContext($run->id, $run->migration_source_id, $run->source_system, false, $run->applied_at, null, new SourceIds($run->migration_source_id),
            ['blank_cells' => (string) ($run->options['blank_cells'] ?? 'keep'), 'source_name' => $run->source->name], $run->applied_by);
        $clients = $registry->get('client');
        $logins = ($run->options['create_logins'] ?? true) && $registry->has('user') ? $registry->get('user') : null;
        // Without Billing no opening balance is posted; the check warned on each row that has one.
        $credits = $registry->has('credit_balance') ? $registry->get('credit_balance') : null;
        $pending = $records->whereNull('outcome');
        $emails = $pending->where('decision', 'create')->map(fn (MigrationRecord $record): string => (string) ($record->normalized['client']['email'] ?? ''))->filter()->values()->all();
        $taken = array_fill_keys(Client::query()->whereIn(DB::raw('lower(email)'), $emails)->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->all(), true);
        $actor = $run->applied_by === null ? null : User::query()->find($run->applied_by);

        foreach ($pending as $record) {
            DB::transaction(function () use ($run, $record, $context, $clients, $logins, $credits, $taken, $audit, $actor): void {
                // Claim the row: another job for this run (a redelivery, a Continue) may have applied it meanwhile.
                $claimed = MigrationRecord::query()->whereKey($record->id)->whereNull('outcome')->lockForUpdate()->exists()
                    && MigrationRun::query()->whereKey($run->id)->where('status', MigrationRun::APPLYING)->exists();

                if ($claimed) {
                    $this->applyRow($run, $record, $context, $clients, $logins, $credits, $taken, $audit, $actor);
                }
            });
        }

        $this->count($run);
        dispatch(new self($run->id, (int) $records->last()->row_number));
    }

    public function failed(?Throwable $exception): void
    {
        $stopped = MigrationRun::query()->whereKey($this->runId)->where('status', MigrationRun::APPLYING)
            ->update(['status' => MigrationRun::FAILED, 'error' => __('Importing stopped before the end. Continue to import the rest; rows already imported are not imported again.')]);

        if ($stopped === 1) {
            MigrationRunPaused::dispatch($this->runId, ImportStopReason::ApplyFailed);
        }
    }

    /**
     * @param  array<string, true>  $taken  emails clients hold now
     */
    private function applyRow(MigrationRun $run, MigrationRecord $record, ImportContext $context, RecordImporter $clients, ?RecordImporter $logins, ?RecordImporter $credits, array $taken, ActivityLogger $audit, ?User $actor): void
    {
        $normalized = $record->normalized ?? [];
        $client = $normalized['client'] ?? [];
        $extra = $normalized['extra'] ?? [];
        $issues = [];

        if (! in_array($record->decision, ['create', 'update'], true) || ($record->decision === 'create' && isset($taken[mb_strtolower((string) ($client['email'] ?? ''))]))) {
            $failed = $record->decision === 'create' || $record->status === 'error';
            $problems = $record->decision === 'create' ? [...($record->problems ?? []), ['severity' => 'error', 'code' => 'taken', 'message' => __('A client with this email was added after the review. Nothing was created.')]] : $record->problems;
            $record->forceFill(['outcome' => $failed ? 'failed' : ($record->decision === 'unchanged' ? 'unchanged' : 'skipped'), 'problems' => $problems, 'applied_at' => now()])->save();

            return;
        }

        $creating = $record->decision === 'create';
        // A new client takes the defaults for what the file does not map; an update changes only mapped fields, and
        // never the email (the importer refuses it too).
        $fields = $creating ? [...($normalized['defaults'] ?? []), ...$client] : Arr::except($client, 'email');
        $outcome = $clients->upsert(new ImportRecord('client', $run->source_system, (string) $record->source_id, [
            ...Arr::except($fields, 'client_group_create'),
            'client_group_name' => $client['client_group_create'] ?? null,
            'signup_date' => $extra['signup_date'] ?? null,
            'imported_fields' => $extra['imported_fields'] ?? [],
            'marketing_consent' => $extra['marketing_consent'] ?? null,
            'marketing_consent_date' => $extra['marketing_consent_date'] ?? null,
            'marketing_consent_evidence' => $extra['marketing_consent_evidence'] ?? null,
            'blank' => $normalized['blank'] ?? [],
        ], checksum: (string) $record->checksum), $context, $creating ? null : $record->match_client_id);
        $applied = ['client' => $outcome];

        if ($outcome->action === ImportAction::Created && $logins !== null && ($fields['status'] ?? 'active') !== 'closed') {
            $applied['user'] = $logins->upsert(new ImportRecord('user', $run->source_system, (string) $record->source_id, [
                'email' => $client['email'], 'name' => trim(($client['first_name'] ?? '').' '.($client['last_name'] ?? '')),
                'verified' => ($run->options['trust_verified'] ?? false) && ($extra['email_verified'] ?? null) === true,
                'link_existing' => $record->link_login === true,
            ], ['client_id' => $outcome->targetId]), $context, null);
        }

        if ($credits !== null && ($extra['credit_balance'] ?? null) !== null && in_array($outcome->action, [ImportAction::Created, ImportAction::Updated, ImportAction::Unchanged], true)) {
            // A client this run created gets its opening balance; an existing one only when Billing finds it safe (§6.12).
            $applied['credit_balance'] = $credits->upsert(new ImportRecord('credit_balance', $run->source_system, (string) $record->source_id, [
                'amount' => (int) $extra['credit_balance'], 'currency' => $fields['currency'] ?? null,
            ], ['client_id' => $outcome->targetId]), $context, $outcome->action === ImportAction::Created ? null : $outcome->targetId);
        }

        $issues = array_merge(...array_values(array_map(fn (ImportOutcome $entity): array => $entity->issues, $applied)));
        $changes = array_values(array_unique(array_merge(...array_values(array_map(fn (ImportOutcome $entity): array => $entity->changes, $applied)))));
        // The row is an update when any importer wrote something to a client that already existed.
        $action = $outcome->action === ImportAction::Unchanged && $changes !== [] ? ImportAction::Updated : $outcome->action;

        foreach ($applied as $entity => $entityOutcome) {
            // Logins are reached through their client; every other entity a row wrote has its own id-map row.
            if ($entity === 'client' || ($entity !== 'user' && in_array($entityOutcome->action, [ImportAction::Created, ImportAction::Updated], true))) {
                $this->map($run, $record, $entity, $entityOutcome);
            }
        }

        $audit->log($action === ImportAction::Created ? 'Client created by import' : 'Client updated by import', null, $outcome->targetId,
            ['run' => $run->id, 'row' => $record->row_number, 'changes' => $changes], 'migration.client_'.$action->value, $actor);
        $record->forceFill([
            'outcome' => $action->value,
            'target_id' => $outcome->targetId,
            // Per entity, so undo hands each importer its own outcome (ADR 0019 addendum). The importers read the
            // snapshots under their own row locks; Migration never reads the client or the ledger for undo.
            'entities' => array_map(fn (ImportOutcome $entity): array => ['action' => $entity->action->value, 'target_id' => $entity->targetId, 'changes' => $entity->changes], $applied),
            'before' => array_filter(array_map(fn (ImportOutcome $entity): ?array => $entity->before, $applied), fn (?array $before): bool => $before !== null) ?: null,
            'problems' => [...($record->problems ?? []), ...array_map(fn (ImportIssue $issue): array => ['severity' => $issue->severity->value, 'code' => $issue->code, 'message' => $issue->message], $issues)],
            'changes' => $changes,
            'applied_at' => now(),
        ])->save();
    }

    private function map(MigrationRun $run, MigrationRecord $record, string $entity, ImportOutcome $outcome): void
    {
        $map = MigrationIdMap::query()->firstOrNew(['migration_source_id' => $run->migration_source_id, 'entity' => $entity, 'source_id' => (string) $record->source_id]);
        $map->forceFill([
            'target_id' => $outcome->targetId,
            'checksum' => (string) $record->checksum,
            'first_run_id' => $map->first_run_id ?? $run->id,
            'last_run_id' => $run->id,
            'synced_at' => now(),
        ])->save();
    }

    private function count(MigrationRun $run): void
    {
        $outcomes = $run->records()->whereNotNull('outcome')->groupBy('outcome')->selectRaw('outcome, count(*) as total')->pluck('total', 'outcome');
        $run->forceFill([
            'created_count' => (int) ($outcomes['created'] ?? 0),
            'updated_count' => (int) ($outcomes['updated'] ?? 0),
            'skipped_count' => (int) ($outcomes['skipped'] ?? 0),
            'failed_count' => (int) ($outcomes['failed'] ?? 0),
            'heartbeat_at' => now(),
        ])->save();
    }

    /**
     * The applying → completed transition, once: of two jobs that reach the end, only the one whose conditional update
     * changed the run writes the audit entry and dispatches the event, after the commit.
     */
    private function finish(MigrationRun $run, ActivityLogger $audit, int $undoDays): void
    {
        DB::transaction(function () use ($run, $audit, $undoDays): void {
            $this->count($run);
            $undoUntil = now()->addDays($undoDays);
            $completed = MigrationRun::query()->whereKey($run->id)->where('status', MigrationRun::APPLYING)
                ->update(['status' => MigrationRun::COMPLETED, 'undo_until' => $undoUntil, 'expires_at' => $undoUntil]);

            if ($completed !== 1) {
                return;
            }

            $run->refresh();
            $audit->log('Client import applied', $run, properties: ['file' => $run->file_name, 'created' => $run->created_count, 'updated' => $run->updated_count, 'failed' => $run->failed_count], event: 'migration.completed');
            MigrationRunCompleted::dispatch($run->id, $run->source_system, [$run->entity => [
                'created' => $run->created_count, 'updated' => $run->updated_count, 'unchanged' => $run->records()->where('outcome', 'unchanged')->count(),
                'skipped' => $run->skipped_count, 'failed' => $run->failed_count,
            ], ...$this->entityCounts($run)]);
        });
    }

    /**
     * Outcomes of the other entities a run wrote alongside its clients (logins, opening balances), counted once at the
     * end from each applied row's per-entity outcomes.
     *
     * @return array<string, array{created: int, updated: int, unchanged: int, skipped: int, failed: int}>
     */
    private function entityCounts(MigrationRun $run): array
    {
        $counts = [];

        $run->records()->whereNotNull('entities')->select(['id', 'entities'])->lazyById(500)->each(function (MigrationRecord $record) use ($run, &$counts): void {
            foreach ($record->entities ?? [] as $entity => $outcome) {
                if ($entity === $run->entity) {
                    continue;
                }

                $counts[$entity] ??= ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

                match ($outcome['action']) {
                    'created' => $counts[$entity]['created']++,
                    'updated' => $counts[$entity]['updated']++,
                    'unchanged' => $counts[$entity]['unchanged']++,
                    default => $counts[$entity]['skipped']++,
                };
            }
        });

        return $counts;
    }
}
