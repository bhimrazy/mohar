<?php

namespace Tests\Fakes\Services;

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Catalog\PriceBook;
use App\Modules\Services\Drivers\Provisioning\LogDriver;

/**
 * Binds in-memory fakes for the sibling contracts Services consumes.
 */
final class ServicesTestKit
{
    public function __construct(
        public FakeInvoicer $invoicer,
        public FakePriceBook $priceBook,
        public FakeCreditLedger $credits,
        public LogDriver $driver,
        public FakeInvoiceQueries $invoiceQueries,
    ) {}

    public static function bind(): self
    {
        $kit = new self(new FakeInvoicer, new FakePriceBook, new FakeCreditLedger, app(LogDriver::class), new FakeInvoiceQueries);

        app()->instance(Invoicer::class, $kit->invoicer);
        app()->instance(PriceBook::class, $kit->priceBook);
        app()->instance(CreditLedger::class, $kit->credits);
        app()->instance(InvoiceQueries::class, $kit->invoiceQueries);
        $kit->driver->reset();

        return $kit;
    }
}
