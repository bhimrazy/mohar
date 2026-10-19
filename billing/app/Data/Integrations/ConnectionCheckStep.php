<?php

namespace App\Data\Integrations;

use App\Enums\CheckStatus;

/**
 * One line of a connection check's checklist (credentials, permissions, certificate, ...), in a plain sentence for
 * staff. Never contains secrets.
 */
final readonly class ConnectionCheckStep
{
    public function __construct(
        public string $label,
        public CheckStatus $status,
        public ?string $detail = null,
    ) {}
}
