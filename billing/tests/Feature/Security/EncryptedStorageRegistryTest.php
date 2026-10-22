<?php

use App\Support\Security\EncryptedColumn;
use App\Support\Security\EncryptedFiles;
use App\Support\Security\EncryptedModel;
use App\Support\Security\EncryptedStorage;

test('keys follow the ADR 0012 pattern, duplicates need replace, and two keys never name the same storage', function () {
    $storage = new EncryptedStorage;
    $storage->register('core_settings', new EncryptedColumn('settings', 'value'));
    $storage->register('kyc_documents_files', new EncryptedFiles('kyc_documents', ['front_path'], 'objects'));
    $storage->register('billing_gateways', new EncryptedModel('App\Modules\Billing\Models\Gateway'));

    expect(fn () => $storage->register('settings.value', new EncryptedColumn('a', 'b')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->register(str_repeat('a', 51), new EncryptedColumn('a', 'b')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->register('core_settings', new EncryptedColumn('settings', 'other')))->toThrow(LogicException::class, 'already registered')
        ->and(fn () => $storage->register('core_copy', new EncryptedColumn('settings', 'value')))->toThrow(LogicException::class, 'the same as [core_settings]')
        ->and(fn () => $storage->register('kyc_thumbnails', new EncryptedFiles('kyc_documents', ['thumb_path'], 'other')))->toThrow(LogicException::class)
        ->and(fn () => $storage->register('billing_copy', new EncryptedModel('\App\Modules\Billing\Models\Gateway')))->toThrow(LogicException::class);

    $storage->register('core_settings', new EncryptedColumn('settings', 'value', 'key', intKey: false), replace: true);

    expect($storage->keys())->toBe(['core_settings', 'kyc_documents_files', 'billing_gateways'])
        ->and($storage->has('core_settings'))->toBeTrue()
        ->and($storage->has('core_copy'))->toBeFalse()
        ->and($storage->all()['core_settings']->intKey)->toBeFalse();
});
