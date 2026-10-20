<?php

/*
 * Security review of PR #80: structural guarantees of deploy/compose.prod.yaml and deploy/caddy/Caddyfile.
 * The codespace cannot run the stack, so these pin the configuration itself.
 */

use Symfony\Component\Yaml\Yaml;

function prodCompose(): array
{
    return Yaml::parseFile(base_path('../deploy/compose.prod.yaml'));
}

/** @return list<string> */
/** Every service that runs the app's own image (web, workers, scheduler, migrate), whatever it is named. */
function phpServices(): array
{
    return collect(prodCompose()['services'])
        ->filter(fn (array $service) => str_starts_with((string) ($service['image'] ?? ''), '${APP_IMAGE'))
        ->keys()->all();
}

test('only caddy publishes ports, and the data stores sit on the internal network alone', function () {
    $compose = prodCompose();

    $published = collect($compose['services'])->filter(fn (array $service) => ! empty($service['ports']))->keys();
    expect($published->all())->toBe(['caddy'])
        ->and($compose['networks']['backend']['internal'] ?? false)->toBeTrue()
        ->and($compose['services']['postgres']['networks'])->toBe(['backend'])
        ->and($compose['services']['rustfs']['networks'])->toBe(['backend'])
        ->and($compose['services']['caddy']['networks'])->toBe(['edge']);

    // Nothing but caddy and the two colours' web containers on the trusted edge range.
    $onEdge = collect($compose['services'])
        ->filter(fn (array $service) => in_array('edge', $service['networks'] ?? [], true))->keys()->sort()->values();
    expect($onEdge->all())->toBe(['app-blue', 'app-green', 'caddy']);
});

test('the app trusts forwarded headers only from the edge network range, never "*"', function () {
    $compose = prodCompose();
    $edge = $compose['networks']['edge']['ipam']['config'][0]['subnet'];

    foreach (phpServices() as $name) {
        $trusted = $compose['services'][$name]['environment']['TRUSTED_PROXIES'];
        expect($trusted)->toBe($edge)->not->toContain('*');
    }
});

test('no container gets the docker socket, host namespaces or extra privileges', function () {
    foreach (prodCompose()['services'] as $name => $service) {
        $volumes = implode(' ', array_map(fn ($v) => is_array($v) ? ($v['source'] ?? '') : $v, $service['volumes'] ?? []));

        expect($volumes)->not->toContain('docker.sock', "{$name} mounts the docker socket")
            ->and($service['privileged'] ?? false)->toBeFalse()
            ->and($service['network_mode'] ?? null)->not->toBe('host')
            ->and($service['pid'] ?? null)->not->toBe('host')
            ->and($service['devices'] ?? [])->toBe([]);
    }

    foreach ([...phpServices(), 'caddy'] as $name) {
        $service = prodCompose()['services'][$name];
        expect($service['cap_drop'])->toBe(['ALL'])
            ->and($service['security_opt'])->toContain('no-new-privileges:true')
            ->and($service['read_only'])->toBeTrue()
            ->and($service['cap_add'] ?? [])->toBe($name === 'caddy' ? ['NET_BIND_SERVICE'] : []);
    }
});

test('database and object-store secrets have no defaults', function () {
    $raw = file_get_contents(base_path('../deploy/compose.prod.yaml'));

    expect($raw)->toContain('${DB_PASSWORD:?')
        ->toContain('${OBJECTS_ACCESS_KEY:?')
        ->toContain('${OBJECTS_SECRET_KEY:?')
        ->not->toMatch('/\$\{(DB_PASSWORD|OBJECTS_ACCESS_KEY|OBJECTS_SECRET_KEY|APP_KEY):-/');
});
