<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Support\Dashboard\Dashboard;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Http\Request;
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
 * (each settings section, each bulk action) is called as such a staff member, with a recent password so step-up cannot
 * answer first. It must answer 403 with a permission denied, and the denial must be the cause: called again with the
 * denied permissions granted, the route answers differently (it runs, or refuses elsewhere or on a further permission,
 * which is granted in turn). A route that answers anything else is listed below with the reason, or a missing check.
 *
 * The sweep proves "no permission, no access". It cannot tell a wrong permission name from the right one.
 */

/** Admin routes any staff member may use without a permission, and why. Each still filters by permission inside. */
const SWEEP_OPEN = [
    'admin.dashboard' => 'The landing page after sign-in: every widget, to-do and health check names a permission (asserted below), so an empty role sees an empty page.',
    'admin.platform.search' => 'The command palette: every search provider names a permission and is skipped without it (asserted below).',
    'admin.appearance.preference' => 'A staff member\'s own theme and density; it changes only their own login.',
    'admin.appearance.themes.schema' => 'The static JSON schema of theme tokens, the same for every install; no stored data.',
];

/** Request bodies, one per permission branch of the route's authorize() (beyond RouteScan::bodies()). */
const SWEEP_BODIES = [
    'admin.platform.search' => ['term' => ['q' => 'sweep']],
    'admin.support.tickets.bulk' => [
        'close' => ['action' => 'close', 'tickets' => [1]],
        'assign' => ['action' => 'assign', 'tickets' => [1]],
        'delete' => ['action' => 'delete', 'tickets' => [1]],
        'block_sender' => ['action' => 'block_sender', 'tickets' => [1]],
    ],
    'admin.billing.credit.refund' => ['recorded' => ['mode' => 'recorded'], 'gateway' => ['mode' => 'gateway']],
];

/** A role gains at most this many rounds of denied permissions before the sweep gives up on a route. */
const SWEEP_ROUNDS = 4;

/**
 * @return array{refused: array<string, list<string>>, open: list<string>, reached: list<string>} refused: label => permissions it required
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
            $outcome = sweepRoute($route, $recorder, $section, $body);

            if ($outcome['required'] !== null) {
                $result['refused'][$label] = $outcome['required'];
            } elseif (array_key_exists($name, SWEEP_OPEN)) {
                $result['open'][] = $name;
            } else {
                $result['reached'][] = "{$label} ({$outcome['reason']})";
            }
        }
    }

    return $result;
}

/**
 * Calls one route as an empty role, then again with each round's denied permissions granted.
 *
 * @param  array<string, mixed>  $body
 * @return array{required: list<string>|null, reason: string} required: the permissions the refusal needed, null when not refused by a permission
 */
function sweepRoute(RoutingRoute $route, RecordingPermissionRegistry $recorder, ?string $section = null, array $body = []): array
{
    // Every call is rolled back, so factories' unique values may repeat.
    fake()->unique(true);
    DB::beginTransaction();

    try {
        $granted = [];
        $previous = null;

        // Fresh models first; if that never reaches a 403, empty bound models.
        foreach ([false, true] as $blank) {
            $parameters = RouteScan::parameters($route, $section, $blank);

            if ($parameters === null) {
                return ['required' => null, 'reason' => 'parameters'];
            }

            $first = sweepCall($route, $parameters, [], $recorder, $body);

            if ($first['status'] === 403 || ! (in_array($first['status'], [404, 405], true) || $first['status'] >= 500)) {
                break;
            }
        }

        if ($first['status'] !== 403 || $first['denied'] === []) {
            return ['required' => null, 'reason' => $first['status'].($first['denied'] === [] ? ', no permission denied' : '')];
        }

        $previous = $first;

        for ($round = 1; $round < SWEEP_ROUNDS; $round++) {
            $granted = array_values(array_unique([...$granted, ...$previous['denied']]));
            $next = sweepCall($route, $parameters, $granted, $recorder, $body);

            // Granting the denied permissions changed nothing: the 403 came from somewhere else.
            if ($next['status'] === 403 && $next['denied'] === [] && $next['site'] === $previous['site']) {
                return ['required' => null, 'reason' => '403 not caused by the denied '.implode(', ', $previous['denied'])];
            }

            if ($next['status'] !== 403 || $next['denied'] === []) {
                return ['required' => $granted, 'reason' => ''];
            }

            $previous = $next;
        }

        return ['required' => null, 'reason' => 'still denied after '.SWEEP_ROUNDS.' rounds: '.implode(', ', $granted)];
    } finally {
        DB::rollBack();
    }
}

/**
 * One call in its own savepoint, as a staff member holding exactly $granted.
 *
 * @param  array<string, mixed>  $parameters
 * @param  list<string>  $granted
 * @param  array<string, mixed>  $body
 * @return array{status: int, denied: list<string>, site: string} denied: permissions looked up and not granted
 */
function sweepCall(RoutingRoute $route, array $parameters, array $granted, RecordingPermissionRegistry $recorder, array $body): array
{
    DB::beginTransaction();

    try {
        $user = User::factory()->staff($granted)->create();
        $recorder->checked = [];
        $response = sweepRequest($route, $parameters, $user, $body);

        return [
            'status' => $response->baseResponse->getStatusCode(),
            'denied' => array_values(array_diff(array_unique($recorder->checked), $granted)),
            'site' => sweepThrowSite($response->baseResponse->exception ?? null),
        ];
    } finally {
        DB::rollBack();
    }
}

