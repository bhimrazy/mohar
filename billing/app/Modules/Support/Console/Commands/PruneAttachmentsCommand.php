<?php

namespace App\Modules\Support\Console\Commands;

use App\Modules\Support\Actions\PruneAttachments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('support:prune-attachments')]
#[Description('Delete attachment files of tickets closed longer than the configured months')]
class PruneAttachmentsCommand extends Command
{
    public function handle(PruneAttachments $action): int
    {
        $this->components->info('Pruned '.$action->handle().' attachment(s).');

        return self::SUCCESS;
    }
}
