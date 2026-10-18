<?php

namespace App\Data\Services;

/**
 * Options for a product config field filled from the remote system (ProvisioningDrivers::remoteOptions()).
 */
final readonly class RemoteOptions
{
    /**
     * @param  array<string, string>  $options  value => label, the union over the servers asked
     * @param  array<string, list<string>>  $missingOn  value => names of asked servers that do not offer it
     * @param  list<string>  $unreachable  names of servers that could not be asked; the form falls back to free text
     *                                     when every server is unreachable
     */
    public function __construct(
        public array $options = [],
        public array $missingOn = [],
        public array $unreachable = [],
    ) {}
}
