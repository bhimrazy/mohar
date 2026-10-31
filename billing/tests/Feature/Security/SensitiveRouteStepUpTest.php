<?php

use App\Models\User;
use App\Modules\Services\Models\ServerGroup;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\Security\RecordingPermissionRegistry;
use Tests\Support\Security\RouteScan;

/**
 * Security standards §2: every route that changes something behind a sensitive permission needs a recent password
 * confirmation (security review 2026-10-19, L1). Behavioural, so constants, inherited helpers, closures, FormRequests
 * and per-section settings all count: each admin non-GET route is called once as a full administrator with a recent
 * password while every permission check is recorded, then again as a staff member holding only the permissions it
 * checked, without a recent password. If it checked a sensitive one, that second call must be refused for step-up.
 */

/** Non-GET admin routes that check a sensitive permission but may run without step-up, and why. */
const STEP_UP_EXEMPT = [
    'admin.billing.gateways.test' => 'Test connection sends no stored data anywhere it was not saved for, and changes nothing.',
    'admin.billing.payment-attempts.check' => 'Asks the gateway for the status of an attempt and books only a payment the gateway confirms, also for a closed attempt (a late payment, #130); it moves no money staff did not already receive.',
    'admin.kyc.lock' => 'Hide documents only ends this staff member\'s own document unlock early and clears the browser history (#240); it takes access away and changes no data.',
];

/** Routes the scan cannot reach even with empty bound models, and why (none today; the scan fails on a new one). */
const STEP_UP_UNRESOLVED = [];

/**
 * Non-GET admin routes that check no permission at all, and why. Any other route whose administrator call checks
 * none is reported: it answered before authorising (a validation error, a throttle), so the scan cannot tell (#107).
 */
const STEP_UP_NO_PERMISSION = [
    'admin.appearance.preference' => 'A staff member\'s own theme and density; it changes only their own login.',
];

/**
 * @param  array<string, mixed>  $body
 */
function stepUpRequest(RoutingRoute $route, array $parameters, User $user, bool $confirmed, array $body = []): TestResponse
{
    $test = test();
    // Shared throttles (provider calls, mail) would answer 429 before the route authorises (#107).
    Cache::store(config('cache.limiter'))->flush();
    $test->flushSession();
    $test->actingAs($user);

    if ($confirmed) {
        $test->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);
    }

    $method = collect($route->methods())->first(fn (string $method): bool => ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true));

    return $test->call($method, route($route->getName(), $parameters), $body, [], [], ['HTTP_REFERER' => url('/admin')]);
}

function refusedForStepUp(TestResponse $response): bool
{
    if ($response->status() === 423) {
        return true;
    }

    try {
        $response->assertSessionHasErrors('password_confirmation');

        return true;
    } catch (AssertionFailedError) {
        return false;
    }
}

/**
 * @return array{sensitive: array<string, list<string>>, missing: list<string>, unresolved: list<string>, unchecked: list<string>, without_permission: list<string>}
 */
function scanSensitiveRoutes(): array
{
    // A feature-gated route answers 404 first; switch every flag on so the scan sees it.
    foreach (array_keys(Arr::dot(config('features'))) as $flag) {
        config(['features.'.$flag => true]);
    }

    Queue::fake();
    Mail::fake();
    Notification::fake();
    Http::fake();

    $recorder = new RecordingPermissionRegistry(app(PermissionRegistry::class));
    app()->instance(PermissionRegistry::class, $recorder);
    $admin = User::factory()->staff(['*'])->create(['email' => 'step-up-scan@example.test']);
    // The loop resets unique fakes, so nothing kept across calls may hold a faked unique value.
    $admin->role->update(['name' => 'Step-up scan administrator']);
    $result = ['sensitive' => [], 'missing' => [], 'unresolved' => [], 'unchecked' => [], 'without_permission' => []];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'admin.') || array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [] || array_key_exists($name, STEP_UP_UNRESOLVED)) {
            continue;
        }

        // The settings route checks a different permission per section, a bulk route per action: try each.
        $variants = [];

        if (in_array('section', $route->parameterNames(), true)) {
            foreach (array_keys(app(SettingsRegistry::class)->sections()) as $section) {
                $variants[] = [$section, $section, []];
            }
        } else {
            foreach (RouteScan::bodies()[$name] ?? ['' => []] as $variant => $body) {
                $variants[] = [(string) $variant, null, $body];
            }
        }

        foreach ($variants as [$variant, $section, $body]) {
            $label = $variant === '' ? $name : "{$name}[{$variant}]";
            // Every call is rolled back, so factories' unique values may repeat.
            fake()->unique(true);
            DB::beginTransaction();

            try {
                // First with fresh models; if that never reaches a permission check, with empty bound models.
                foreach ([false, true] as $blank) {
                    $parameters = RouteScan::parameters($route, $section, $blank);

                    if ($parameters === null) {
                        break;
                    }

                    // The administrator's call runs in its own savepoint, so a delete leaves the model for the next call.
                    $recorder->checked = [];
                    DB::beginTransaction();

                    try {
                        $status = stepUpRequest($route, $parameters, $admin, confirmed: true, body: $body)->status();
                    } finally {
                        DB::rollBack();
                    }

                    $checked = array_values(array_unique($recorder->checked));

                    if ($checked !== [] || ! (in_array($status, [404, 405], true) || $status >= 500)) {
                        break;
                    }
                }

                if ($parameters === null || ($checked === [] && (in_array($status, [404, 405], true) || $status >= 500))) {
                    $result['unresolved'][] = $label.($parameters === null ? ' (parameters)' : " ({$status})");

                    continue;
                }

                if ($checked === []) {
                    if (array_key_exists($name, STEP_UP_NO_PERMISSION)) {
                        $result['without_permission'][] = $name;
                    } else {
                        $result['unchecked'][] = "{$label} ({$status})";
                    }

                    continue;
                }

                $sensitive = array_values(array_filter($checked, fn (string $permission): bool => $recorder->isSensitive($permission)));

                if ($sensitive === []) {
                    continue;
                }

                $result['sensitive'][$label] = $sensitive;
                $holder = User::factory()->staff($checked)->create();

                if (! array_key_exists($name, STEP_UP_EXEMPT) && ! refusedForStepUp(stepUpRequest($route, $parameters, $holder, confirmed: false, body: $body))) {
                    $result['missing'][] = $label.' ('.implode(', ', $sensitive).')';
                }
            } finally {
                DB::rollBack();
            }
        }
    }

    return $result;
}

