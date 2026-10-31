<?php

use App\Modules\Core\Security\EncryptedTargets;
use App\Support\Security\EncryptedStorage;
use Tests\TestCase;

beforeAll(fn () => TestCase::$onlyModules = 'Core');
afterAll(fn () => TestCase::$onlyModules = null);

test('with only Core loaded, the encrypted storage is exactly Core\'s and resolves to its targets', function () {
    expect(app(EncryptedStorage::class)->keys())->toBe(['core_settings', 'core_two_factor_secret', 'core_install_secrets', 'core_two_factor_recovery_codes'])
        ->and(array_keys(app(EncryptedTargets::class)->all()))->toBe(['install_secrets.value', 'settings.value', 'users.two_factor_recovery_codes', 'users.two_factor_secret']);
});
