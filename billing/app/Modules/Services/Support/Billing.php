<?php

namespace App\Modules\Services\Support;

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Billing\Invoicer;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Access to the Billing module through its contracts, resolved lazily so Services can boot
 * (and render its UI) without a Billing implementation bound.
 */
class Billing
{
    /** Invoice statuses that still expect payment. */
    public const OPEN_STATUSES = ['draft', 'unpaid', 'collections'];

    public function __construct(private Container $container) {}

    public function available(): bool
    {
        return $this->container->bound(Invoicer::class);
    }

    public function invoicer(): Invoicer
    {
        if (! $this->available()) {
            throw new RuntimeException('No Billing\Invoicer implementation is bound.');
        }

        return $this->container->make(Invoicer::class);
    }

    public function credits(): CreditLedger
    {
        return $this->container->make(CreditLedger::class);
    }

    /**
     * Whether an open invoice may be cancelled: never once any payment or credit was applied.
     */
    public function canCancel(int $invoiceId): bool
    {
        return ! $this->container->make(InvoiceQueries::class)->hasPayments($invoiceId);
    }

    public function isOpen(int $invoiceId): bool
    {
        return in_array($this->invoicer()->status($invoiceId), self::OPEN_STATUSES, true);
    }
}
