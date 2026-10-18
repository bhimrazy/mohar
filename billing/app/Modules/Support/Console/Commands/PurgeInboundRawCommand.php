<?php

namespace App\Modules\Support\Console\Commands;

use App\Modules\Support\Actions\PurgeInboundRaw;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('support:purge-inbound-raw')]
#[Description('Delete raw inbound email payloads past the retention window')]
class PurgeInboundRawCommand extends Command
{
    public function handle(PurgeInboundRaw $action): int
    {
        $this->components->info('Purged '.$action->handle().' raw email(s).');

        return self::SUCCESS;
    }
}
