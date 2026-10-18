<?php

namespace App\Modules\Services\Enums;

/**
 * Commands a provisioning driver can execute for a service.
 */
enum ModuleCommandName: string
{
    case Create = 'create';
    case Suspend = 'suspend';
    case Unsuspend = 'unsuspend';
    case Terminate = 'terminate';
    case ChangePackage = 'change_package';
    case ChangePassword = 'change_password';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Create',
            self::Suspend => 'Suspend',
            self::Unsuspend => 'Unsuspend',
            self::Terminate => 'Terminate',
            self::ChangePackage => 'Change package',
            self::ChangePassword => 'Change password',
        };
    }

    /**
     * The action as a noun, for the activity log: "Account creation failed for …".
     */
    public function noun(): string
    {
        return match ($this) {
            self::Create => 'Account creation',
            self::Suspend => 'Suspension',
            self::Unsuspend => 'Unsuspension',
            self::Terminate => 'Termination',
            self::ChangePackage => 'Package change',
            self::ChangePassword => 'Password change',
        };
    }

    /**
     * What a successful run did, for the activity log: "Account created".
     */
    public function pastTense(): string
    {
        return match ($this) {
            self::Create => 'Account created',
            self::Suspend => 'Account suspended',
            self::Unsuspend => 'Account unsuspended',
            self::Terminate => 'Account terminated',
            self::ChangePackage => 'Package changed',
            self::ChangePassword => 'Password changed',
        };
    }

    /**
     * The status a successful run moves the service to, or null when the status does not change.
     */
    public function targetStatus(): ?ServiceStatus
    {
        return match ($this) {
            self::Create, self::Unsuspend => ServiceStatus::Active,
            self::Suspend => ServiceStatus::Suspended,
            self::Terminate => ServiceStatus::Terminated,
            self::ChangePackage, self::ChangePassword => null,
        };
    }
}
