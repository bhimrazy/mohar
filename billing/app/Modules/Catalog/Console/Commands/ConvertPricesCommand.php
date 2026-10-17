<?php

namespace App\Modules\Catalog\Console\Commands;

use App\Modules\Catalog\Actions\ConvertPrices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:convert-prices')]
#[Description('Regenerate non-default-currency prices from the default currency and exchange rates')]
class ConvertPricesCommand extends Command
{
    public function handle(ConvertPrices $convert): int
    {
        $count = $convert->handle();
        $this->components->info("{$count} price rows written.");

        return self::SUCCESS;
    }
}
