<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Support\Security\RecordingPermissionRegistry;
use Tests\Support\Security\RouteScan;

/**
 * Best-practices audit 2026-10-31, M2: every admin route refuses a staff member whose role grants nothing. Each route
 * (each settings section, each bulk action) is called once as such a staff member, with a recent password so step-up
 * cannot answer first, and must answer 403 after checking a permission. A route that answers anything else is either
 * listed below with the reason, or a missing check.
 */

/** Admin routes any staff member may use without a permission, and why. Each still filters by permission inside. */
const SWEEP_OPEN = [
    'admin.dashboard' => 'The landing page after sign-in: every widget, to-do and notice is resolved per permission, so an empty role sees an empty page.',
    'admin.platform.search' => 'The command palette: every search provider names a permission and is skipped without it (asserted below).',
    'admin.appearance.preference' => 'A staff member\'s own theme and density; it changes only their own login.',
    'admin.appearance.themes.schema' => 'The static JSON schema of theme tokens, the same for every install; no stored data.',
];

/** Request bodies the sweep sends so a route reaches its permission check (beyond RouteScan::bodies()). */
const SWEEP_BODIES = [
    'admin.platform.search' => ['term' => ['q' => 'sweep']],
];

/**
 * @return array{refused: list<string>, open: list<string>, reached: list<string>}
 */
function sweepAdminRoutes(): array
{
    foreach (array_keys(Arr::dot(config('features'))) as $flag) {
        config(['features.'.$flag => true]);
    }

    Queue::fake();
    Mail::fake();
    Notification::fake();
    Http::fake();

    $recorder = new RecordingPermissionRegistry(app(PermissionRegistry::class));
    app()->instance(PermissionRegistry::class, $recorder);
    $nobody = User::factory()->staff([])->create(['email' => 'no-permission-sweep@example.test']);
    $nobody->role->update(['name' => 'Permission sweep, no permissions']);
    $result = ['refused' => [], 'open' => [], 'reached' => []];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'admin.')) {
            continue;
        }

        $variants = [];

        if (in_array('section', $route->parameterNames(), true)) {
            foreach (array_keys(app(SettingsRegistry::class)->sections()) as $section) {
                $variants[] = [$section, $section, []];
            }
        } else {
            foreach ((RouteScan::bodies() + SWEEP_BODIES)[$name] ?? ['' => []] as $variant => $body) {
                $variants[] = [(string) $variant, null, $body];
            }
        }

        foreach ($variants as [$variant, $section, $body]) {
            $label = $variant === '' ? $name : "{$name}[{$variant}]";
            fake()->unique(true);
            DB::beginTransaction();

            try {
                foreach ([false, true] as $blank) {
                    $parameters = RouteScan::parameters($route, $section, $blank);

                    if ($parameters === null) {
                        break;
                    }

                    $recorder->checked = [];
                    $status = sweepRequest($route, $parameters, $nobody, $body)->status();

                    if ($status === 403 || ! (in_array($status, [404, 405], true) || $status >= 500)) {
                        break;
                    }
                }

                $checked = array_values(array_unique($recorder->checked));

                if ($parameters !== null && $status === 403 && $checked !== []) {
                    $result['refused'][] = $label;
                } elseif (array_key_exists($name, SWEEP_OPEN)) {
                    $result['open'][] = $name;
                } else {
                    $result['reached'][] = $label.($parameters === null ? ' (parameters)' : " ({$status}".($checked === [] ? ', no permission checked' : '').')');
                }
            } finally {
                DB::rollBack();
            }
        }
    }

    return $result;
}

/**
 * @param  array<string, mixed>  $parameters
 * @param  array<string, mixed>  $body
 */
function sweepRequest(RoutingRoute $route, array $parameters, User $user, array $body = []): TestResponse
{
    $test = test();
    Cache::store(config('cache.limiter'))->flush();
    $test->flushSession();
    $test->actingAs($user)->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);
    $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');
    // A signed route answers 403 for a bad signature before the controller runs, so the sweep signs it.
    $url = in_array('signed', $route->gatherMiddleware(), true)
        ? URL::temporarySignedRoute($route->getName(), now()->addMinutes(5), $parameters)
        : route($route->getName(), $parameters);

    return $test->json($method, $url, $body, ['Referer' => url('/admin')]);
}

test('every admin route refuses a staff member without permissions', function () {
    $scan = sweepAdminRoutes();

    expect($scan['reached'])->toBe([])
        // Vacuity guards: the sweep reaches money, settings and signed routes, and every open entry is still a route.
        ->and($scan['refused'])->toContain(
            'admin.billing.invoices.capture',
            'admin.billing.invoices.bulk[mark_paid]',
            'admin.billing.transactions.refund',
            'admin.platform.settings.update[support_inbound]',
            'admin.platform.impersonation.enter',
            'admin.storefront.website.preview',
            'admin.clients.index',
        )
        ->and(count($scan['refused']))->toBeGreaterThan(400)
        ->and(array_values(array_diff(array_keys(SWEEP_OPEN), $scan['open'])))->toBe([]);
});

test('every admin route refuses a signed-in client', function () {
    $client = User::factory()->create();
    $routes = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'admin.')) {
            continue;
        }

        // Empty bound models: the staff middleware answers before any model is used.
        $parameters = RouteScan::parameters($route, 'general', blank: true);
        expect($parameters)->not->toBeNull($route->getName())
            ->and(sweepRequest($route, $parameters, $client)->status())->toBe(403, $route->getName());
        $routes++;
    }

    expect($routes)->toBeGreaterThan(300);
});

test('a staff member without permissions finds nothing in the admin search', function () {
    $providers = (fn (): array => $this->providers)->call(app(GlobalSearch::class));

    expect($providers)->not->toBeEmpty()
        ->and(array_filter($providers, fn (array $provider): bool => $provider['permission'] === null))->toBe([]);

    Client::factory()->create(['company_name' => 'Sweep Traders']);
    actingAsStaff([]);

    $this->getJson(route('admin.platform.search', ['q' => 'Sweep']))->assertOk()->assertExactJson(['groups' => []]);
    actingAsStaff(['core.clients.view']);
    expect($this->getJson(route('admin.platform.search', ['q' => 'Sweep']))->json('groups.0.group'))->toBe('Clients');
});
