<?php

namespace Tests\Support\Reports;

use App\Contracts\Reports\ProvidesCsvOptions;

class DemoBomReport extends DemoReport implements ProvidesCsvOptions
{
    protected string $key = 'demo_bom';

    public static bool $bom = true;

    public function csvBom(): bool
    {
        return self::$bom;
    }
}
