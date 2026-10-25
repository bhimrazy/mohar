<?php

/*
 * Whole-app rules as a text scan, not arch rules: an arch rule over `App` or `App\Modules` makes the plugin parse every
 * file into memory (about 0.7 MB each), which put ExtensionsTest.php over the 1 GB memory_limit once app/ passed
 * 1,600 files. The removed classes no longer exist, so any use of them has to name them in full.
 */

/** The module-internal SPIs that ADR 0009 replaced with kernel contracts. */
const REMOVED_SPI = [
    'App\Modules\Services\Drivers\Provisioning\ProvisioningDriver',
    'App\Modules\Services\Drivers\Provisioning\ServiceContext',
    'App\Modules\Services\Drivers\Provisioning\DriverMetadata',
    'App\Modules\Services\Drivers\Provisioning\DriverResult',
    'App\Modules\Services\Drivers\Provisioning\DriverRegistry',
    'App\Modules\Services\Drivers\Provisioning\UsageRow',
    'App\Modules\Services\Exceptions\RetryableProvisioningException',
    'App\Modules\Services\Support\Redactor',
    'App\Modules\Domains\Drivers\Registrars\Registrar',
    'App\Modules\Domains\Drivers\Registrars\Data',
    'App\Modules\Domains\Drivers\Registrars\RegistrarManager',
    'App\Modules\Domains\Exceptions\RegistrarException',
    'App\Modules\Domains\Exceptions\RegistrarTimeout',
    'App\Modules\Domains\Exceptions\CapabilityNotSupported',
];

/**
 * PHP files under the given app/ directory, as path => source.
 *
 * @return array<string, string>
 */
function appSources(string $directory = ''): array
{
    $root = dirname(__DIR__, 2).'/app'.($directory === '' ? '' : '/'.$directory);
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

/** Whether the source names the class: in full, or as a namespace segment followed by `\`, `;`, `:` or a non-name character. */
function namesClass(string $source, string $class): bool
{
    return preg_match('/(?<![\w\\\\])\\\\?'.preg_quote($class, '/').'(?![\w])/', $source) === 1;
}

test('nothing uses the removed module-internal provisioning and registrar SPIs (ADR 0009)', function () {
    $uses = [];

    foreach (appSources() as $path => $source) {
        foreach (REMOVED_SPI as $class) {
            if (namesClass($source, $class)) {
                $uses[] = basename($path).' names '.$class;
            }
        }
    }

    expect($uses)->toBe([]);
});

test('no module extends the kernel driver registry (ADR 0012)', function () {
    $extending = [];

    foreach (appSources('Modules') as $path => $source) {
        $imported = namesClass($source, 'App\Support\Integrations\DriverRegistry');

        if (preg_match('/\bextends\s+\\\\?App\\\\Support\\\\Integrations\\\\DriverRegistry\b/', $source) === 1
            || ($imported && preg_match('/\bextends\s+DriverRegistry\b/', $source) === 1)) {
            $extending[] = basename($path);
        }
    }

    expect($extending)->toBe([]);
});

test('the scans catch a use of a removed class and an extended registry', function () {
    expect(namesClass("use App\\Modules\\Services\\Support\\Redactor;\n", 'App\Modules\Services\Support\Redactor'))->toBeTrue()
        ->and(namesClass('throw new \App\Modules\Domains\Exceptions\RegistrarTimeout();', 'App\Modules\Domains\Exceptions\RegistrarTimeout'))->toBeTrue()
        ->and(namesClass('use App\Modules\Services\Support\RedactorRules;', 'App\Modules\Services\Support\Redactor'))->toBeFalse()
        ->and(namesClass('use App\Modules\Domains\Drivers\Registrars\RegistrarFactory;', 'App\Modules\Domains\Drivers\Registrars\Registrar'))->toBeFalse()
        ->and(appSources('Modules'))->not->toBe([]);
});
