<?php

namespace App\Enums;

/**
 * What an importer did with a record (App\Data\Migration\ImportOutcome).
 */
enum ImportAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
}
