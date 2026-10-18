<?php

namespace App\Modules\Services\Enums;

use App\Modules\Services\Exceptions\InvalidStatusTransition;

/**
 * Lifecycle of services and service addons (services.md §3).
 */
enum ServiceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Cancelled = 'cancelled';
    case Fraud = 'fraud';
    case Completed = 'completed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Cancelled, self::Fraud],
            self::Active => [self::Suspended, self::Terminated, self::Cancelled, self::Completed],
            self::Suspended => [self::Active, self::Terminated, self::Cancelled],
            self::Fraud => [self::Pending],
            self::Terminated => [self::Active],
            self::Cancelled, self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @throws InvalidStatusTransition
     */
    public function assertCanTransitionTo(self $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw new InvalidStatusTransition($this, $target);
        }
    }

    /**
     * Statuses that keep generating renewal invoices.
     *
     * @return list<self>
     */
    public static function billable(): array
    {
        return [self::Pending, self::Active, self::Suspended];
    }

    /**
     * Statuses that occupy an account slot on a server.
     *
     * @return list<string>
     */
    public static function occupyingValues(): array
    {
        return [self::Pending->value, self::Active->value, self::Suspended->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
