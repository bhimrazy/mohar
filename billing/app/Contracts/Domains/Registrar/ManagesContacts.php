<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;

/**
 * Capability `contacts`. A registrant change can start a registry lock; report it in RegistrarResult::$meta
 * (`contact_change_pending`, `transfer_lock_until`). Never edit a contact other domains share: create a new one.
 */
interface ManagesContacts
{
    public function getContacts(RegistrarConfig $config, DomainRef $domain): ContactSet;

    public function setContacts(RegistrarConfig $config, DomainRef $domain, ContactSet $contacts): RegistrarResult;
}
