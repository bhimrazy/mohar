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

// Adopted from review/pr-262-probes (#265): a value longer than its column is refused the same way on SQLite and
// PostgreSQL, instead of being stored on one and raising a QueryException on the other.
test('an over-long ref value is refused the same way on every database', function (RegistrarRef $ref) {
    expect(fn () => app(RegistrarRefs::class)->remember($ref))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('registrar_refs')->count())->toBe(0);
})->with([
    'ref type' => fn () => new RegistrarRef('resellerclub', 'customer_contact_profile', 'client', 1, '9'),
    'sha512 payload hash' => fn () => new RegistrarRef('resellerclub', 'contact', 'client', 1, '9', hash('sha512', 'contact')),
]);

// Adopted from review/pr-262-probes (#265).
test('remembering the same ref three times keeps one row and the last remote id', function () {
    $refs = app(RegistrarRefs::class);

    foreach (['1', '2', '3'] as $remote) {
        $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', 5, $remote));
    }

    expect(DB::table('registrar_refs')->count())->toBe(1)
        ->and($refs->find('resellerclub', 'customer', 'client', 5)?->remoteId)->toBe('3');
});
