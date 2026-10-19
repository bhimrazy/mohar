<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Mohar\Simulators\Kernel\RecordingScanner;

#[Signature('simulate:scan')]
#[Description('Scan every simulator recording for credentials and personal data (use it as a pre-commit hook)')]
final class ScanCommand extends SimulatorCommand
{
    protected function perform(): int
    {
        $findings = (new RecordingScanner)->scanPackage();

        foreach ($findings as $finding) {
            $this->components->error($finding);
        }

        if ($findings === []) {
            $this->components->info('No credentials or personal data in the recordings.');
        }

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }
}
