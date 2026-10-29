<?php

namespace App\Support\Billing;

use DomainException;

/**
 * `Invoicer::cancel` could not cancel the invoice, and it is still payable (for example a tax invoice whose credit note
 * Billing refused). The message is plain words for staff. A caller keeps its own record open instead of marking it
 * cancelled or rewinding its renewal pointers; Billing has put the invoice on its staff list.
 *
 * @api
 */
final class InvoiceNotCancellable extends DomainException {}
