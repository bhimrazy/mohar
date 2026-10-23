<?php

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

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

test('a secret is stored encrypted', function () {
    app(RegistrarRefs::class)->remember(new RegistrarRef('opensrs', 'profile', 'client', 7, 'p-1', secret: 'Profile-Pass-1'));

    expect(DB::table('registrar_refs')->sole()->secret)->not->toContain('Profile-Pass')
        ->and(app(RegistrarRefs::class)->find('opensrs', 'profile', 'client', 7)?->secret)->toBe('Profile-Pass-1');
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

test('remembering the same ref three times keeps one row and the first remote id', function () {
    $refs = app(RegistrarRefs::class);

    foreach (['1', '2', '3'] as $remote) {
        expect($refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', 5, $remote))->remoteId)->toBe('1');
    }

    expect(DB::table('registrar_refs')->count())->toBe(1)
        ->and($refs->find('resellerclub', 'customer', 'client', 5)?->remoteId)->toBe('1');
});

test('security:reencrypt moves a ref secret to the new key, which then reads it alone', function () {
    $restart = function (string $current, array $previous = []): void {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Facade::clearResolvedInstance('encrypter');
    };
    [$k1, $k2] = ['base64:'.base64_encode(random_bytes(32)), 'base64:'.base64_encode(random_bytes(32))];

    $restart($k1);
    app(RegistrarRefs::class)->remember(new RegistrarRef('opensrs', 'profile', 'client', 7, 'p-1', secret: 'Profile-Pass-1'));

    $restart($k2, [$k1]);
    $this->artisan('security:reencrypt')->assertExitCode(0);

    $restart($k2);
    $raw = (string) DB::table('registrar_refs')->value('secret');

    expect((new Encrypter(base64_decode(substr($k2, 7)), 'AES-256-CBC'))->decrypt($raw, false))->toBe('Profile-Pass-1')
        ->and(app(RegistrarRefs::class)->find('opensrs', 'profile', 'client', 7)?->secret)->toBe('Profile-Pass-1');
});
