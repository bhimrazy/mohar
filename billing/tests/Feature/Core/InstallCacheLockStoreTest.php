<?php

use App\Modules\Core\Install\CacheLockStore;
use App\Modules\Core\Install\CheckStatus;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Support\PlatformDashboard;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('objects');
    config(['cache.stores.file.path' => sys_get_temp_dir().'/lock-store-test-'.getmypid()]);
});

/**
 * @return array{0: int, 1: string}
 */
function installWithCacheStore(string $store, bool $production = false, bool $existing = false): array
{
    config(['cache.default' => $store]);

    if ($existing) {
        Currency::factory()->create(['code' => 'USD', 'is_default' => true]);
    }

    if ($production) {
        app()->detectEnvironment(fn (): string => 'production');
    }

    try {
        $status = Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => 'owner@example.test']);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    return [$status, Artisan::output()];
}

test('the array store only warns outside production', function () {
    [$status, $output] = installWithCacheStore('array');

    expect($status)->toBe(0)
        ->and($output)->toMatch('/Cache lock store \.+ WARNING/')
        ->and($output)->toContain('CACHE_STORE=database or redis');
});

test('in production the array store stops a first install but only warns on an existing installation', function () {
    [$status, $output] = installWithCacheStore('array', production: true);
    expect($status)->toBe(1)
        ->and($output)->toMatch('/Cache lock store \.+ FAILED/')
        ->and(Currency::query()->count())->toBe(0);

    [$status, $output] = installWithCacheStore('array', production: true, existing: true);
    expect($status)->toBe(0)
        ->and($output)->toMatch('/Cache lock store \.+ WARNING/');
});

test('the file store warns, saying it is safe on a single host only, even on a first production install', function () {
    [$status, $output] = installWithCacheStore('file', production: true);

    expect($status)->toBe(0)
        ->and($output)->toMatch('/Cache lock store \.+ WARNING/')
        ->and($output)->toContain('single host only');
});

test('the database store passes the install check', function () {
    [$status, $output] = installWithCacheStore('database', production: true);

    expect($status)->toBe(0)
        ->and($output)->toMatch('/Cache lock store \.+ OK/');
});

test('shared stores pass and a lock connection that is not configured fails', function () {
    foreach (['redis', 'memcached', 'dynamodb', 'database'] as $store) {
        config(['cache.default' => $store]);
        expect((new CacheLockStore)->assess()['status'])->toBe(CheckStatus::Ok);
    }

    config(['cache.default' => 'database', 'cache.stores.database.lock_connection' => 'missing']);
    $result = (new CacheLockStore)->assess();

    expect($result['status'])->toBe(CheckStatus::Failed)
        ->and($result['detail'])->toContain('lock connection "missing"')
        ->and($result['detail'])->not->toContain(config('app.name'));
});

test('a failover store is judged by the weakest store it lists', function () {
    config(['cache.stores.failover.stores' => ['database', 'array']]);
    config(['cache.default' => 'failover']);
    $result = (new CacheLockStore)->assess();

    expect($result['status'])->toBe(CheckStatus::Failed)->and($result['detail'])->toContain('"array"');
});

test('the dashboard shows the lock store check only in production and only while it is unsafe', function () {
    $check = function (string $store): ?array {
        config(['cache.default' => $store]);
        $dashboard = new Dashboard;
        app(PlatformDashboard::class)->registerCacheLockStore($dashboard);

        return collect($dashboard->resolveHealth('admin', actingAsStaff(['core.settings.view'])))->firstWhere('key', 'platform.cache_lock_store');
    };

    expect($check('array'))->toBeNull();

    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect($check('database'))->toBeNull()
            ->and($check('array'))->toMatchArray(['tone' => 'danger', 'value' => 'Use a shared store'])
            ->and($check('file'))->toMatchArray(['tone' => 'warning', 'value' => 'Single host only']);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});
