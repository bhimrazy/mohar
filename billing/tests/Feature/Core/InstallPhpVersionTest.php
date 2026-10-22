<?php

use App\Modules\Core\Install\PhpSecurityRelease;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Support\PlatformDashboard;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('objects'));

/**
 * @return array{0: int, 1: string}
 */
function installWithPhp(string $version): array
{
    app()->instance(PhpSecurityRelease::class, new PhpSecurityRelease($version));
    $status = Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => 'owner@example.test']);

    return [$status, Artisan::output()];
}

test('a patched PHP build passes the install check', function () {
    [$status, $output] = installWithPhp('8.4.27');

    expect($status)->toBe(0)
        ->and($output)->toMatch('/PHP \.+ OK/');
});

test('a vulnerable PHP build only warns outside production, naming both advisories and the release to install', function () {
    [$status, $output] = installWithPhp('8.4.26');

    expect($status)->toBe(0)
        ->and($output)->toMatch('/PHP \.+ WARNING/')
        ->and($output)->toContain('CVE-2026-109618', 'CVE-2026-109619', '8.4.27');
});

test('in production a vulnerable PHP build stops a first install but only warns on an existing installation', function () {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        [$status, $output] = installWithPhp('8.5.11');
        expect($status)->toBe(1)
            ->and($output)->toMatch('/PHP \.+ FAILED/')
            ->and($output)->toContain('8.5.12')
            ->and(Currency::query()->count())->toBe(0);

        // The re-run after an upgrade: the deploy goes on while the host's PHP package catches up.
        Currency::factory()->create(['code' => 'USD', 'is_default' => true]);
        [$status, $output] = installWithPhp('8.5.11');
        expect($status)->toBe(0)
            ->and($output)->toMatch('/PHP \.+ WARNING/');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

test('the dashboard shows a red PHP check only in production and only while PHP is vulnerable', function () {
    $check = function (string $version): ?array {
        app()->instance(PhpSecurityRelease::class, new PhpSecurityRelease($version));
        $dashboard = new Dashboard;
        app(PlatformDashboard::class)->registerPhpRuntime($dashboard);

        return collect($dashboard->resolveHealth('admin', actingAsStaff(['core.settings.view'])))->firstWhere('key', 'platform.php_runtime');
    };

    expect($check('8.4.26'))->toBeNull();

    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect($check('8.4.27'))->toBeNull()
            ->and($check('8.4.26'))->toMatchArray(['tone' => 'danger', 'value' => 'Upgrade to 8.4.27'])
            ->and($check('8.4.26')['detail'])->toContain('CVE-2026-109618', 'queue workers');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});
