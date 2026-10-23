<?php

/*
 * Each module registers its own webhook types (ADR 0011). The arch plugin can't see method calls, so this scans the
 * module providers: only a module's own provider calls WebhookEvents::register(), and only for events of its own area.
 */

/** Event areas a module may register besides its own name. Affiliate referrals are captured and paid in Orders. */
const WEBHOOK_EXTRA_AREAS = ['Orders' => ['Affiliates']];

/**
 * @return array<string, array<string, list<string>>> module => provider file => event areas it registers
 */
function webhookRegistrations(): array
{
    $found = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Modules/*/*.php') ?: [] as $file) {
        $module = basename(dirname($file));
        $code = (string) file_get_contents($file);

        if (! str_contains($code, 'WebhookEvents')) {
            continue;
        }

        preg_match_all('/^use App\\\\Events\\\\(\w+)\\\\(\w+);/m', $code, $uses, PREG_SET_ORDER);
        $areaOf = array_column($uses, 1, 2);
        preg_match_all('/->register\(\s*(\w+)::class\s*,\s*[\'"][\w.]+[\'"]/', $code, $calls);

        $found[$module][basename($file)] = array_values(array_unique(array_map(fn (string $event): string => $areaOf[$event] ?? "unresolved:{$event}", $calls[1])));
    }

    return $found;
}

test('webhook events are registered only by the owning module\'s provider, for its own events', function () {
    $registrations = webhookRegistrations();

    expect($registrations)->not->toBe([]);

    foreach ($registrations as $module => $files) {
        foreach ($files as $file => $areas) {
            $allowed = [$module, ...(WEBHOOK_EXTRA_AREAS[$module] ?? [])];

            expect($file)->toBe("{$module}ServiceProvider.php")
                ->and(array_diff($areas, $allowed))->toBe([], "{$module} registers webhook events of another module");
        }
    }
});

test('Notifications keeps no webhook list of its own', function () {
    expect(array_filter(webhookRegistrations()['Notifications'] ?? []))->toBe([])
        ->and(glob(dirname(__DIR__, 2).'/app/Modules/Notifications/Webhooks/WebhookEventTypes.php'))->toBe([]);
});

afterAll(fn () => releaseArchCache());
