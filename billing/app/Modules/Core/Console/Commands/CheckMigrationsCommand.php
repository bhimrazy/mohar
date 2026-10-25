<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Support\MigrationLint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Process;

/**
 * The migration rules of docs/architecture.md, "Migrations during blue-green deploys": expand then contract
 * (rules 1 to 3), DDL under LockTimeout::run (5), indexes through OnlineIndex (6), and one timestamp per migration.
 * The analysis is App\Modules\Core\Support\MigrationLint. A `@contract` or `@compatible` tag with a reason, in a
 * comment before the statement, excuses that statement; locks and indexes cannot be excused. Migrations listed in
 * database/migration-rules-baseline.txt were merged before this checker's rules and are never rewritten, so they are skipped.
 */
#[Signature('deploy:check-migrations
    {paths?* : Migration files to check}
    {--pending : Check the migrations this database has not run yet (before a deploy)}
    {--since= : Check migration files added or changed since this git ref (locally, before a pull request)}')]
#[Description('Fail when a migration breaks the blue-green rules: one-step drops or changes, DDL outside LockTimeout, indexes outside OnlineIndex, duplicate timestamps')]
class CheckMigrationsCommand extends Command
{
    public function handle(Migrator $migrator): int
    {
        $known = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $files = $this->files($migrator, $known);

        if ($files === null) {
            return self::FAILURE;
        }

        if ($files === []) {
            $this->components->info('No migrations to check.');

            return self::SUCCESS;
        }

        $failures = 0;
        $baseline = $this->baseline();

        foreach ($files as $file) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->components->error("{$file} is not a readable file.");

                return self::FAILURE;
            }

            $name = basename($file);

            if (in_array(basename($file, '.php'), $baseline, true)) {
                $this->components->twoColumnDetail($name, '<fg=gray>merged before these rules (baseline)</>');

                continue;
            }

            $findings = MigrationLint::analyse((string) file_get_contents($file));
            $errors = $this->describe($findings, 'error');
            $warnings = $this->describe($findings, 'warning');

            if ($errors !== []) {
                $failures++;
                $this->components->twoColumnDetail($name, '<fg=red>'.implode(', ', $errors).'</>');
            }

            foreach (['contract' => 'contract step (@contract)', 'compatible' => 'compatible change (@compatible)'] as $tag => $label) {
                $excused = $this->describe(array_filter($findings, fn (array $f): bool => $f['tag'] === $tag), 'excused');

                if ($excused !== []) {
                    $this->components->twoColumnDetail($name, "<fg=yellow>{$label}: ".implode(', ', $excused).'</>');
                }
            }

            if ($warnings !== []) {
                $this->components->twoColumnDetail($name, '<fg=yellow>warning: '.implode(', ', $warnings).'</>');
            }

            if ($findings === []) {
                $this->components->twoColumnDetail($name, '<fg=green>expand only</>');
            }
        }

        $duplicates = MigrationLint::duplicateTimestamps($files, array_values($known));

        foreach ($duplicates as $duplicate) {
            $this->components->error($duplicate);
        }

        if ($failures > 0) {
            $this->components->error("{$failures} migration(s) break the blue-green rules. Split a drop, rename or change: "
                .'add in this release, remove in a later one once no released code reads the old schema, and tag that '
                .'statement @contract with a reason; tag an in-place change the old release tolerates @compatible. A tag '
                .'excuses the statement it precedes (a tag on the class, only a file with one such statement). Run DDL on an '
                .'existing table in LockTimeout::run and build indexes with OnlineIndex::create in a migration with '
                .'`public $withinTransaction = false;` (docs/architecture.md, "Migrations during blue-green deploys").');
        }

        return $failures > 0 || $duplicates !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  iterable<array{message: string, line: int, level: string, tag: string|null}>  $findings
     * @return list<string>
     */
    private function describe(iterable $findings, string $level): array
    {
        $lines = [];

        foreach ($findings as $finding) {
            if ($finding['level'] === $level) {
                $lines[$finding['message']][] = $finding['line'];
            }
        }

        $out = [];

        foreach ($lines as $message => $at) {
            $out[] = $message.' (line '.implode(', ', array_unique($at)).')';
        }

        return $out;
    }

    /**
     * @return list<string> migration names (no .php) skipped
     */
    private function baseline(): array
    {
        $path = database_path('migration-rules-baseline.txt');

        if (! is_file($path)) {
            return [];
        }

        $lines = array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []);

        return array_values(array_filter($lines, fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    }

    /**
     * @param  array<string, string>  $known
     * @return list<string>|null
     */
    private function files(Migrator $migrator, array $known): ?array
    {
        $given = array_values(array_filter((array) $this->argument('paths')));

        if ($given !== []) {
            return $given;
        }

        if ($this->option('pending')) {
            if (! $migrator->repositoryExists()) {
                return array_values($known);
            }

            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff_key($known, array_flip($ran)));
        }

        $since = (string) $this->option('since');

        if ($since !== '') {
            // --relative: paths relative to the app directory (billing/), not to the repository root.
            $result = Process::path(base_path())->run(['git', 'diff', '--name-only', '--relative', '--diff-filter=AMR', $since, '--', '.']);

            if ($result->failed()) {
                $this->components->error("git diff against {$since} failed: ".trim($result->errorOutput()));

                return null;
            }

            $changed = array_map(fn (string $path): string => (string) realpath(base_path($path)), array_filter(explode("\n", $result->output())));

            return array_values(array_filter($known, fn (string $file): bool => in_array(realpath($file), $changed, true)));
        }

        $this->components->error('Pass migration files, --pending or --since=<git ref>.');

        return null;
    }
}
