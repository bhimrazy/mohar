<?php

namespace App\Data\Catalog;

/**
 * What a service's configurable options and custom fields mean to its provisioning driver (PriceBook::moduleInputs()).
 */
final readonly class ModuleInputs
{
    /**
     * @param  array<string, string|int>  $configOptions  module key => the selected value's module value (else its label),
     *                                                    the quantity, or "1"/"0" for a yes/no option
     * @param  array<string, mixed>  $customFields  module key => value
     * @param  list<string>  $secretValues  values of password-type custom fields, with or without a module key
     */
    public function __construct(
        public array $configOptions = [],
        public array $customFields = [],
        #[\SensitiveParameter]
        public array $secretValues = [],
    ) {}
}
