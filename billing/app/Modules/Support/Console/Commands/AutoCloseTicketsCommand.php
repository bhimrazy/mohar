<?php

namespace App\Modules\Support\Console\Commands;

use App\Modules\Support\Actions\AutoCloseTickets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('support:auto-close')]
#[Description('Close idle tickets in an auto-close status (hourly; also part of the daily automation)')]
class AutoCloseTicketsCommand extends Command
{
    public function handle(AutoCloseTickets $action): int
    {
        $this->components->info('Closed '.$action->handle().' ticket(s).');

        return self::SUCCESS;
    }
}
