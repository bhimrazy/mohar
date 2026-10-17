<?php

namespace App\Modules\Orders\Console\Commands;

use App\Modules\Orders\Actions\PruneExpiredCarts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:prune-carts')]
#[Description('Delete expired shopping carts')]
class PruneCarts extends Command
{
    public function handle(PruneExpiredCarts $prune): int
    {
        $this->info("Deleted {$prune->handle()} expired carts.");

        return self::SUCCESS;
    }
}
