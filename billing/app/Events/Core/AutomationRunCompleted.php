<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The daily automation pipeline finished for a run date.
 */
final class AutomationRunCompleted
{
    use Dispatchable;

    public function __construct(
        public string $runDate,
        public bool $failed,
    ) {}
}
