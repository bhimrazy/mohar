<?php

namespace App\Data\Services\Provisioning;

/**
 * Disk and bandwidth of one account, in megabytes. A null limit means unlimited.
 */
final readonly class UsageRow
{
    public function __construct(
        public int $diskUsedMb,
        public ?int $diskLimitMb,
        public int $bandwidthUsedMb,
        public ?int $bandwidthLimitMb,
    ) {}
}
