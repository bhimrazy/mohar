<?php

namespace App\Support\Localization;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('i18n:clear')]
#[Description('Remove the cached JSON translation catalogues (run by `php artisan optimize:clear`)')]
class ClearCatalogueCommand extends Command
{
    public function handle(): int
    {
        if (is_file($file = Catalogue::cachePath())) {
            unlink($file);
        }

        Catalogue::flush();
        $this->components->info('Translation catalogues cleared.');

        return self::SUCCESS;
    }
}
