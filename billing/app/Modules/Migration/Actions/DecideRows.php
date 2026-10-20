<?php

namespace App\Modules\Migration\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ClientMatcher;
use App\Modules\Migration\Support\ReviewDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff decisions on checked rows before the import (client-import spec §5.5): create, update the matched client, or
 * skip, where the row allows it. Each change refreshes the counts and the review digest, so a confirm sent with the
 * page's earlier digest is refused.
 */
class DecideRows
{
    public function __construct(private ClientMatcher $matcher, private ActivityLogger $audit) {}

    /**
     * What staff may choose for a row: skip always; update when it matched one client; create when no client has its
     * email (or only a company or tax ID matched). Rows with errors and unchanged rows have no choice.
     *
     * @return list<string>
     */
    public static function choices(MigrationRecord $record): array
    {
        $codes = array_column($record->problems ?? [], 'code');

        if ($record->status === 'error' || in_array($record->decision, [null, 'unchanged'], true) || in_array('several_matches', $codes, true)) {
            return $record->decision === 'needs_decision' ? ['skip'] : [];
        }

        return array_values(array_filter([
            ($record->match_client_id === null || in_array('possible_duplicate', $codes, true)) ? 'create' : null,
            $record->match_client_id !== null ? 'update' : null,
            'skip',
        ]));
    }

    public function one(MigrationRun $run, int $rowNumber, string $decision): void
    {
        DB::transaction(function () use ($run, $rowNumber, $decision): void {
            $locked = $this->ready($run);
            $record = $locked->records()->where('row_number', $rowNumber)->lockForUpdate()->firstOrFail();

            if (! in_array($decision, self::choices($record), true)) {
                throw ValidationException::withMessages(['decision' => __('Row :row cannot be set to that.', ['row' => number_format($rowNumber)])]);
            }

            $changes = [];

            if ($decision === 'update') {
                $client = Client::query()->findOrFail($record->match_client_id);
                $merged = in_array('merged', array_column($record->problems ?? [], 'code'), true);
                $changes = $this->matcher->changes(['client' => $record->normalized['client'] ?? [], 'blank' => $record->normalized['blank'] ?? []],
                    $client, ($locked->options['blank_cells'] ?? 'keep') === 'keep', $merged ? ['email'] : []);
            }

            $record->forceFill(['decision' => $decision === 'update' && $changes === [] ? 'unchanged' : $decision, 'changes' => $changes])->save();
            $this->refresh($locked);
        });

        $this->audit->log('Client import row decided', $run, properties: ['file' => $run->file_name, 'row' => $rowNumber, 'decision' => $decision], event: 'migration.row_decided');
    }

    /**
     * Accepts the proposal of every flagged row that has a safe one: a row whose email matched no client but whose
     * company name or tax ID did is created. Rows matched to a closed client, to several clients, or to a client whose
     * email changed recently are left for staff, one by one.
     *
     * @return array{accepted: int, left: int}
     */
    public function acceptProposed(MigrationRun $run): array
    {
        $result = DB::transaction(function () use ($run): array {
            $locked = $this->ready($run);
            $accepted = 0;

            foreach ($locked->records()->where('decision', 'needs_decision')->select(['id', 'problems'])->lazyById(500) as $record) {
                if (in_array('possible_duplicate', array_column($record->problems ?? [], 'code'), true)) {
                    $record->forceFill(['decision' => 'create', 'changes' => []])->save();
                    $accepted++;
                }
            }

            $this->refresh($locked);

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
            $count = $locked->records()->where('decision', 'needs_decision')->update(['decision' => 'skip']);
            $this->refresh($locked);

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
     * Counts by decision, the tax-exempt count (decrypting only the rows marked exempt-capable is not possible, so
     * the rows that will be written are read once), and a new digest.
     */
    private function refresh(MigrationRun $run): void
    {
        $decisions = $run->records()->groupBy('decision')->selectRaw('decision, count(*) as total')->pluck('total', 'decision');
        $taxExempt = 0;

        foreach ($run->records()->whereIn('decision', ['create', 'update'])->select(['id', 'normalized'])->lazyById(1000) as $record) {
            $taxExempt += (int) (($record->normalized['client']['tax_exempt'] ?? false) === true);
        }

        $run->forceFill([
            'create_count' => (int) ($decisions['create'] ?? 0),
            'update_count' => (int) ($decisions['update'] ?? 0),
            'unchanged_count' => (int) ($decisions['unchanged'] ?? 0),
            'skip_count' => (int) ($decisions['skip'] ?? 0),
            'decision_count' => (int) ($decisions['needs_decision'] ?? 0),
            'tax_exempt_count' => $taxExempt,
        ])->save();
        $run->forceFill(['review_digest' => ReviewDigest::of($run)])->save();
    }
}
