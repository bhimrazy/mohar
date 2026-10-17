<?php

use App\Modules\Catalog\Support\Gateways;

it('offers only the gateways every group in the cart allows', function () {
    expect(Gateways::intersection([['a', 'b'], ['b', 'c']]))->toBe(['b']);
});

it('treats unrestricted groups as allowing every gateway', function () {
    expect(Gateways::intersection([null, ['a', 'b']]))->toBe(['a', 'b'])
        ->and(Gateways::intersection([null, null]))->toBeNull();
});

it('returns an empty list when groups share no gateway', function () {
    expect(Gateways::intersection([['a'], ['c']]))->toBe([]);
});

it('reports pairs of groups that share no gateway', function () {
    expect(Gateways::conflicts(['Hosting' => ['a', 'b'], 'VPS' => ['b'], 'SSL' => ['c'], 'Domains' => null]))
        ->toBe([['Hosting', 'SSL'], ['VPS', 'SSL']]);
});
