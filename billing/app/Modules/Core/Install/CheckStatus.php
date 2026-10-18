<?php

namespace App\Modules\Core\Install;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
