<?php

namespace App\Enums;

/**
 * What an importer did with a record (App\Data\Migration\ImportOutcome).
 *
 * Open enum (ADR 0010): cases may be added in a minor version, so consumers need a default branch.
 *
 * @open
 */
enum ImportAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
}
