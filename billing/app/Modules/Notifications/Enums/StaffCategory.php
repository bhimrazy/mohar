<?php

namespace App\Modules\Notifications\Enums;

/**
 * Staff email subscriptions granted per role (spec §2 "Staff subscriptions").
 */
enum StaffCategory: string
{
    case System = 'system';
    case Account = 'account';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System alerts',
            self::Account => 'Account activity',
            self::Support => 'Support tickets',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::System => 'Automation summaries, provisioning and registrar failures, sync reports, disabled webhooks.',
            self::Account => 'New and fraud-flagged orders, cancellation requests, client changes, affiliate withdrawals.',
            self::Support => 'New tickets and client replies.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category): string => $category->value, self::cases());
    }
}
