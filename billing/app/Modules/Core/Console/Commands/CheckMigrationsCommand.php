<?php

namespace App\Modules\Core\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Process;

/**
 * The expand-then-contract rule (docs/architecture.md, "Migrations during blue-green deploys"): a release's
 * migrations may only add, because the previous release keeps running against the same schema until the switch.
 * This flags an up() that drops, renames or changes a column or table. Two tags, with the reason next to them, turn
 * a finding into a report: @contract for the contract step (removing what no released code uses any more) and
 *
 * @compatible for an in-place change the previous release still works with (widening a column, say).
 */
#[Signature('deploy:check-migrations
    {paths?* : Migration files to check}
    {--pending : Check the migrations this database has not run yet (before a deploy)}
    {--since= : Check migration files added or changed since this git ref (locally, before a pull request)}')]
#[Description('Fail when a migration drops, renames or changes a column or table in one step (expand-then-contract)')]
class CheckMigrationsCommand extends Command
{
    /** @var array<string, string> pattern => what it does */
    private const DESTRUCTIVE = [
        '/->\s*drop(Column|Columns|Morphs|NullableMorphs|Timestamps|TimestampsTz|SoftDeletes|SoftDeletesTz|RememberToken)\s*\(/' => 'drops a column',
        '/->\s*renameColumn\s*\(/' => 'renames a column',
        '/->\s*change\s*\(\s*\)/' => 'changes a column definition',
        '/Schema::\s*(drop|dropIfExists|dropColumns)\s*\(/' => 'drops a table or columns',
        '/Schema::\s*rename\s*\(/' => 'renames a table',
        '/->\s*rename\s*\(/' => 'renames a table',
        '/DB::\s*(statement|unprepared)\s*\([^;]*\b(DROP|RENAME)\b/is' => 'runs raw SQL that drops or renames',
    ];

    public function handle(Migrator $migrator): int
    {
        $files = $this->files($migrator);

        if ($files === null) {
            return self::FAILURE;
        }

        if ($files === []) {
            $this->components->info('No migrations to check.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($files as $file) {
            $source = (string) @file_get_contents($file);
            $problems = $this->problems($this->upBody($source));
            $name = basename($file);

            if ($problems === []) {
                $this->components->twoColumnDetail($name, '<fg=green>expand only</>');

                continue;
            }

            if (preg_match('/@(contract|compatible)\b/', $source, $tag) === 1) {
                $label = $tag[1] === 'contract' ? 'contract step (@contract)' : 'compatible change (@compatible)';
                $this->components->twoColumnDetail($name, "<fg=yellow>{$label}: ".implode(', ', $problems).'</>');

                continue;
            }

            $failures++;
            $this->components->twoColumnDetail($name, '<fg=red>'.implode(', ', $problems).'</>');
        }

        if ($failures > 0) {
            $this->components->error("{$failures} migration(s) remove or change schema the running release still uses. Split them: "
                .'add in this release, remove in a later one once no released code reads the old schema, and tag that '
                .'migration @contract; tag an in-place change the old release tolerates @compatible (docs/architecture.md, '
                .'"Migrations during blue-green deploys").');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function files(Migrator $migrator): ?array
    {
        $given = array_values(array_filter((array) $this->argument('paths')));

        if ($given !== []) {
            return $given;
        }

        $known = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

        if ($this->option('pending')) {
            if (! $migrator->repositoryExists()) {
                return array_values($known);
            }

            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff_key($known, array_flip($ran)));
        }

        $since = (string) $this->option('since');

        if ($since !== '') {
            $result = Process::path(base_path())->run(['git', 'diff', '--name-only', '--diff-filter=AMR', $since, '--', '.']);

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

    /**
     * The source of the migration's up() method, so down() (which may drop anything) is not checked.
     */
    private function upBody(string $source): string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $j = $i + 1;

            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if (! is_array($tokens[$j] ?? null) || strtolower($tokens[$j][1]) !== 'up') {
                continue;
            }

            while ($j < $count && $tokens[$j] !== '{') {
                $j++;
            }

            $depth = 0;
            $body = '';

            for (; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $depth += match ($text) {
                    '{', '${' => 1,
                    '}' => -1,
                    default => is_array($tokens[$j]) && $tokens[$j][0] === T_CURLY_OPEN ? 1 : 0,
                };
                $body .= $text;

                if ($depth === 0) {
                    return $body;
                }
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function problems(string $body): array
    {
        $found = [];

        foreach (self::DESTRUCTIVE as $pattern => $meaning) {
            if (preg_match($pattern, $body) === 1) {
                $found[] = $meaning;
            }
        }

        return array_values(array_unique($found));
    }
}
