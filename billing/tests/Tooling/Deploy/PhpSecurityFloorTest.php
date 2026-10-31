<?php

/*
 * The PHP security floor (CVE-2026-109618, CVE-2026-109619) is written in four places: App\Modules\Core\Install\
 * PhpSecurityRelease (install check and dashboard), the prod stage of deploy/Dockerfile (refuses to build below it),
 * and the operator docs for Docker-less and cPanel hosts. These probes keep the copies from drifting apart.
 */

use App\Modules\Core\Install\PhpSecurityRelease;

function d5RepoFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/'.$path);
}

test('the version check knows the patched release of each supported minor and nothing older passes', function (string $version, ?string $upgrade) {
    $php = new PhpSecurityRelease($version);

    expect($php->requiredUpgrade())->toBe($upgrade)
        ->and($php->vulnerable())->toBe($upgrade !== null);
})->with([
    'vulnerable 8.4' => ['8.4.26', '8.4.27'],
    'old 8.4 (dev and CI builds)' => ['8.4.15', '8.4.27'],
    'patched 8.4' => ['8.4.27', null],
    'newer 8.4' => ['8.4.30', null],
    'vulnerable 8.5' => ['8.5.11', '8.5.12'],
    'patched 8.5' => ['8.5.12', null],
    'a distribution suffix' => ['8.4.26-1+ubuntu24.04.1', '8.4.27'],
    'a newer minor than the list knows' => ['8.6.0', null],
]);

test('the warning names both advisories, the release to install and the restart of long-running processes', function () {
    expect((new PhpSecurityRelease('8.4.26'))->problem())
        ->toContain('PHP 8.4.26', 'CVE-2026-109618', 'CVE-2026-109619', 'Upgrade PHP to 8.4.27 or newer', 'queue workers')
        ->and((new PhpSecurityRelease('8.4.27'))->problem())->toBeNull();
});

test('the production image refuses to build below the same 8.4 floor the app checks, from a php8.4 base', function () {
    $dockerfile = d5RepoFile('deploy/Dockerfile');
    $floor = PhpSecurityRelease::PATCHED['8.4'];

    expect($dockerfile)->toMatch('/^FROM dunglas\/frankenphp:[0-9.]+-php8\.4-trixie@sha256:[0-9a-f]{64} AS php$/m')
        ->and($dockerfile)->toContain("version_compare(PHP_VERSION, \"{$floor}\", \"<\")");

    // The prod stage runs the guard, not only a builder stage.
    $prod = substr($dockerfile, (int) strpos($dockerfile, 'FROM runtime AS prod'));
    expect($prod)->toContain("version_compare(PHP_VERSION, \"{$floor}\", \"<\")");
});

test('the install and cPanel docs state the floors and the advisories the app checks', function (string $doc) {
    $text = d5RepoFile($doc);

    expect($text)->toContain(...PhpSecurityRelease::ADVISORIES);

    foreach (PhpSecurityRelease::PATCHED as $release) {
        expect($text)->toContain($release);
    }
})->with(['billing/docs/devops/install.md', 'billing/docs/devops/cpanel.md']);
