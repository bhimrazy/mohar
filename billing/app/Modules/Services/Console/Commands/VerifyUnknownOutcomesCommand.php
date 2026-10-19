<?php

namespace App\Modules\Services\Console\Commands;

use App\Modules\Services\Actions\VerifyUnknownOutcomes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('services:verify-unknown')]
#[Description('Ask the servers whether module commands with an unknown outcome ran, and settle the ones that did')]
class VerifyUnknownOutcomesCommand extends Command
{
    public function handle(VerifyUnknownOutcomes $verify): int
    {
        $this->components->info($verify->sweep().' unknown outcomes queued for a check.');

        return self::SUCCESS;
    }
}
