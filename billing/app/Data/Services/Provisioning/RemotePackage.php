<?php

namespace App\Data\Services\Provisioning;

/**
 * One package a panel offers (ListsPackages). $name is stored exactly as the panel returns it. A null quota means
 * unlimited or not reported.
 */
final readonly class RemotePackage
{
    public function __construct(
        public string $name,
        public ?int $diskMb = null,
        public ?int $bandwidthMb = null,
    ) {}
}
