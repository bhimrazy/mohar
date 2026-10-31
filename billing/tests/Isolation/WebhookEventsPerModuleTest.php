<?php

use App\Support\Events\WebhookEvents;
use Tests\TestCase;

/*
 * Each module booted alone registers webhook events of its own area only (ADR 0011), so no module edits a sibling's
 * list (Core is always loaded). Orders also registers the Affiliates event until an Affiliates module exists.
 */

const WEBHOOK_AREAS = [
    'Core' => ['Core'], 'Orders' => ['Orders', 'Affiliates'], 'Billing' => ['Billing'], 'Services' => ['Services'],
    'Domains' => ['Domains'], 'Support' => ['Support'], 'Notifications' => [], 'Catalog' => [], 'Storefront' => [],
];

afterEach(fn () => TestCase::$onlyModules = null);

test('a module booted alone registers only its own webhook events', function (string $module, array $areas) {
    TestCase::$onlyModules = $module;
    $events = $this->createApplication()->make(WebhookEvents::class)->events();

    foreach ($events as $event) {
        expect($event)->toStartWith('App\\Events\\')
            ->and(explode('\\', $event)[2])->toBeIn([...$areas, 'Core']);
    }

    // The module registers something of its own, so the loop above is not checking an empty list.
    expect(array_filter($events, fn (string $e): bool => in_array(explode('\\', $e)[2], $areas, true)) !== [])->toBe($areas !== []);
})->with(function () {
    foreach (WEBHOOK_AREAS as $module => $areas) {
        yield $module => [$module, $areas];
    }
});
