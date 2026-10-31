<?php

use App\Providers\AppServiceProvider;
use App\Providers\ExtensionsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ModulesServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    ModulesServiceProvider::class,
    ExtensionsServiceProvider::class,
];
