<?php

namespace App\Modules\Services\Drivers\Provisioning;

use App\Contracts\Services\Provisioning\ChangesPackage;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\ProvisioningContext;

/**
 * Manual provisioning: every command succeeds without contacting anything; staff do the work.
 */
class NoneDriver implements ChangesPackage, ChangesPassword, ProvisioningDriver
{
    public function key(): string
    {
        return 'none';
    }

    public function name(): string
    {
        return 'None (manual)';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(requiresServer: false);
    }

    public function create(ProvisioningContext $context): DriverResult
    {
        return DriverResult::ok('Marked as provisioned (manual).');
    }

    public function suspend(ProvisioningContext $context, string $reason): DriverResult
    {
        return DriverResult::ok('Marked as suspended (manual).');
    }

    public function unsuspend(ProvisioningContext $context): DriverResult
    {
        return DriverResult::ok('Marked as active (manual).');
    }

    public function terminate(ProvisioningContext $context): DriverResult
    {
        return DriverResult::ok('Marked as terminated (manual).');
    }

    public function changePackage(ProvisioningContext $context, PreviousPackage $previous): DriverResult
    {
        return DriverResult::ok('Package change recorded (manual).');
    }

    public function changePassword(ProvisioningContext $context, #[\SensitiveParameter] string $newPassword): DriverResult
    {
        return DriverResult::ok('Password recorded (manual).');
    }
}
