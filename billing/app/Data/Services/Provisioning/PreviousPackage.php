<?php

namespace App\Data\Services\Provisioning;

use App\Enums\BillingCycle;

/**
 * What a service was before a package change. Null fields mean the host does not know (a staff-run change).
 */
final readonly class PreviousPackage
{
    public function __construct(
        public ?int $productId = null,
        public ?BillingCycle $billingCycle = null,
    ) {}
}
