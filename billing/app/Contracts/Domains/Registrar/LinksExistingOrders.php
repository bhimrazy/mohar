<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\LinkedOrder;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Support\Domains\RegistrarException;

/**
 * Capability `link_order` (registrars.md §3): check, on a staff member's explicit request, that an order which
 * already exists in our registrar account is the one for a domain the host holds without a recorded order id (an
 * imported domain, or one staff set Active). Read-only; the host records the id only when this returns.
 *
 * The lookup is by the order id staff typed. The driver refuses, with a plain message, an order that is missing from
 * our account, that is for another name (the name must match exactly), that no longer holds its name, or that
 * belongs to a customer other than the one this host uses for the domain's client. Nothing is ever found by
 * searching for the name or by an email address: a name found that way is not proof of ownership.
 */
interface LinksExistingOrders
{
    /**
     * @param  string  $orderId  digits only, as the staff member typed it
     *
     * @throws RegistrarException non-retryable when the order does not qualify; retryable when the registrar could not be reached
     */
    public function lookupOrder(RegistrarConfig $config, DomainRef $domain, string $orderId): LinkedOrder;
}
