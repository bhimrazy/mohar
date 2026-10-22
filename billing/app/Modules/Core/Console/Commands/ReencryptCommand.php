<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Models\ReencryptionCheckpoint;
use App\Modules\Core\Security\EncryptedTargets;
use App\Modules\Core\Security\Reencryptor;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Providers\ModulesServiceProvider;
use App\Support\Security\EncryptedColumn;
use App\Support\Security\EncryptedFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Re-encrypts every stored secret with the current APP_KEY after a rotation (docs/devops/key-rotation.md).
 *
 * Exit codes: 0 every value is on the current key; 1 some value no key decrypts, a write failed its check (those
 * values are unchanged), or a registration is broken; 2 invalid options, no APP_KEY, or (without --partial) some
 * modules are not loaded; 3 work remains (a dry run found values on an old key, --limit stopped the run, or the app
 * wrote values during it: run it again); 4 another run holds the lock.
 */
#[Signature('security:reencrypt
    {--dry-run : Count the values per column and key without changing anything}
    {--only=* : Only these targets (table.column, or table.files), repeatable}
    {--chunk=500 : Rows read per query}
    {--limit= : Stop after this many values; the next run resumes}
    {--restart : Ignore the checkpoints of an interrupted run}
    {--partial : Run although some modules are not loaded (their storage is skipped)}')]
#[Description('Re-encrypt stored secrets (encrypted columns, secret settings, encrypted files) with the current APP_KEY')]
class ReencryptCommand extends Command
{
    public const INCOMPLETE = 3;

    public const LOCKED = 4;

    private const LOCK = 'security:reencrypt';

    public function handle(EncryptedTargets $discovery, ActivityLogger $logger, Settings $settings): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $limit = $this->option('limit') === null ? null : filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($chunk === false || $limit === false) {
            $this->components->error('--chunk must be between 1 and 10000, and --limit a positive number.');

            return self::INVALID;
        }

        if ((string) config('app.key') === '') {
            $this->components->error('APP_KEY is not set (nor readable from APP_KEY_FILE).');

            return self::INVALID;
        }

        try {
            $targets = $discovery->all();
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $skipped = array_values(array_diff(ModulesServiceProvider::availableModules(), ModulesServiceProvider::enabledModules()));

        if ($skipped !== [] && ! $dryRun && ! $this->option('partial')) {
            $this->components->error('Modules not loaded: '.implode(', ', $skipped).'. Their encrypted storage would stay on the old key. Load every module (unset MODULES), or pass --partial.');

            return self::INVALID;
        }

        $only = array_values(array_filter((array) $this->option('only')));

        if ($unknown = array_diff($only, array_keys($targets))) {
            $this->components->error('Unknown target: '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys($targets)).'.');

            return self::INVALID;
        }

        if ($only !== []) {
            $targets = array_intersect_key($targets, array_flip($only));
        }

        $lock = Cache::lock(self::LOCK, 6 * 3600);

        if (! $dryRun && ! $lock->get()) {
            $this->components->error('Another security:reencrypt run is in progress.');

            return self::LOCKED;
        }

        try {
            return $this->reencrypt($targets, $dryRun, $chunk, $limit, $skipped, $logger, $settings);
        } finally {
            $dryRun || $lock->release();
        }
    }

    /**
     * @param  array<string, EncryptedColumn|EncryptedFiles>  $targets
     */
    /**
     * @param  list<string>  $skipped  modules not loaded
     */
    private function reencrypt(array $targets, bool $dryRun, int $chunk, ?int $budget, array $skipped, ActivityLogger $logger, Settings $settings): int
    {
        $reencryptor = app(Reencryptor::class);
        $results = [];
        $rows = [];
        $complete = true;

        foreach ($targets as $name => $target) {
            $connection = $target->connection;

            if (! Schema::connection($connection)->hasTable($target->table)) {
                $rows[] = [$name, 'no table', '', '', '', '', ''];

                continue;
            }

            $result = $reencryptor->process($name, $target, $dryRun, $chunk, $budget, (bool) $this->option('restart'));
            $results[$name] = $result['counts'];
            $complete = $complete && $result['complete'];
            $c = $result['counts'];
            $rows[] = [$name.($result['complete'] ? '' : ' (not finished)'), $c['values'], $c['current'], $c['old_key'], $dryRun ? '-' : $c['reencrypted'], $c['undecryptable'], $dryRun ? '-' : $c['changed'] + $c['failed'] + $c['missing']];

            if ($result['undecryptable_ids'] !== []) {
                $this->components->warn("{$name}: no key decrypts the value of ".implode(', ', $result['undecryptable_ids']).(count($result['undecryptable_ids']) < $c['undecryptable'] ? ', ...' : '').' (left unchanged).');
            }
        }

        $this->table(['Target', 'Values', 'Current key', 'Old key', 'Re-encrypted', 'Undecryptable', 'Changed / failed / missing'], $rows);

        $totals = Reencryptor::emptyCounts();

        foreach ($results as $counts) {
            foreach ($counts as $key => $count) {
                $totals[$key] += $count;
            }
        }

        if (! $dryRun) {
            if ($complete) {
                ReencryptionCheckpoint::query()->whereIn('target', array_keys($results))->delete();
            }

            $settings->forgetCached();
            $logger->log('Stored secrets were re-encrypted with the current application key.', properties: [
                'complete' => $complete,
                'skipped_modules' => $skipped,
                'totals' => $totals,
                'targets' => array_map(fn (array $counts): array => array_filter($counts), $results),
            ], event: 'security.reencrypted');
        }

        if ($skipped !== []) {
            $this->components->warn('Modules not loaded, so their storage was not covered: '.implode(', ', $skipped).'.');
        }

        return $this->conclude($dryRun, $complete && $skipped === [], $totals);
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function conclude(bool $dryRun, bool $complete, array $totals): int
    {
        if ($totals['undecryptable'] > 0 || $totals['failed'] > 0) {
            $this->components->error("{$totals['undecryptable']} value(s) no configured key decrypts and {$totals['failed']} failed write check(s); they are unchanged. Add the key they were written with to APP_PREVIOUS_KEYS, or clear those values.");

            return self::FAILURE;
        }

        if (! $complete) {
            $this->components->warn('Not finished: stopped at --limit, or modules were skipped. Run the command again (with every module loaded) to finish.');

            return self::INCOMPLETE;
        }

        if (! $dryRun && $totals['changed'] > 0) {
            $this->components->warn("{$totals['changed']} value(s) were written by the app during the run and left as written. Run `security:reencrypt --dry-run` to confirm they are on the current key.");

            return self::INCOMPLETE;
        }

        if ($dryRun && $totals['old_key'] > 0) {
            $this->components->warn("{$totals['old_key']} value(s) are on an old key. Run without --dry-run to re-encrypt them.");

            return self::INCOMPLETE;
        }

        $this->components->info($dryRun ? 'Every stored value is on the current key.' : "Done: {$totals['reencrypted']} value(s) re-encrypted; every stored value is on the current key.");

        return self::SUCCESS;
    }
}
