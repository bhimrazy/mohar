<?php

namespace App\Modules\Domains\Enums;

/**
 * Lifecycle of a domain (spec §3). Transitions are enforced by DomainStateMachine.
 */
enum DomainStatus: string
{
    case Pending = 'pending';
    case PendingRegistration = 'pending_registration';
    case PendingTransfer = 'pending_transfer';
    case Active = 'active';
    case Grace = 'grace';
    case Redemption = 'redemption';
    case Expired = 'expired';
    case TransferredAway = 'transferred_away';
    case Cancelled = 'cancelled';
    case Fraud = 'fraud';

    /**
     * Statuses that hold the name: at most one such row per domain name.
     *
     * @return list<self>
     */
    public static function nonTerminal(): array
    {
        return [self::Pending, self::PendingRegistration, self::PendingTransfer, self::Active, self::Grace, self::Redemption];
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, self::nonTerminal(), true);
    }

    /**
     * Whether the client may manage the domain (nameservers, lock, EPP, contacts, auto-renew).
     */
    public function allowsClientActions(): bool
    {
        return in_array($this, [self::Active, self::Grace, self::Redemption], true);
    }

    /**
     * Statuses this one may move to through normal (non-forced) transitions. Expired → active is a staff
     * action only (forced renewal or status change).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::PendingRegistration, self::PendingTransfer, self::Cancelled, self::Fraud],
            self::PendingRegistration => [self::Active, self::Pending, self::Cancelled],
            self::PendingTransfer => [self::Active, self::Pending, self::Cancelled],
            self::Active => [self::Grace, self::Redemption, self::Expired, self::TransferredAway, self::Cancelled],
            self::Grace => [self::Redemption, self::Expired, self::Active],
            self::Redemption => [self::Expired, self::Active],
            self::Expired => [self::Cancelled],
            self::TransferredAway, self::Cancelled, self::Fraud => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
