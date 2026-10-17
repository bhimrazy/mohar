<?php

namespace App\Data\Catalog;

final readonly class ProductSummary
{
    /**
     * @param  array<string, mixed>  $driverConfig
     * @param  'order'|'payment'|'accept'|'manual'  $autoSetup
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $groupName,
        public string $type,
        public string $provisioningDriver,
        public ?int $serverGroupId,
        public array $driverConfig,
        public string $autoSetup,
        public ?string $welcomeEmail,
        public bool $requiresDomain,
        public bool $taxable,
        public ?int $stock,
        public bool $active,
    ) {}
}
