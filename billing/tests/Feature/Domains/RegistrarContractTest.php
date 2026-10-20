<?php

use Tests\Support\Contracts\RegistrarDriverContract;

/**
 * Every registrar driver shipped with the Domains module passes the shared contract (registrars.md §7).
 */
RegistrarDriverContract::verify('log');
RegistrarDriverContract::verify('np_manual', extension: '.com.np');
