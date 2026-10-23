<?php

namespace App\Contracts\Domains\Registrar;

/**
 * Marker: the driver works without a saved account (the demo log driver, test fakes). Any other driver that declares
 * settings is not active until staff save its account (RegistrarDriverRegistry::needsAccount).
 */
interface StandaloneRegistrar {}
