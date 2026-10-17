<?php

namespace App\Data\Catalog;

final readonly class ProductSummary
{
    /**
     * @param  array<string, mixed>  $driverConfig
     * @param  'order'|'payment'|'accept'|'manual'  $autoSetup
     * @param  'free'|'onetime'|'recurring'  $paymentType
     * @param  list<string>  $freeDomainTlds  e.g. [".com", ".net"]
     * @param  list<string>  $freeDomainCycles  BillingCycle values that qualify for the free domain
     * @param  int|null  $suspendAfterDays  per-product override of services.suspend_after_days (null = global setting)
     * @param  int|null  $terminateAfterDays  per-product override of services.terminate_after_days
     * @param  int|null  $recurringCycleLimit  stop billing after N cycles (null = unlimited)
     * @param  int|null  $termMonths  fixed-term length (null = open-ended)
     * @param  list<string>|null  $allowedGateways  gateway keys allowed for this product (null = all enabled gateways)
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
        public string $paymentType = 'recurring',
        public bool $prorata = false,
        public ?int $prorataDay = null,
        public ?int $prorataChargeNextMonthDay = null,
        public bool $freeDomain = false,
        public array $freeDomainTlds = [],
        public array $freeDomainCycles = [],
        public ?int $suspendAfterDays = null,
        public ?int $terminateAfterDays = null,
        public ?int $recurringCycleLimit = null,
        public ?int $termMonths = null,
        public ?string $slug = null,
        public ?array $allowedGateways = null,
    ) {}
}
