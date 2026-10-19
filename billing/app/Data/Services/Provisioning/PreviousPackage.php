<?php

namespace App\Data\Services\Provisioning;

use App\Enums\BillingCycle;

/**
 * What a service was before a package change, so a driver can compare without a query. Null or empty fields mean the
 * host does not know (a staff-run change).
 */
final readonly class PreviousPackage
{
    /**
     * @param  array<string, mixed>  $productConfig  the previous product's driver settings
     * @param  array<string, mixed>  $configOptions  the previous configurable options by module key
     */
    public function __construct(
        public ?int $productId = null,
        public ?BillingCycle $billingCycle = null,
        public array $productConfig = [],
        public array $configOptions = [],
    ) {}
}
