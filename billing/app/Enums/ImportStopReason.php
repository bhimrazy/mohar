<?php

namespace App\Enums;

/**
 * Why an import run stopped and waits for staff (App\Events\Migration\MigrationRunPaused).
 *
 * Open enum (ADR 0010): cases may be added in a minor version, so consumers need a default branch.
 *
 * @open
 */
enum ImportStopReason: string
{
    /** Applying rows failed after every retry; Continue imports the rest. */
    case ApplyFailed = 'apply_failed';
}
