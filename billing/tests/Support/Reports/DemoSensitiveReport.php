<?php

namespace Tests\Support\Reports;

use App\Contracts\Reports\HasSensitiveExport;

class DemoSensitiveReport extends DemoReport implements HasSensitiveExport
{
    protected string $key = 'demo_secret';
}
