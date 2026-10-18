<?php

namespace App\Modules\Billing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Collections = 'collections';

    /**
     * Statuses on which money is still owed and payments apply to the balance.
     *
     * @return list<self>
     */
    public static function payable(): array
    {
        return [self::Unpaid, self::PaymentPending, self::Collections];
    }

    public function isPayable(): bool
    {
        return in_array($this, self::payable(), true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
