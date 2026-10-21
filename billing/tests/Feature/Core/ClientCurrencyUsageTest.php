<?php

use App\Support\Clients\ClientCurrencyUsage;
use Illuminate\Support\Facades\Log;

/**
 * The aggregating-registry shape (ADR 0012 addendum): register(key, Closure, replace), keys like every registry, and
 * the union of every source's answer, each source asked only for the clients not found yet.
 */
test('keys are 1 to 50 lowercase letters, digits or underscores', function (string $key) {
    (new ClientCurrencyUsage)->register($key, fn (array $ids): array => []);
})->with(['', 'Billing', 'billing.invoices', 'billing-invoices', str_repeat('a', 51)])->throws(InvalidArgumentException::class);

test('a key is registered once unless replace is passed, and a replacement is logged', function () {
    Log::spy();
    $usage = new ClientCurrencyUsage;
    $usage->register('billing', fn (array $ids): array => []);

    expect(fn () => $usage->register('billing', fn (array $ids): array => $ids))->toThrow(LogicException::class);

    $usage->register('billing', fn (array $ids): array => $ids, replace: true);

    expect($usage->keys())->toBe(['billing'])
        ->and($usage->clientsInUse([7]))->toBe([7]);
    Log::shouldHaveReceived('info')->once()->with('Client currency usage source replaced.', ['key' => 'billing']);
});

test('the answer is the union of every source, each asked only for the clients not found yet', function () {
    $asked = [];
    $usage = new ClientCurrencyUsage;
    $usage->register('services', function (array $ids) use (&$asked): array {
        $asked['services'] = $ids;

        return ['1', 1];
    });
    $usage->register('orders', function (array $ids) use (&$asked): array {
        $asked['orders'] = $ids;

        return [3, 99];
    });

    expect($usage->clientsInUse([1, 2, 3]))->toEqualCanonicalizing([1, 3])
        ->and($asked)->toBe(['services' => [1, 2, 3], 'orders' => [2, 3]]);
});

test('with no source registered no client is in use', function () {
    expect((new ClientCurrencyUsage)->clientsInUse([1, 2]))->toBe([]);
});
