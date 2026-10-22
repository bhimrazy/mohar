<?php

use Tests\Support\Contracts\ModuleRegistrarDriverContract;

/**
 * Every registrar driver shipped with the Domains module passes the shared contract (registrars.md §7).
 */
ModuleRegistrarDriverContract::verify('log');
