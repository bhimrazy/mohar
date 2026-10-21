<?php

namespace App\Modules\Migration\Actions;

use App\Models\User;
use App\Modules\Core\Import\LoginImporter;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ClientMatcher;
use App\Modules\Migration\Support\ReviewDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/**
 * Staff decisions on checked rows before the import (client-import spec §5.5): create, update the matched client,
 * skip, or, when a login has the row's email, create and link that login or create without one (§6.11). Decision
 * state lives in plain columns, so a decision decrypts at most the one row it changes; the counts come from those
 * columns and the digest from the rows' stored hashes (ReviewDigest), so a confirm sent with an earlier digest is
 * refused. Every change runs under the run's row lock, the lock confirm and the apply jobs take.
 */
class DecideRows
{
    /** The choices that create the client, with what each puts in link_login. */
    private const CREATES = ['create' => null, 'link' => true, 'no_link' => false];

    private const LOGIN_CODES = ['existing_login', 'existing_login_recent'];

    public function __construct(private ClientMatcher $matcher, private ActivityLogger $audit) {}

    /**
     * What staff may choose for a row: skip always; update when it matched one client; create when no client has its
     * email (or only a company or tax ID matched), offered as "link" and "no link" when a login has the email, and as
     * "no link" alone when that login cannot be linked. Rows with errors and unchanged rows have no choice.
     *
     * @return list<string>
     */
    public static function choices(MigrationRecord $record): array
    {
        $codes = array_column($record->problems ?? [], 'code');

        if ($record->status === 'error' || in_array($record->decision, [null, 'unchanged'], true) || in_array('several_matches', $codes, true)) {
            return $record->decision === 'needs_decision' ? ['skip'] : [];
        }

        $creates = match (true) {
            in_array('existing_login', $codes, true) => ['link', 'no_link'],
            in_array('existing_login_recent', $codes, true) => ['no_link'],
            default => ['create'],
        };

        return [
            ...($record->match_client_id === null || in_array('possible_duplicate', $codes, true) ? $creates : []),
            ...($record->match_client_id !== null ? ['update'] : []),
            'skip',
        ];
    }

    public function one(MigrationRun $run, int $rowNumber, string $choice): void
    {
        DB::transaction(function () use ($run, $rowNumber, $choice): void {
            $locked = $this->ready($run);
            $record = $locked->records()->where('row_number', $rowNumber)->lockForUpdate()->firstOrFail();

            if (! in_array($choice, self::choices($record), true)) {
                throw ValidationException::withMessages(['decision' => __('Row :row cannot be set to that.', ['row' => number_format($rowNumber)])]);
            }

            // The rule is checked again now, with the login as it is: the review may be hours old.
            if ($choice === 'link' && ! LoginImporter::linkable(User::query()->where('email', mb_strtolower((string) ($record->normalized['client']['email'] ?? '')))->first())) {
                throw ValidationException::withMessages(['decision' => __('Row :row: the login with this email can no longer be linked, because its address was verified less than 30 days ago or never. Create the client without a login, or skip the row.', ['row' => number_format($rowNumber)])]);
            }

            $decision = array_key_exists($choice, self::CREATES) ? 'create' : $choice;
            $linkLogin = self::CREATES[$choice] ?? null;
            $changes = [];

            if ($choice === 'update') {
                $client = Client::query()->findOrFail($record->match_client_id);
                $changes = $this->matcher->changes(['client' => $record->normalized['client'] ?? [], 'blank' => $record->normalized['blank'] ?? []], $client, ($locked->options['blank_cells'] ?? 'keep') === 'keep');
                $decision = $changes === [] ? 'unchanged' : 'update';
            }

            $hash = ReviewDigest::row($locked, $record->row_number, $decision, $record->checksum, $record->match_client_id, $linkLogin);
            $digest = ReviewDigest::replace($locked->review_digest, $record->review_hash, $hash);
            $record->forceFill(['decision' => $decision, 'changes' => $changes, 'link_login' => $linkLogin, 'review_hash' => $hash])->save();
            $this->refresh($locked, $digest);
        });

        $this->audit->log('Client import row decided', $run, properties: ['file' => $run->file_name, 'row' => $rowNumber, 'decision' => $choice], event: 'migration.row_decided');
    }

