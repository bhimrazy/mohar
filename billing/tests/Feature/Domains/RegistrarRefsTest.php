<?php

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use Illuminate\Support\Facades\DB;

test('a remembered remote object is found again for the same local record and payload only', function () {
    $refs = app(RegistrarRefs::class);
    $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', 42, '9001'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', 42, '501', 'hash-a'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', 42, '502', 'hash-b'));

    expect($refs->find('resellerclub', 'customer', 'client', 42))->toEqual(new RegistrarRef('resellerclub', 'customer', 'client', 42, '9001'))
        ->and($refs->find('resellerclub', 'contact', 'client', 42, 'hash-b')?->remoteId)->toBe('502')
        ->and($refs->find('resellerclub', 'contact', 'client', 42))->toBeNull()
        ->and($refs->find('namecheap', 'customer', 'client', 42))->toBeNull()
        ->and($refs->find('resellerclub', 'customer', 'client', 43))->toBeNull();
});

test('remembering the same record again replaces it, and its secret is stored encrypted', function () {
    $refs = app(RegistrarRefs::class);
    $refs->remember(new RegistrarRef('opensrs', 'profile', 'client', 7, 'old', secret: 'Profile-Pass-1'));
    $refs->remember(new RegistrarRef('opensrs', 'profile', 'client', 7, 'new', secret: 'Profile-Pass-2'));

    $row = DB::table('registrar_refs')->sole();

    expect($row->remote_id)->toBe('new')
        ->and($row->secret)->not->toContain('Profile-Pass')
        ->and($refs->find('opensrs', 'profile', 'client', 7)?->secret)->toBe('Profile-Pass-2');
});