/**
 * Where an exception was thrown, as the first application frame: abort() and authorize() throw from vendor code.
 */
function sweepThrowSite(?Throwable $exception): string
{
    if ($exception === null) {
        return '';
    }

    foreach ([['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()] as $frame) {
        if (isset($frame['file']) && ! str_contains($frame['file'], '/vendor/')) {
            return $exception::class.'@'.$frame['file'].':'.($frame['line'] ?? 0);
        }
    }

    return $exception::class;
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
        // Vacuity guards: the sweep reaches money, settings, signed and bulk routes, every branch of the bulk actions,
        // and second permissions behind a first; every open entry is still a route.
        ->and(array_keys($scan['refused']))->toContain(
            'admin.billing.invoices.capture',
            'admin.billing.transactions.refund',
            'admin.platform.settings.update[support_inbound]',
            'admin.platform.impersonation.enter',
            'admin.storefront.website.preview',
            'admin.clients.index',
        )
        ->and(array_intersect_key($scan['refused'], array_flip([
            'admin.billing.invoices.bulk[mark_paid]',
            'admin.billing.invoices.bulk[cancel]',
            'admin.billing.invoices.bulk[remind]',
            'admin.support.tickets.bulk[close]',
            'admin.support.tickets.bulk[delete]',
            'admin.support.tickets.bulk[block_sender]',
            'admin.billing.credit.refund[gateway]',
        ])))->toEqual([
            'admin.billing.invoices.bulk[mark_paid]' => ['billing.payments.manage'],
            'admin.billing.invoices.bulk[cancel]' => ['billing.invoices.manage'],
            'admin.billing.invoices.bulk[remind]' => ['billing.invoices.manage'],
            'admin.support.tickets.bulk[close]' => ['support.tickets.manage'],
            'admin.support.tickets.bulk[delete]' => ['support.tickets.delete'],
            'admin.support.tickets.bulk[block_sender]' => ['support.tickets.manage', 'support.inbound.manage'],
            'admin.billing.credit.refund[gateway]' => ['billing.credit.refund', 'billing.refunds.manage'],
        ])
        ->and(count($scan['refused']))->toBeGreaterThan(400)
        ->and(array_values(array_diff(array_keys(SWEEP_OPEN), $scan['open'])))->toBe([]);
});

test('the sweep does not count a 403 that the denied permission did not cause', function () {
    Route::middleware(['web', 'auth', 'staff'])->group(function (): void {
        // A soft lookup (a UI flag) and then a refusal for an unrelated reason.
        Route::get('admin/zz-sweep-unrelated', function (Request $request) {
            $request->user()->hasPermission('core.clients.view');
            abort(403, 'Not in this state.');
        })->name('admin.zz-sweep-unrelated');
        Route::get('admin/zz-sweep-guarded', function (Request $request) {
            abort_unless($request->user()->hasPermission('core.clients.view'), 403);
            abort(403, 'Not in this state.');
        })->name('admin.zz-sweep-guarded');
    });
    Route::getRoutes()->refreshNameLookups();
    $recorder = new RecordingPermissionRegistry(app(PermissionRegistry::class));
    app()->instance(PermissionRegistry::class, $recorder);

    expect(sweepRoute(Route::getRoutes()->getByName('admin.zz-sweep-unrelated'), $recorder))
        ->toBe(['required' => null, 'reason' => '403 not caused by the denied core.clients.view'])
        ->and(sweepRoute(Route::getRoutes()->getByName('admin.zz-sweep-guarded'), $recorder))
        ->toBe(['required' => ['core.clients.view'], 'reason' => '']);
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

test('the ticket bulk action looks up the delete permission only for a delete', function () {
    $recorder = new RecordingPermissionRegistry(app(PermissionRegistry::class));
    app()->instance(PermissionRegistry::class, $recorder);
    $this->actingAs(User::factory()->staff([])->create())->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);

    $this->postJson(route('admin.support.tickets.bulk'), [])->assertForbidden();
    expect($recorder->checked)->not->toContain('support.tickets.delete')->not->toContain('support.inbound.manage');

    $recorder->checked = [];
    $this->postJson(route('admin.support.tickets.bulk'), ['action' => 'delete', 'ids' => [1]])->assertForbidden();
    expect($recorder->checked)->toContain('support.tickets.delete');
});

test('an empty role sees no admin dashboard widget, to-do or health check', function () {
    $dashboard = app(Dashboard::class);
    $unguarded = [];

    foreach (['widgets', 'todos', 'health'] as $kind) {
        foreach ((fn (): array => $this->{$kind}['admin'] ?? [])->call($dashboard) as $entry) {
            if ($entry['permission'] === null) {
                $unguarded[] = "{$kind}:{$entry['key']}";
            }
        }
    }

    $nobody = User::factory()->staff([])->create();

    expect($unguarded)->toBe([])
        ->and($dashboard->resolveWidgets('admin', $nobody))->toBe([])
        ->and($dashboard->resolveTodos('admin', $nobody))->toBe([])
        ->and($dashboard->resolveHealth('admin', $nobody))->toBe([]);
});