    /**
     * Accepts the proposal of every flagged row that has a safe one: a row whose email matched no client but whose
     * company name or tax ID did is created. Rows matched to a closed client, to several clients, to a client whose
     * email changed recently, and rows whose email belongs to a login are left for staff, one by one.
     *
     * @return array{accepted: int, left: int}
     */
    public function acceptProposed(MigrationRun $run): array
    {
        $result = DB::transaction(function () use ($run): array {
            $locked = $this->ready($run);
            $accepted = 0;
            $digest = $locked->review_digest;
            $proposed = $this->undecided($locked)->filter(function (MigrationRecord $record): bool {
                $codes = array_column($record->problems ?? [], 'code');

                return in_array('possible_duplicate', $codes, true) && array_intersect($codes, self::LOGIN_CODES) === [];
            });

            foreach ($proposed->chunk(500) as $batch) {
                $digest = $this->rewrite($locked, $batch->values()->all(), 'create', $digest);
                $accepted += $batch->count();
            }

            $this->refresh($locked, $digest);

            return ['accepted' => $accepted, 'left' => $locked->decision_count];
        });

        $this->audit->log('Client import proposals accepted', $run, properties: ['file' => $run->file_name, ...$result], event: 'migration.proposals_accepted');

        return $result;
    }

    /**
     * Skips every row that still needs a decision; returns how many.
     */
    public function skipUndecided(MigrationRun $run): int
    {
        $skipped = DB::transaction(function () use ($run): int {
            $locked = $this->ready($run);
            $count = 0;
            $digest = $locked->review_digest;

            foreach ($this->undecided($locked)->chunk(500) as $batch) {
                $digest = $this->rewrite($locked, $batch->values()->all(), 'skip', $digest);
                $count += $batch->count();
            }

            $this->refresh($locked, $digest);

            return $count;
        });

        $this->audit->log('Client import rows needing a decision skipped', $run, properties: ['file' => $run->file_name, 'rows' => $skipped], event: 'migration.rows_skipped');

        return $skipped;
    }

    private function ready(MigrationRun $run): MigrationRun
    {
        $locked = MigrationRun::query()->lockForUpdate()->findOrFail($run->id);

        if ($locked->status !== MigrationRun::READY) {
            throw ValidationException::withMessages(['decision' => __('Rows can be decided only while the import waits for review.')]);
        }

        return $locked;
    }

    /**
     * The plain columns of the rows that need a decision, never their payload.
     *
     * @return LazyCollection<int, MigrationRecord>
     */
    private function undecided(MigrationRun $run): LazyCollection
    {
        return $run->records()->where('decision', 'needs_decision')->select(['id', 'row_number', 'checksum', 'match_client_id', 'problems', 'review_hash'])->lazyById(500);
    }

    /**
     * Gives every row of the batch the same decision in one statement (each row keeps its own review hash), without
     * a login link, and returns the digest with those rows' hashes replaced.
     *
     * @param  array<int, MigrationRecord>  $records
     */
    private function rewrite(MigrationRun $run, array $records, string $decision, ?string $digest): string
    {
        $cases = [];
        $bindings = [];

        foreach ($records as $record) {
            $hash = ReviewDigest::row($run, $record->row_number, $decision, $record->checksum, $record->match_client_id, null);
            $digest = ReviewDigest::replace($digest, $record->review_hash, $hash);
            $cases[] = 'when ? then ?';
            array_push($bindings, $record->id, $hash);
        }

        $ids = array_map(fn (MigrationRecord $record): int => $record->id, $records);
        DB::update('update migration_records set decision = ?, changes = ?, link_login = null, review_hash = case id '.implode(' ', $cases).' end where id in ('.implode(', ', array_fill(0, count($ids), '?')).')',
            [$decision, '[]', ...$bindings, ...$ids]);

        return (string) $digest;
    }

    /**
     * Counts by decision and the tax-exempt count, from plain columns, and the digest the caller kept up to date.
     */
    private function refresh(MigrationRun $run, string $digest): void
    {
        $decisions = $run->records()->groupBy('decision')->selectRaw('decision, count(*) as total')->pluck('total', 'decision');

        $run->forceFill([
            'create_count' => (int) ($decisions['create'] ?? 0),
            'update_count' => (int) ($decisions['update'] ?? 0),
            'unchanged_count' => (int) ($decisions['unchanged'] ?? 0),
            'skip_count' => (int) ($decisions['skip'] ?? 0),
            'decision_count' => (int) ($decisions['needs_decision'] ?? 0),
            'tax_exempt_count' => $run->records()->where('tax_exempt', true)->whereIn('decision', ['create', 'update'])->count(),
            'review_digest' => $digest,
        ])->save();
    }
}
