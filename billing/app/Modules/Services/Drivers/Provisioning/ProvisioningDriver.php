<?php

namespace App\Modules\Services\Drivers\Provisioning;

use App\Modules\Services\Exceptions\RetryableProvisioningException;
use App\Modules\Services\Models\Server;

/**
 * Contract every provisioning driver (control panel, hypervisor, manual) implements.
 *
 * Drivers return a DriverResult for expected outcomes and throw RetryableProvisioningException for
 * transient faults. They must be idempotent where the remote system allows it (creating an account
 * that already exists with the same owner is a success).
 */
interface ProvisioningDriver
{
    public function metadata(): DriverMetadata;

    public function testConnection(Server $server): DriverResult;

    /**
     * Create the account. A successful result may carry `username`, `password`, `dedicated_ip` and
     * `driver_data` keys in its data.
     *
     * @throws RetryableProvisioningException
     */
    public function create(ServiceContext $context): DriverResult;

    /**
     * @throws RetryableProvisioningException
     */
    public function suspend(ServiceContext $context, string $reason): DriverResult;

    /**
     * @throws RetryableProvisioningException
     */
    public function unsuspend(ServiceContext $context): DriverResult;

    /**
     * @throws RetryableProvisioningException
     */
    public function terminate(ServiceContext $context): DriverResult;

    /**
     * Apply the service's current product/options on the remote account.
     *
     * @param  array<string, mixed>  $previous  snapshot before the change (product_id, cycle, ...)
     *
     * @throws RetryableProvisioningException
     */
    public function changePackage(ServiceContext $context, array $previous): DriverResult;

    /**
     * @throws RetryableProvisioningException
     */
    public function changePassword(ServiceContext $context, string $newPassword): DriverResult;

    /**
     * Disk/bandwidth usage of every account on a server, keyed by account username.
     *
     * @return array<string, UsageRow>
     */
    public function usage(Server $server): array;
}
