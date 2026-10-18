<?php

namespace App\Data\Services\Provisioning;

use Carbon\CarbonImmutable;

/**
 * One account as the panel reports it (ListsAccounts, FindsAccounts).
 */
final readonly class RemoteAccount
{
    /**
     * @param  string|null  $plan  the package name exactly as the panel reports it
     * @param  string|null  $owner  the panel user that owns the account (root or a reseller)
     * @param  string|null  $email  the account's contact email, used to propose a client match on import
     */
    public function __construct(
        public string $username,
        public ?string $domain = null,
        public ?string $plan = null,
        public ?string $owner = null,
        public ?string $email = null,
        public ?string $ip = null,
        public bool $suspended = false,
        public ?string $suspendReason = null,
        public ?CarbonImmutable $createdAt = null,
        public ?int $diskUsedMb = null,
        public ?int $diskLimitMb = null,
    ) {}
}
