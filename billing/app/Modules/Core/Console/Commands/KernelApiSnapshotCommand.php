<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Support\KernelApiSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the public kernel surface to docs/architecture/kernel-api.txt, or with --check fails when the committed file
 * is out of date (ADR 0010 "Enforcement"; CI runs the check).
 */
#[Signature('kernel:api-snapshot {--check : Fail when the committed snapshot differs instead of writing it}')]
#[Description('Write (or check) the public kernel API snapshot')]
class KernelApiSnapshotCommand extends Command
{
    /** Changed lines shown by --check before the rest is summarised. */
    private const SHOWN_LINES = 40;

    public function handle(KernelApiSnapshot $generator): int
    {
        $path = $generator->path;
        $file = str_starts_with($path, base_path().'/') ? substr($path, strlen(base_path()) + 1) : $path;
        $snapshot = $generator->render();

        if (! $this->option('check')) {
            file_put_contents($path, $snapshot);
            $this->components->info("Wrote {$file}.");

            return self::SUCCESS;
        }

        $committed = is_file($path) ? (string) file_get_contents($path) : '';

        if ($committed === $snapshot) {
            $this->components->info("{$file} is up to date.");

            return self::SUCCESS;
        }

        $this->components->error("The public kernel API changed and {$file} does not record it (ADR 0010).");
        $this->line('Run `php artisan kernel:api-snapshot`, commit the file, and add a fragment to '
            .'docs/architecture/kernel-changelog.d/<branch-slug>.md describing the change (format: that folder\'s README.md).');
        $this->newLine();

        $old = explode("\n", $committed);
        $new = explode("\n", $snapshot);
        $changes = [
            ...array_map(fn (string $line): string => '- '.$line, array_values(array_diff($old, $new))),
            ...array_map(fn (string $line): string => '+ '.$line, array_values(array_diff($new, $old))),
        ];

        foreach (array_slice($changes, 0, self::SHOWN_LINES) as $change) {
            // Raw: signatures contain backslashes and angle brackets that the console would read as markup.
            $this->output->writeln($change, OutputInterface::OUTPUT_RAW);
        }

        if (count($changes) > self::SHOWN_LINES) {
            $this->line('... and '.(count($changes) - self::SHOWN_LINES).' more changed lines.');
        }

        return self::FAILURE;
    }
}
