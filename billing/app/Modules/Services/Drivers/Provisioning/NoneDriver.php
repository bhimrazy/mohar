<?php

namespace App\Modules\Services\Drivers\Provisioning;

use App\Modules\Services\Models\Server;

/**
 * Manual provisioning: every command succeeds without contacting anything; staff do the work.
 */
class NoneDriver implements ProvisioningDriver
{
    public function metadata(): DriverMetadata
    {
        return new DriverMetadata('none', 'None (manual)', requiresServer: false, capabilities: [DriverMetadata::CHANGE_PACKAGE, DriverMetadata::CHANGE_PASSWORD]);
    }

    public function testConnection(Server $server): DriverResult
    {
        return DriverResult::ok('Manual servers have nothing to connect to.');
    }

    public function create(ServiceContext $context): DriverResult
    {
        return DriverResult::ok('Marked as provisioned (manual).');
    }

    public function suspend(ServiceContext $context, string $reason): DriverResult
    {
        return DriverResult::ok('Marked as suspended (manual).');
    }

    public function unsuspend(ServiceContext $context): DriverResult
    {
        return DriverResult::ok('Marked as active (manual).');
    }

    public function terminate(ServiceContext $context): DriverResult
    {
        return DriverResult::ok('Marked as terminated (manual).');
    }

    public function changePackage(ServiceContext $context, array $previous): DriverResult
    {
        return DriverResult::ok('Package change recorded (manual).');
    }

    public function changePassword(ServiceContext $context, string $newPassword): DriverResult
    {
        return DriverResult::ok('Password recorded (manual).');
    }

    public function usage(Server $server): array
    {
        return [];
    }
}
