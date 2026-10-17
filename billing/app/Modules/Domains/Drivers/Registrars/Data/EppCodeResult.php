<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

/**
 * Either the code itself or `emailed` when the registrar sends it to the registrant.
 */
final readonly class EppCodeResult
{
    public function __construct(
        public ?string $code = null,
        public bool $emailed = false,
    ) {}
}
