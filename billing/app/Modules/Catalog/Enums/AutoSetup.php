<?php

namespace App\Modules\Catalog\Enums;

/**
 * When a newly ordered service is provisioned (consumed by Orders/Services).
 */
enum AutoSetup: string
{
    case OnOrder = 'on_order';
    case OnPayment = 'on_payment';
    case OnAccept = 'on_accept';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::OnOrder => 'When the order is placed',
            self::OnPayment => 'When the first payment is received',
            self::OnAccept => 'When staff accept the order',
            self::Manual => 'Manually',
        };
    }

    /**
     * Value used by the shared ProductSummary DTO.
     *
     * @return 'order'|'payment'|'accept'|'manual'
     */
    public function summaryValue(): string
    {
        return match ($this) {
            self::OnOrder => 'order',
            self::OnPayment => 'payment',
            self::OnAccept => 'accept',
            self::Manual => 'manual',
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
