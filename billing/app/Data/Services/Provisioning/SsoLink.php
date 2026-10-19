<?php

namespace App\Data\Services\Provisioning;

use Carbon\CarbonImmutable;

/**
 * A one-time login URL. The host redirects to it at once and never stores or logs it.
 */
final readonly class SsoLink
{
    public function __construct(
        #[\SensitiveParameter]
        public string $url,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
