<?php

namespace App\Data\Services;

final readonly class ProvisionResult
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
    ) {}
}
