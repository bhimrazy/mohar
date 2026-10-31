<?php

namespace App\Support\Localization;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('i18n:cache')]
#[Description('Cache the JSON translation catalogues (run by `php artisan optimize`)')]
class CacheCatalogueCommand extends Command
{
    public function handle(): int
    {
        $all = Catalogue::buildAll();
        $file = Catalogue::cachePath();
        $temporary = $file.'.'.getmypid().'.tmp';

        file_put_contents($temporary, '<?php return '.var_export($all, true).';'.PHP_EOL);
        rename($temporary, $file);
        Catalogue::flush();

        $this->components->info('Translation catalogues cached ('.count($all).' locale'.(count($all) === 1 ? '' : 's').').');

        return self::SUCCESS;
    }
}
