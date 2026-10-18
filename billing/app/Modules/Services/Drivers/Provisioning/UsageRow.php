<?php

namespace App\Modules\Services\Drivers\Provisioning;

final readonly class UsageRow
{
    public function __construct(
        public int $diskUsedMb,
        public ?int $diskLimitMb,
        public int $bandwidthUsedMb,
        public ?int $bandwidthLimitMb,
    ) {}
}
