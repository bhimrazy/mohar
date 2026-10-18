<?php

namespace App\Modules\Core\Install;

/**
 * One line of the install pre-flight. A failed check blocks the install; a warning is reported and the install goes on.
 */
final readonly class EnvironmentCheck
{
    public function __construct(
        public string $label,
        public CheckStatus $status,
        public string $detail,
    ) {}

    public function failed(): bool
    {
        return $this->status === CheckStatus::Failed;
    }
}
