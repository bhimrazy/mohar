<?php

namespace App\Modules\Notifications\Rendering;

final readonly class RenderResult
{
    /**
     * @param  list<string>  $warnings  unknown variables and other non-fatal problems
     */
    public function __construct(
        public string $output,
        public array $warnings = [],
    ) {}
}
