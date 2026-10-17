<?php

namespace App\Modules\Catalog\Enums;

use App\Enums\BillingCycle;

enum PaymentType: string
{
    case Free = 'free';
    case OneTime = 'one_time';
    case Recurring = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::OneTime => 'One-time',
            self::Recurring => 'Recurring',
        };
    }

    /**
     * Value used by the shared ProductSummary DTO.
     *
     * @return 'free'|'onetime'|'recurring'
     */
    public function summaryValue(): string
    {
        return match ($this) {
            self::Free => 'free',
            self::OneTime => 'onetime',
            self::Recurring => 'recurring',
        };
    }

    /**
     * Billing cycles that may carry a price row for this payment type.
     *
     * @return list<BillingCycle>
     */
    public function cycles(): array
    {
        return match ($this) {
            self::Free => [],
            self::OneTime => [BillingCycle::OneTime],
            self::Recurring => BillingCycle::recurring(),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
