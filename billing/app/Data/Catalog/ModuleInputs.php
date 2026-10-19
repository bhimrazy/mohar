<?php

namespace App\Data\Catalog;

/**
 * What a service's configurable options and custom fields mean to its provisioning driver (PriceBook::moduleInputs()).
 */
final readonly class ModuleInputs
{
    /**
     * @param  array<string, string|int>  $configOptions  module key => for a dropdown or radio option, the selected value's
     *                                                    module value (its label when the module value is empty); for a
     *                                                    quantity option, the quantity; for a yes/no option, "1" when
     *                                                    ticked and "0" when not. Options without a module key, and
     *                                                    selections that no longer match a value of the option, are left
     *                                                    out.
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
