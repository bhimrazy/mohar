<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Support\AutomationRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('automation:run-requested')]
#[Description('Start the automation run requested from the admin, if any (scheduled every minute)')]
class RunRequestedAutomation extends Command
{
    public function handle(AutomationRunner $runner): int
    {
        $failed = $runner->runRequested(function (string $key, string $result): void {
            $this->components->twoColumnDetail($key, $result);
        });

        return $failed === true ? self::FAILURE : self::SUCCESS;
    }
}
