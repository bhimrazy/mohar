<?php

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use App\Modules\Core\Support\NullRegistrarRefs;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Services\EloquentRegistrarRefs;
use App\Modules\Domains\Services\RegistrarManager;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Domains\InMemoryRegistrarRefs;

// remember() is first-writer-wins for every store that keeps refs.
dataset('ref stores', [
    'Domains store' => fn () => new EloquentRegistrarRefs,
    'in-memory fake' => fn () => new InMemoryRegistrarRefs,
]);

test('the second remember of a key returns the first ref and keeps its secret', function (RegistrarRefs $refs) {
    $first = $refs->remember(new RegistrarRef('opensrs', 'profile', 'client', 7, 'p-1', secret: 'Pass-1'));
    $second = $refs->remember(new RegistrarRef('opensrs', 'profile', 'client', 7, 'p-2', secret: 'Pass-2'));

    expect($first->remoteId)->toBe('p-1')
        ->and($second)->toEqual($first)
        ->and($refs->find('opensrs', 'profile', 'client', 7))->toEqual($first);
})->with('ref stores');

test('a different payload hash is a different key', function (RegistrarRefs $refs) {
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', 1, '10', 'hash-a'));

    expect($refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', 1, '11', 'hash-b'))->remoteId)->toBe('11');
})->with('ref stores');

test('a concurrent loser reads the winner instead of overwriting it', function () {
    $refs = new EloquentRegistrarRefs;
    // The winner's row appears between this caller's decision to create and its insert: the insert hits the unique key.
    DB::table('registrar_refs')->insert(['registrar_key' => 'resellerclub', 'ref_type' => 'customer', 'local_type' => 'client', 'local_id' => 5, 'remote_id' => 'winner', 'payload_hash' => '', 'created_at' => now(), 'updated_at' => now()]);

    expect($refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', 5, 'loser'))->remoteId)->toBe('winner')
        ->and(DB::table('registrar_refs')->count())->toBe(1);
});

test('the null default stores nothing and hands the ref back', function () {
    $refs = new NullRegistrarRefs;
    $ref = new RegistrarRef('resellerclub', 'customer', 'client', 5, '9');

    expect($refs->remember($ref))->toBe($ref)
        ->and($refs->find('resellerclub', 'customer', 'client', 5))->toBeNull();
});

test('a registrar that needs an account is not active until one is saved, a standalone one is', function () {
    $registry = app(RegistrarDriverRegistry::class);
    $manager = app(RegistrarManager::class);

    expect($manager->isActive('log'))->toBeTrue()
        ->and($registry->needsAccount('log'))->toBeFalse()
        ->and($registry->needsAccount('resellerclub'))->toBeTrue()
        ->and($manager->isActive('resellerclub'))->toBeFalse();

    RegistrarAccount::query()->create(['key' => 'resellerclub', 'is_active' => true, 'test_mode' => false, 'settings' => []]);

    expect($manager->isActive('resellerclub'))->toBeTrue();
});
