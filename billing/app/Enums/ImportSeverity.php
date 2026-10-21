<?php

namespace App\Enums;

/**
 * How serious an import issue is: errors stop the record, blocking issues stop the run.
 */
enum ImportSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Blocking = 'blocking';
}
