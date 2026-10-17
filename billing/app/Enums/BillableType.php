<?php

namespace App\Enums;

/**
 * What an invoice line bills for. Modules react to InvoicePaid only for the types they own.
 */
enum BillableType: string
{
    /** Recurring/first-period charge of a client service (Services module). */
    case Service = 'service';

    /** One-off setup fee of a service; never extends a due date. */
    case ServiceSetup = 'service_setup';

    /** Recurring charge of a service addon (Services module). */
    case ServiceAddon = 'service_addon';

    /** Prorated upgrade/downgrade charge (Services module). */
    case ServiceUpgrade = 'service_upgrade';

    /** Domain registration, transfer or renewal (Domains module). */
    case Domain = 'domain';

    /** Discount line produced by a promotion (Orders module); negative amount. */
    case Promotion = 'promotion';

    /** Late payment fee (Billing module). */
    case LateFee = 'late_fee';

    /** Add funds to the client's credit balance (Billing module). */
    case AddFunds = 'add_funds';

    /** Free-form line created by staff. */
    case Manual = 'manual';
}
