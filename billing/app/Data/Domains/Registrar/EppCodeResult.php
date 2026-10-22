<?php

namespace App\Data\Domains\Registrar;

/**
 * Either the code itself or `emailed` when the registrar sends it to the registrant.
 */
final readonly class EppCodeResult
{
    public function __construct(
        #[\SensitiveParameter] public ?string $code = null,
        public bool $emailed = false,
    ) {}
}