test('every admin route that checks a sensitive permission refuses to run without a recent password', function () {
    $scan = scanSensitiveRoutes();

    expect($scan['missing'])->toBe([])
        ->and($scan['unresolved'])->toBe([])
        ->and($scan['unchecked'])->toBe([])
        // Vacuity guards: the scan sees routes it must see, and every exemption is still a sensitive route.
        ->and($scan['sensitive'])->toHaveKeys([
            'admin.services.servers.update',
            'admin.services.server-groups.store',
            'admin.billing.invoices.capture',
            'admin.platform.roles.store',
            'admin.domains.registrars.update',
            'admin.platform.settings.update[support_inbound]',
            // Behind a shared throttle, or validating before authorising (#107).
            'admin.services.servers.test',
            'admin.platform.currencies.rates.refresh',
            'admin.billing.invoices.bulk[mark_paid]',
        ])
        // A route that is gone, or now checks a permission, leaves its entry stale (#459).
        ->and(array_values(array_diff(array_keys(STEP_UP_NO_PERMISSION), $scan['without_permission'])))->toBe([])
        ->and(array_diff(array_keys(STEP_UP_EXEMPT), array_map(fn (string $label): string => explode('[', $label)[0], array_keys($scan['sensitive']))))->toBe([]);
});

test('probe L1: server-group changes need a recent password confirmation', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);

    $this->post(route('admin.services.server-groups.store'), ['name' => 'Shared EU', 'fill_type' => 'least_full', 'server_ids' => []])
        ->assertSessionHasErrors('password_confirmation');

    expect(ServerGroup::query()->count())->toBe(0);

    confirmPassword();
    $this->post(route('admin.services.server-groups.store'), ['name' => 'Shared EU', 'fill_type' => 'least_full', 'server_ids' => []])
        ->assertSessionHasNoErrors();

    expect(ServerGroup::query()->count())->toBe(1);
});

/**
 * The routes known to change users.email (#388), with their request body less the email. Found by searching app/ for
 * writes to a login's email; a new route that changes it is added here. Account creation (registration, invitations, imports) sets an email and changes none.
 *
 * @return array<string, Closure(): array{0: User, 1: User, 2: string, 3: array<string, mixed>}> actor, target, url, body
 */
function emailChangingRoutes(): array
{
    return [
        'profile.update' => function (): array {
            $user = User::factory()->create();

            return [$user, $user, route('profile.update'), ['name' => $user->name]];
        },
        'admin.platform.staff.update' => function (): array {
            $admin = User::factory()->staff(['*'])->create();
            $staff = User::factory()->staff(['core.clients.view'])->create();

            return [$admin, $staff, route('admin.platform.staff.update', $staff), ['name' => $staff->name, 'role_id' => $staff->role_id, 'is_active' => true]];
        },
    ];
}

test('every route that changes a login\'s email refuses without a recent password, over Inertia and JSON', function () {
    Notification::fake();
    Mail::fake();

    foreach (emailChangingRoutes() as $name => $make) {
        [$actor, $target, $url, $body] = $make();
        // A new address per route, so one route's change never blocks the next as taken.
        $body['email'] = Str::slug($name).'@example.com.np';
        $method = collect(Route::getRoutes()->getByName($name)->methods())->first(fn (string $method): bool => ! in_array($method, ['GET', 'HEAD'], true));
        $original = $target->email;
        $this->flushSession();
        $this->actingAs($actor);

        expect(refusedForStepUp($this->call($method, $url, $body, [], [], ['HTTP_REFERER' => url('/')])))->toBeTrue("{$name} (Inertia)")
            ->and($this->json($method, $url, $body)->status())->toBe(423, "{$name} (JSON)")
            ->and($target->fresh()->email)->toBe($original, $name);

        // Vacuity guard: with a recent password the same request does change the email.
        confirmPassword();
        $this->call($method, $url, $body);
        expect($target->fresh()->email)->toBe($body['email'], "{$name} (confirmed)");
    }
});
