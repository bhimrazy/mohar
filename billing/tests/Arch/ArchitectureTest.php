<?php

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;

/*
 * Architecture rules enforced in CI (docs/architecture.md → "Dependency rules").
 * Modules may depend on Core and the shared kernel only; cross-module calls go through
 * App\Contracts, cross-module reactions through App\Events. Hygiene and extension rules are in HygieneTest.php
 * and ExtensionsTest.php: one file per group keeps the arch plugin's parsed-source cache small (tests/Pest.php).
 */

/** Read models allowed to depend on every module. */
const READ_MODEL_MODULES = ['Reports'];

/**
 * Modules that exist on other branches or are planned. Kept next to the glob below so a branch that imports a
 * module merged elsewhere (or not yet merged) is still caught.
 */
const PLANNED_MODULES = [
    'Catalog', 'Orders', 'Billing', 'Services', 'Domains', 'Support',
    'Notifications', 'Affiliates', 'Platform', 'Ai', 'Migration', 'Themes', 'PaymentsNepal', 'PaymentsStripe',
];

/** Every planned module plus every module directory on this branch, except Core and the read models. */
define('FEATURE_MODULES', array_values(array_unique(array_diff(
    [...PLANNED_MODULES, ...array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: [])],
    ['Core', ...READ_MODEL_MODULES],
))));

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

arch('every kernel event is a DomainEvent with an occurrence id (ADR 0011)')
    ->expect('App\Events')
    ->classes()
    ->toImplement(DomainEvent::class)
    ->toUseTrait(IdentifiesOccurrence::class);

/**
 * PHP files under the given directories of the billing app, as path => source text.
 *
 * @param  list<string>  $directories
 * @return array<string, string>
 */
function sourcesUnder(array $directories): array
{
    $sources = [];

    foreach ($directories as $directory) {
        $path = dirname(__DIR__, 2).'/'.$directory;

        if (! is_dir($path)) {
            continue;
        }

        foreach (new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)), '/\.php$/') as $file) {
            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

test('the app never references the dev-only simulators package (ADR 0016)', function () {
    // The package is installed only with require-dev, so a reference would break a --no-dev install. Checked as raw
    // text, case-insensitively (PHP names are), including group imports such as `use Mohar\{Simulators\…}`.
    $offenders = array_keys(array_filter(
        sourcesUnder(['app', 'bootstrap', 'config', 'routes']),
        fn (string $source): bool => preg_match('/Mohar\s*\\\\{1,2}\s*(\{\s*)?Simulators/i', $source) === 1,
    ));

    expect($offenders)->toBe([]);
});

/**
 * Driver code that knows about simulators or overrides (ADR 0016 §5): every file under a module's Drivers/ directory,
 * plus the module classes those files import (helpers such as PaymentsNepal\Support\ProviderHttp). The three demo
 * drivers simulate a provider by design and are exempt by full path. Driver code may not mention "simulat" or ask
 * Endpoints whether an override is active.
 *
 * @param  array<string, string>  $sources  path relative to billing/ => source text
 * @return list<string> offending paths
 */
function driverCodeAwareOfSimulators(array $sources): array
{
    $demo = [
        'app/Modules/Services/Drivers/Provisioning/LogDriver.php',
        'app/Modules/Domains/Drivers/Registrars/LogRegistrar.php',
        'app/Modules/Billing/Drivers/Gateways/SandboxCardDriver.php',
    ];
    $scanned = array_filter($sources, fn (string $path): bool => str_starts_with($path, 'app/Modules/') && str_contains($path, '/Drivers/'), ARRAY_FILTER_USE_KEY);

    foreach ($scanned as $source) {
        preg_match_all('/^use\s+App\\\\Modules\\\\([\w\\\\]+)\s*;/mi', $source, $imports);

        foreach ($imports[1] as $class) {
            $path = 'app/Modules/'.str_replace('\\', '/', $class).'.php';

            if (isset($sources[$path])) {
                $scanned[$path] = $sources[$path];
            }
        }
    }

    return array_keys(array_filter(
        $scanned,
        fn (string $source, string $path): bool => ! in_array($path, $demo, true)
            && preg_match('/simulat|activeKeys\s*\(|developmentEnvironment\s*\(|configuredKeys\s*\(/i', $source) === 1,
        ARRAY_FILTER_USE_BOTH,
    ));
}

test('no provider driver or driver helper knows about simulators (ADR 0016 §5): there is no simulator branch', function () {
    $root = dirname(__DIR__, 2).'/';
    $sources = [];

    foreach (sourcesUnder(['app/Modules']) as $path => $source) {
        $sources[substr($path, strlen($root))] = $source;
    }

    expect(driverCodeAwareOfSimulators($sources))->toBe([]);
});

test('the driver rule exempts only the three demo drivers, by full path, and follows driver imports', function () {
    $mention = "<?php\n// Talks to the simulator.\n";

    expect(driverCodeAwareOfSimulators([
        'app/Modules/Services/Drivers/Provisioning/LogDriver.php' => $mention,
        'app/Modules/PaymentsNepal/Drivers/LogDriver.php' => $mention,
        'app/Modules/Billing/Drivers/Gateways/GatewayManager.php' => $mention,
        'app/Modules/PaymentsNepal/Drivers/KhaltiDriver.php' => "<?php\nuse App\\Modules\\PaymentsNepal\\Support\\ProviderHttp;\n",
        'app/Modules/PaymentsNepal/Support/ProviderHttp.php' => "<?php\nif (Endpoints::developmentEnvironment(app())) {}\n",
        'app/Modules/PaymentsNepal/Support/Unrelated.php' => $mention,
    ]))->toBe([
        'app/Modules/PaymentsNepal/Drivers/LogDriver.php',
        'app/Modules/Billing/Drivers/Gateways/GatewayManager.php',
        'app/Modules/PaymentsNepal/Support/ProviderHttp.php',
    ]);
});

afterAll(fn () => releaseArchCache());
