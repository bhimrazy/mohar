<?php

use App\Modules\Core\Install\CacheLockStore;
use App\Modules\Core\Install\CheckStatus;

test('a database store whose lock_connection names only a redis connection is not accepted', function () {
    config(['cache.default' => 'database', 'cache.stores.database.lock_connection' => 'cache']);

    expect(config('database.connections.cache'))->toBeNull()
        ->and((new CacheLockStore)->assess()['status'])->toBe(CheckStatus::Failed);
});
