<?php

namespace App\Data\Domains\Registrar;

/**
 * One remote object a registrar driver created for a local record (registrars.md C4): a ResellerClub customer or
 * contact, an OpenSRS profile. See RegistrarRefs.
 */
final readonly class RegistrarRef
{
    /**
     * @param  string  $refType  `customer`, `contact` or `profile`
     * @param  string  $localType  what it stands for locally, e.g. `client` or `domain_contact`
     * @param  string  $payloadHash  hash of the data sent when it was created ('' when it does not matter); a
     *                               changed payload is a new object, never an edit of a shared one
     * @param  string|null  $secret  a credential the registrar issued for it (OpenSRS profile password); stored
     *                               encrypted, never logged
     */
    public function __construct(
        public string $registrarKey,
        public string $refType,
        public string $localType,
        public int $localId,
        public string $remoteId,
        public string $payloadHash = '',
        #[\SensitiveParameter] public ?string $secret = null,
    ) {}
}
