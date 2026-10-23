<?php

namespace App\Contracts\Install;

/** Setting keys one module's installer writes so another module's installer can follow the same first install. */
interface InstallMarkers
{
    /** The country (ISO 3166-1 alpha-2) Core chose on a first install; absent on an existing installation. */
    public const REGIONAL_COUNTRY = 'core.regional_defaults';
}
