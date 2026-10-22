<?php

namespace App\Modules\Migration\Jobs;

use App\Contracts\Billing\TaxIdRules;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ClientColumns;
use App\Modules\Migration\Support\ClientMatcher;
use App\Modules\Migration\Support\ReviewDigest;
use App\Modules\Migration\Support\RowNormalizer;
use App\Support\Migration\ImportRegistry;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks a run's rows under its mapping, CHUNK rows per job by row number (client-import spec §5.4): normalise and
 * validate each row, apply the duplicate rules, and write status, decision and problems with a fixed number of
 * queries per chunk. Each chunk queues the next; the last sets the counts. A job from an earlier mapping (an older
 * generation) stops without writing.
 */
final class CheckRun implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const CHUNK = 1000;

    /** Values of each kind remembered for the map step's decisions. */
    private const MAX_FOUND_VALUES = 50;

    public int $tries = 3;

    public int $timeout = Queues::IMPORT_JOB_TIMEOUT_SECONDS;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $runId, public int $generation, public int $afterRow = 0)
    {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(ClientMatcher $matcher): void
    {
        $run = MigrationRun::query()->find($this->runId);

        if ($run?->status !== MigrationRun::CHECKING || $run->generation !== $this->generation) {
            return;
        }

        $records = $run->records()->where('row_number', '>', $this->afterRow)->orderBy('row_number')->limit(self::CHUNK)->get(['id', 'row_number', 'raw']);

        if ($records->isEmpty()) {
            $this->finish($run);

            return;
        }

        $columns = $run->mapping['columns'] ?? [];
        $hasExternalId = in_array(ClientColumns::EXTERNAL_ID, $columns, true);
        $normalizer = $this->normalizer($run, $columns);
        $imports = app(ImportRegistry::class);
        $found = $this->afterRow === 0 ? [] : ($run->found_values ?? []);
        $rows = [];

        foreach ($records as $record) {
            $normalized = $normalizer->normalize($record->raw ?? []);
            $email = $normalized['client']['email'] ?? null;
            $hash = is_string($email) ? MigrationRecord::emailHash($email) : null;
            $sourceId = $hasExternalId ? ($normalized['extra'][ClientColumns::EXTERNAL_ID] ?? null) : ($hash === null ? null : "email:{$hash}");

            if (($normalized['extra']['credit_balance'] ?? null) !== null && ! $imports->has('credit_balance')) {
                $normalized['problems'][] = ['severity' => 'warning', 'code' => 'credit_unavailable', 'message' => __('The opening credit balance will not be imported: billing is not installed.'), 'field' => 'credit_balance'];
            }

            if ($hasExternalId && $sourceId === null) {
                $normalized['problems'][] = ['severity' => 'error', 'code' => 'no_external_id', 'message' => __('The External ID is empty.'), 'field' => ClientColumns::EXTERNAL_ID];
            }

            foreach ($normalized['found'] as $kind => $values) {
                foreach ($values as $value) {
                    if (isset($found[$kind][$value]) || count($found[$kind] ?? []) < self::MAX_FOUND_VALUES) {
                        $found[$kind][$value] = ($found[$kind][$value] ?? 0) + 1;
                    }
                }
            }

            $payload = ['client' => $normalized['client'], 'defaults' => $normalized['defaults'], 'extra' => $normalized['extra'], 'blank' => $normalized['blank']];
            $rows[] = [
                'row' => $record->row_number,
                'source_id' => $sourceId ?? "row:{$record->row_number}",
                'email_hash' => $hash,
                'client' => $normalized['client'],
                'blank' => $normalized['blank'],
                'problems' => $normalized['problems'],
                'payload' => $payload,
                'checksum' => hash('sha256', (string) json_encode([$normalized['client'], $normalized['extra']])),
            ];
        }

        $decisions = $matcher->decide($run, $rows, $hasExternalId);
        $writes = [];

        foreach ($rows as $index => $row) {
            $decision = $decisions[$index];
            $severities = array_column($decision['problems'], 'severity');
            $writes[] = [
                'migration_run_id' => $run->id,
                'row_number' => $row['row'],
                'normalized' => Crypt::encryptString((string) json_encode($row['payload'], JSON_INVALID_UTF8_SUBSTITUTE)),
                'email_hash' => $row['email_hash'],
                'source_id' => mb_substr($row['source_id'], 0, 191),
                'checksum' => $row['checksum'],
                'status' => in_array('error', $severities, true) ? 'error' : (in_array('warning', $severities, true) ? 'warning' : 'ok'),
                'decision' => $decision['decision'],
                'match_client_id' => $decision['match_client_id'],
                'problems' => json_encode($decision['problems']),
                // An update also moves the opening balance to the file's amount, when Billing finds it can (§6.12).
                'changes' => json_encode($decision['decision'] === 'update' && ($row['payload']['extra']['credit_balance'] ?? null) !== null ? [...$decision['changes'], 'credit_balance'] : $decision['changes']),
                'tax_exempt' => ($row['client']['tax_exempt'] ?? false) === true,
                'link_login' => null,
                'review_hash' => ReviewDigest::row($run, $row['row'], $decision['decision'], $row['checksum'], $decision['match_client_id'], null),
            ];
        }

        $last = $records->last()->row_number;
        // Write only while this check is still the run's current one: a new mapping (a new generation) may have
        // started while this chunk was being checked.
        $current = DB::transaction(function () use ($run, $writes, $records, $found): bool {
            $locked = MigrationRun::query()->lockForUpdate()->find($run->id);

            if ($locked?->status !== MigrationRun::CHECKING || $locked->generation !== $this->generation) {
                return false;
            }

            // One statement per chunk: 11 values per row stays far below the SQLite and PostgreSQL parameter limits.
            MigrationRecord::query()->upsert($writes, ['migration_run_id', 'row_number'], ['normalized', 'email_hash', 'source_id', 'checksum', 'status', 'decision', 'match_client_id', 'problems', 'changes', 'tax_exempt', 'link_login', 'review_hash']);

            $locked->forceFill([
                'checked_count' => ($this->afterRow === 0 ? 0 : $locked->checked_count) + $records->count(),
                'found_values' => $found,
                'heartbeat_at' => now(),
            ])->save();

            return true;
        });

        if ($current) {
            dispatch(new self($run->id, $this->generation, $last));
        }
    }

    public function failed(?Throwable $exception): void
    {
        MigrationRun::query()->whereKey($this->runId)->where('status', MigrationRun::CHECKING)->where('generation', $this->generation)
            ->update(['status' => MigrationRun::FAILED, 'error' => __('Checking stopped before the end. Continue to check the rest.')]);
    }

    /**
     * Counts by decision and status; the run is ready, or back on the map step while group names need a decision.
     */
    private function finish(MigrationRun $run): void
    {
        $decisions = $run->records()->groupBy('decision')->selectRaw('decision, count(*) as total')->pluck('total', 'decision');
        $statuses = $run->records()->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        $run->forceFill([
            'status' => ($run->found_values['groups'] ?? []) === [] ? MigrationRun::READY : MigrationRun::MAPPING,
            'create_count' => (int) ($decisions['create'] ?? 0),
            'update_count' => (int) ($decisions['update'] ?? 0),
            'unchanged_count' => (int) ($decisions['unchanged'] ?? 0),
            'skip_count' => (int) ($decisions['skip'] ?? 0),
            'decision_count' => (int) ($decisions['needs_decision'] ?? 0),
            'error_count' => (int) ($statuses['error'] ?? 0),
            'warning_count' => (int) ($statuses['warning'] ?? 0),
            'tax_exempt_count' => $run->records()->where('tax_exempt', true)->whereIn('decision', ['create', 'update'])->count(),
            'heartbeat_at' => now(),
        ])->save();
        $run->forceFill(['review_digest' => ReviewDigest::of($run)])->save();
    }

    /**
     * @param  list<string>  $columns
     */
    private function normalizer(MigrationRun $run, array $columns): RowNormalizer
    {
        $enabled = Currency::query()->orderByDesc('is_default')->orderBy('id')->pluck('code')->all();
        $currencies = array_combine($enabled, $enabled);

        foreach ($run->options['currencies'] ?? [] as $from => $to) {
            if (in_array($to, $enabled, true)) {
                $currencies[strtoupper((string) $from)] = $to;
            }
        }

        $groups = ClientGroup::query()->orderBy('id')->get(['id', 'name'])
            ->mapWithKeys(fn (ClientGroup $group): array => [mb_strtolower($group->name) => $group->id])->all();

        foreach ($run->options['groups'] ?? [] as $name => $choice) {
            $groups[mb_strtolower((string) $name)] ??= $choice;
        }

        return new RowNormalizer($run->header ?? [], $columns, $run->mapping['date_format'] ?? null, $currencies, $enabled[0] ?? 'USD', $groups,
            array_values(array_unique([config('app.locale'), config('app.fallback_locale')])), app(TaxIdRules::class));
    }
}
