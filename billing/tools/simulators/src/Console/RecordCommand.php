<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Mohar\Simulators\Contracts\RecordsLive;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\RecordingScanner;
use Mohar\Simulators\SimulatorRegistry;
use Throwable;

#[Signature('simulate:record {provider : a key from simulate:list}')]
#[Description('Record a provider\'s real sandbox responses (credentials from the environment only) to audit its simulator')]
final class RecordCommand extends SimulatorCommand
{
    protected function perform(): int
    {
        $key = (string) $this->argument('provider');
        $simulator = new ($this->laravel->make(SimulatorRegistry::class)->get($key));

        if (! $simulator instanceof RecordsLive) {
            $this->components->error("The {$key} simulator has no live recording script yet.");

            return self::FAILURE;
        }

        $directory = dirname(__DIR__, 2).'/'.$key.'/recordings';

        try {
            $simulator->recordLive(new Recorder($directory));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        // A recording that fails the secret scan is deleted, never left for a commit.
        $scanner = new RecordingScanner;
        $deleted = 0;

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $findings = $scanner->scanFile($file);

            if ($findings !== []) {
                unlink($file);
                $deleted++;
                $this->components->error(basename($file).' was deleted: '.implode('; ', $findings).'.');
            }
        }

        if ($deleted > 0) {
            return self::FAILURE;
        }

        $this->components->info("Recordings for {$key} written (source: live).");

        return self::SUCCESS;
    }
}
