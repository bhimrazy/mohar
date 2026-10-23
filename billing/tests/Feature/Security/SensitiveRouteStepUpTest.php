<?php

use App\Models\User;
use App\Modules\Services\Models\ServerGroup;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

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
 * Records every permission a request checks: Role::allows() asks the registry whether each one is sensitive.
 */
final class RecordingPermissionRegistry extends PermissionRegistry
{
    /** @var list<string> */
    public array $checked = [];

    public function __construct(private PermissionRegistry $inner) {}

    public function register(string $group, array $permissions, array $sensitive = []): void
    {
        $this->inner->register($group, $permissions, $sensitive);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function exists(string $permission): bool
    {
        return $this->inner->exists($permission);
    }

    public function isSensitive(string $permission): bool
    {
        $this->checked[] = $permission;

        return $this->inner->isSensitive($permission);
    }

    public function sensitive(): array
    {
        return $this->inner->sensitive();
    }
}

/** Route key that binds to an empty model, for models without a factory or in a state a fresh one cannot reach. */
const STEP_UP_BLANK = '987654321';

/**
 * @return array<string, class-string<Model>> route parameter => bound model class
 */
function stepUpModelParameters(RoutingRoute $route): array
{
    $bound = [];

    foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
            $bound[$parameter->getName()] = $type->getName();
        }
    }

    $models = [];

    foreach ($route->parameterNames() as $name) {
        $class = $bound[$name] ?? $bound[Str::camel($name)] ?? null;

        if ($class !== null) {
            $models[$name] = $class;
        }
    }

    return $models;
}

/**
 * Binds STEP_UP_BLANK to an empty model of the route's type; any other key resolves as implicit binding would.
 */
function stepUpBinder(string $name): void
{
    static $bound = [];
    $key = spl_object_id(app('router')).'|'.$name;

    if (isset($bound[$key])) {
        return;
    }

    $bound[$key] = true;
    Route::bind($name, function (string $value, RoutingRoute $route) use ($name): Model {
        $class = stepUpModelParameters($route)[$name];

        return $value === STEP_UP_BLANK ? new $class : ((new $class)->resolveRouteBinding($value) ?? throw (new ModelNotFoundException)->setModel($class));
    });
}

/**
 * A value for each route parameter: a fresh factory model (children get their parent's id), STEP_UP_BLANK when
 * $blank or the model has no factory, or a known key for string parameters. Null when one cannot be resolved.
 *
 * @return array<string, mixed>|null
 */
function stepUpParameters(RoutingRoute $route, ?string $section, bool $blank): ?array
{
    $models = stepUpModelParameters($route);
    $values = [];
    $parents = [];

    foreach ($route->parameterNames() as $name) {
        $class = $models[$name] ?? null;

        if ($class !== null) {
            if ($blank || ! method_exists($class, 'factory')) {
                stepUpBinder($name);
                $values[$name] = STEP_UP_BLANK;

                continue;
            }

            $model = new $class;
            $attributes = [];

            foreach ($parents as $parentName => $parent) {
                if (Schema::hasColumn($model->getTable(), "{$parentName}_id")) {
                    $attributes["{$parentName}_id"] = $parent->getKey();
                }
            }

            $values[$name] = $parents[Str::snake($name)] = $class::factory()->create($attributes);

            continue;
        }

        $values[$name] = $name === 'section' ? $section : stepUpStringParameter($route, $name);

        if ($values[$name] === null) {
            return null;
        }
    }

    return $values;
}

function stepUpStringParameter(RoutingRoute $route, string $name): ?string
{
    $pattern = $route->wheres[$name] ?? null;

    // whereIn() constraints list the allowed values; take the first.
    if (is_string($pattern) && preg_match('/^[a-z0-9_|-]+$/', $pattern) === 1) {
        return explode('|', $pattern)[0];
    }

    return [
        'registrar' => 'log',
        'gateway' => 'sandbox',
        'kind' => 'mark',
        'template' => 'client.welcome',
        'converter' => 'products',
        'notice' => 'company_time_settings',
    ][$name] ?? null;
}

function stepUpRequest(RoutingRoute $route, array $parameters, User $user, bool $confirmed): TestResponse
{
    $test = test();
    $test->flushSession();
    $test->actingAs($user);

    if ($confirmed) {
        $test->withSession(['auth.password_confirmed_at' => time()]);
    }

    $method = collect($route->methods())->first(fn (string $method): bool => ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true));

    return $test->call($method, route($route->getName(), $parameters), [], [], [], ['HTTP_REFERER' => url('/admin')]);
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
 * @return array{sensitive: array<string, list<string>>, missing: list<string>, unresolved: list<string>}
 */
function scanSensitiveRoutes(): array
{
    Queue::fake();
    Mail::fake();
    Notification::fake();
    Http::fake();

    $recorder = new RecordingPermissionRegistry(app(PermissionRegistry::class));
    app()->instance(PermissionRegistry::class, $recorder);
    $admin = User::factory()->staff(['*'])->create(['email' => 'step-up-scan@example.test']);
    // The loop resets unique fakes, so nothing kept across calls may hold a faked unique value.
    $admin->role->update(['name' => 'Step-up scan administrator']);
    $result = ['sensitive' => [], 'missing' => [], 'unresolved' => []];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'admin.') || array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [] || array_key_exists($name, STEP_UP_UNRESOLVED)) {
            continue;
        }

        // The settings route checks a different permission per section: try every section.
        $sections = in_array('section', $route->parameterNames(), true) ? array_keys(app(SettingsRegistry::class)->sections()) : [null];

        foreach ($sections as $section) {
            $label = $section === null ? $name : "{$name}[{$section}]";
            // Every call is rolled back, so factories' unique values may repeat.
            fake()->unique(true);
            DB::beginTransaction();

            try {
                // First with fresh models; if that never reaches a permission check, with empty bound models.
                foreach ([false, true] as $blank) {
                    $parameters = stepUpParameters($route, $section, $blank);

                    if ($parameters === null) {
                        break;
                    }

                    // The administrator's call runs in its own savepoint, so a delete leaves the model for the next call.
                    $recorder->checked = [];
                    DB::beginTransaction();

                    try {
                        $status = stepUpRequest($route, $parameters, $admin, confirmed: true)->status();
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

                $sensitive = array_values(array_filter($checked, fn (string $permission): bool => $recorder->isSensitive($permission)));

                if ($sensitive === []) {
                    continue;
                }

                $result['sensitive'][$label] = $sensitive;
                $holder = User::factory()->staff($checked)->create();

                if (! array_key_exists($name, STEP_UP_EXEMPT) && ! refusedForStepUp(stepUpRequest($route, $parameters, $holder, confirmed: false))) {
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
        // Vacuity guards: the scan sees routes it must see, and every exemption is still a sensitive route.
        ->and($scan['sensitive'])->toHaveKeys([
            'admin.services.servers.update',
            'admin.services.server-groups.store',
            'admin.billing.invoices.capture',
            'admin.platform.roles.store',
            'admin.domains.registrars.update',
            'admin.platform.settings.update[support_inbound]',
        ])
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
