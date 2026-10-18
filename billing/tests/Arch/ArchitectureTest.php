<?php

/*
 * Architecture rules enforced in CI (docs/architecture.md → "Dependency rules").
 * Modules may depend on Core and the shared kernel only; cross-module calls go through
 * App\Contracts, cross-module reactions through App\Events.
 */

/** Read models allowed to depend on every module. */
const READ_MODEL_MODULES = ['Reports'];

/** Every module directory except Core and the read models, so a new module is checked from its first commit. */
define('FEATURE_MODULES', array_values(array_diff(
    array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: []),
    ['Core', ...READ_MODEL_MODULES],
)));

foreach (FEATURE_MODULES as $module) {
    $others = array_values(array_map(
        fn (string $other): string => "App\\Modules\\{$other}",
        array_filter([...FEATURE_MODULES, ...READ_MODEL_MODULES], fn (string $other): bool => $other !== $module),
    ));

    arch("{$module} module does not depend on sibling modules")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($others);
}

arch('Core and the shared kernel do not depend on feature modules')
    ->expect(['App\Modules\Core', 'App\Contracts', 'App\Data', 'App\Events', 'App\Enums', 'App\Support'])
    ->not->toUse(array_map(fn (string $module): string => "App\\Modules\\{$module}", [...FEATURE_MODULES, ...READ_MODEL_MODULES]));

arch('contracts are interfaces')
    ->expect('App\Contracts')
    ->toBeInterfaces();

arch('DTOs and events carry data, never Eloquent models')
    ->expect(['App\Data', 'App\Events'])
    ->not->toUse(['Illuminate\Database\Eloquent\Model', 'App\Models']);

arch('DTOs and events are final and immutable-by-construction')
    ->expect(['App\Data', 'App\Events'])
    ->toBeFinal();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('controllers do not query other modules or the DB facade directly')
    ->expect('App\Modules\*\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB']);
