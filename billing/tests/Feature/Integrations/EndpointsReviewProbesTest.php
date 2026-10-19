<?php

/*
 * Probes from the PR #92 review (ADR 0016 S1): what the install check and the dashboard health check show about
 * INTEGRATION_ENDPOINTS. The first fails at 9784842.
 */

use App\Modules\Core\Install\EnvironmentChecks;
use App\Modules\Platform\Support\PlatformDashboard;
use App\Support\Dashboard\Dashboard;
use App\Support\Integrations\Endpoints;

function probeOverrides(string $json): void
{
    config(['integrations.endpoints.overrides' => $json, 'integrations.endpoints.ca_bundle' => null]);
    app()->forgetInstance(Endpoints::class);
}

/**
 * @param  list<string>  $permissions
 * @return array<string, mixed>|null
 */
function probeHealth(array $permissions): ?array
{
    $dashboard = new Dashboard;
    app(PlatformDashboard::class)->registerIntegrationEndpoints($dashboard);

    return collect($dashboard->resolveHealth('admin', actingAsStaff($permissions)))->firstWhere('key', 'platform.integration_endpoints');
}

test('a malformed override key is never echoed verbatim to staff or the installer', function () {
    // A URL pasted as the key (or any other operator text): only keys matching the key pattern may be shown.
    probeOverrides('{"https://admin:hunter2@sim.example.test":"https://sim.example.test"}');
    app()->detectEnvironment(fn () => 'production');

    $install = collect(app(EnvironmentChecks::class)->run())->firstWhere('label', 'Integration endpoints');

    expect($install?->detail)->not->toBeNull()->not->toContain('hunter2')
        ->and(probeHealth(['core.settings.view'])['detail'] ?? '')->not->toContain('hunter2');
});

test('the integration endpoints health check needs core.settings.view and never shows a URL', function () {
    probeOverrides('{"stripe.api":"https://sim.example.test:8443/base"}');
    app()->detectEnvironment(fn () => 'production');

    expect(probeHealth(['core.automation.view']))->toBeNull()
        ->and((string) json_encode(probeHealth(['core.settings.view'])))->toContain('stripe.api')->not->toContain('sim.example.test');

    app()->detectEnvironment(fn () => 'local');
    expect((string) json_encode(probeHealth(['core.settings.view'])))->not->toContain('sim.example.test');
});
