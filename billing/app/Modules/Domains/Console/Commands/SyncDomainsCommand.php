<?php

namespace App\Modules\Domains\Console\Commands;

use App\Modules\Domains\Actions\SyncDomains;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('domains:sync')]
#[Description('Sync pending transfers and a batch of domains with their registrars (hourly)')]
class SyncDomainsCommand extends Command
{
    public function handle(SyncDomains $sync): int
    {
        $result = $sync->handle();
        $this->components->info("{$result['synced']} domains synced, {$result['changes']} changes, {$result['errors']} errors.");

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
