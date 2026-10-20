<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Mohar\Simulators\Contracts\RecordsLive;
use Mohar\Simulators\Contracts\RedactsPayments;
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
        $key = $this->stringArgument('provider');
        $simulator = new ($this->laravel->make(SimulatorRegistry::class)->get($key));

        if (! $simulator instanceof RecordsLive) {
            $this->components->error("The {$key} simulator has no live recording script yet.");

            return self::FAILURE;
        }

        // Recorded into a private staging directory first: only a run that completes and whose every file passes the
        // secret scan is moved into the package, so a failed or leaking run never leaves a file ready to commit.
        $directory = dirname(__DIR__, 2).'/'.$key.'/recordings';
        $staging = sys_get_temp_dir().'/mohar-sim-recording-'.bin2hex(random_bytes(6));
        mkdir($staging, 0700);

        try {
            $simulator->recordLive(new Recorder($staging, payments: $simulator instanceof RedactsPayments));
            $findings = (new RecordingScanner)->scan($staging);

            foreach ($findings as $finding) {
                $this->components->error($finding);
            }

            if ($findings !== []) {
                $this->components->error('Nothing was saved: the recordings above failed the secret scan.');

                return self::FAILURE;
            }

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            foreach (glob($staging.'/*.json') ?: [] as $file) {
                copy($file, $directory.'/'.basename($file));
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage().' Nothing was saved.');

            return self::FAILURE;
        } finally {
            array_map('unlink', glob($staging.'/*') ?: []);
            rmdir($staging);
        }

        $this->components->info("Recordings for {$key} written (source: live).");

        return self::SUCCESS;
    }
}
