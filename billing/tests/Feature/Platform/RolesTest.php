<?php

use App\Models\User;
use App\Modules\Core\Models\Role;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('a support.* role is denied billing routes and allowed support routes', function () {
    Route::middleware(['web', 'auth', 'staff'])->group(function () {
        Route::get('/admin/test-invoices', fn () => 'ok')->middleware('can:billing.invoices.view');
        Route::get('/admin/test-tickets', fn () => 'ok')->middleware('can:support.tickets.view');
    });
    actingAsStaff(['support.*']);

    $this->get('/admin/test-invoices')->assertForbidden();
    $this->get('/admin/test-tickets')->assertOk();
});

test('an area wildcard grants that area only', function () {
    $staff = User::factory()->staff(['billing.invoices.*'])->create();

    expect($staff->can('billing.invoices.cancel'))->toBeTrue()
        ->and($staff->can('billing.refunds.create'))->toBeFalse()
        ->and($staff->can('billing.invoicesx.view'))->toBeFalse();
});

test('role management requires core.roles.manage', function () {
    actingAsStaff(['core.staff.*']);

    $this->get(route('admin.platform.roles.index'))->assertForbidden();
    $this->post(route('admin.platform.roles.store'), ['name' => 'X', 'permissions' => ['core.clients.view']])->assertForbidden();
});

test('the permission picker lists registered permissions and what the actor may grant', function () {
    actingAsStaff(['core.roles.manage', 'core.clients.*']);

    $this->get(route('admin.platform.roles.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/platform/roles/form')
            ->where('canGrantAll', false)
            ->where('groups', function ($groups) {
                $clients = collect($groups)->firstWhere('group', 'Clients');
                $permissions = collect($clients['permissions'])->keyBy('key');

                return $permissions['core.clients.view']['grantable'] === true
                    && $permissions['core.contacts.manage']['grantable'] === false
                    && collect($clients['wildcards'])->firstWhere('key', 'core.clients.*')['grantable'] === true;
            }));
});

test('a role can be created with registered permissions and wildcards', function () {
    actingAsStaff();

    $this->post(route('admin.platform.roles.store'), [
        'name' => 'Clients desk',
        'description' => 'Front desk',
        'permissions' => ['core.clients.*', 'core.activity_log.view'],
    ])->assertRedirect(route('admin.platform.roles.index'));

    $role = Role::query()->where('name', 'Clients desk')->sole();
    expect($role->permissions)->toBe(['core.activity_log.view', 'core.clients.*']);
    $this->assertDatabaseHas('activity_log', ['event' => 'role.created', 'subject_id' => $role->id]);
});

test('unknown permissions and malformed wildcards are rejected', function () {
    actingAsStaff();

    $this->post(route('admin.platform.roles.store'), ['name' => 'Bad', 'permissions' => ['core.clients.view', 'made.up.permission']])
        ->assertSessionHasErrors('permissions.1');
    $this->post(route('admin.platform.roles.store'), ['name' => 'Bad', 'permissions' => ['nothing.*']])
        ->assertSessionHasErrors('permissions.0');
    $this->post(route('admin.platform.roles.store'), ['name' => 'Bad', 'permissions' => []])
        ->assertSessionHasErrors('permissions');
});

test('staff cannot grant permissions they do not hold', function () {
    actingAsStaff(['core.roles.manage', 'core.clients.view']);

    $this->post(route('admin.platform.roles.store'), ['name' => 'Escalate', 'permissions' => ['*']])->assertSessionHasErrors('permissions');
    $this->post(route('admin.platform.roles.store'), ['name' => 'Escalate', 'permissions' => ['core.*']])->assertSessionHasErrors('permissions');
    $this->post(route('admin.platform.roles.store'), ['name' => 'Escalate', 'permissions' => ['core.roles.manage', 'core.staff.manage']])->assertSessionHasErrors('permissions');

    expect(Role::query()->where('name', 'Escalate')->exists())->toBeFalse();
});

test('staff cannot edit a role that has permissions they do not hold', function () {
    $actor = actingAsStaff(['core.roles.manage', 'core.clients.view']);
    $admin = Role::factory()->withPermissions(['*'])->create();

    $this->put(route('admin.platform.roles.update', $admin), ['name' => $admin->name, 'permissions' => ['core.clients.view']])
        ->assertSessionHasErrors('permissions');

    $ownRole = $actor->role;
    $this->put(route('admin.platform.roles.update', $ownRole), ['name' => $ownRole->name, 'permissions' => ['core.roles.manage', 'core.clients.view', 'core.staff.manage']])
        ->assertSessionHasErrors('permissions');

    expect($admin->fresh()->permissions)->toBe(['*']);
});

test('removing "*" from the role of the last full administrators is blocked', function () {
    $admin = actingAsStaff();

    $this->put(route('admin.platform.roles.update', $admin->role), ['name' => $admin->role->name, 'permissions' => ['core.roles.manage']])
        ->assertSessionHasErrors('permissions');

    User::factory()->staff(['*'])->create();
    $this->put(route('admin.platform.roles.update', $admin->role), ['name' => $admin->role->name, 'permissions' => ['core.roles.manage']])
        ->assertSessionHasNoErrors();
});

test('system roles and roles in use cannot be deleted', function () {
    actingAsStaff();
    $system = Role::factory()->withPermissions(['support.*'])->create(['is_system' => true]);
    $inUse = Role::factory()->withPermissions(['support.*'])->create();
    User::factory()->create()->forceFill(['is_staff' => true, 'role_id' => $inUse->id])->save();
    $unused = Role::factory()->withPermissions(['support.*'])->create();

    $this->delete(route('admin.platform.roles.destroy', $system))->assertSessionHasErrors('role');
    $this->delete(route('admin.platform.roles.destroy', $inUse))->assertSessionHasErrors('role');
    $this->delete(route('admin.platform.roles.destroy', $unused))->assertRedirect(route('admin.platform.roles.index'));

    expect(Role::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(Role::query()->whereKey($system->id)->exists())->toBeTrue();
});
