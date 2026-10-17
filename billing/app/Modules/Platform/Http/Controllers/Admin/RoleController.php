<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Platform\Actions\Roles\SaveRole;
use App\Modules\Platform\Http\Requests\RoleRequest;
use App\Modules\Platform\Services\PermissionGuard;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request, PermissionGuard $guard, PermissionRegistry $registry): Response
    {
        $this->authorize('core.roles.manage');
        $actor = $request->user();
        $groupOf = [];

        foreach ($registry->all() as $group => $permissions) {
            foreach (array_keys($permissions) as $key) {
                $groupOf[$key] = $group;
            }
        }

        return Inertia::render('admin/platform/roles/index', [
            'roles' => Role::query()->withCount('users')->orderBy('name')->orderBy('id')->get()->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions,
                'summary' => $this->summary($role->permissions, $groupOf),
                'users_count' => $role->users_count,
                'is_system' => $role->is_system,
                'editable' => $actor instanceof User && $guard->canManageRole($actor, $role),
            ]),
        ]);
    }

    public function create(Request $request, PermissionRegistry $registry, PermissionGuard $guard): Response
    {
        $this->authorize('core.roles.manage');

        return Inertia::render('admin/platform/roles/form', $this->formProps($request->user(), $registry, $guard));
    }

    public function store(RoleRequest $request, SaveRole $save): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        $role = $save->handle($actor, null, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name created.', ['name' => $role->name])]);

        return to_route('admin.platform.roles.index');
    }

    public function edit(Request $request, Role $role, PermissionRegistry $registry, PermissionGuard $guard): Response
    {
        $this->authorize('core.roles.manage');

        return Inertia::render('admin/platform/roles/form', [
            'role' => $role->only(['id', 'name', 'description', 'permissions', 'is_system']),
            ...$this->formProps($request->user(), $registry, $guard),
        ]);
    }

    public function update(RoleRequest $request, Role $role, SaveRole $save): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        $save->handle($actor, $role, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name saved.', ['name' => $role->name])]);

        return to_route('admin.platform.roles.index');
    }

    public function destroy(Request $request, Role $role, SaveRole $save): RedirectResponse
    {
        $this->authorize('core.roles.manage');
        $actor = $request->user();
        abort_if($actor === null, 401);

        $save->delete($actor, $role);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name deleted.', ['name' => $role->name])]);

        return to_route('admin.platform.roles.index');
    }

    /**
     * Human summary of a role's grants, e.g. ["Clients (all)", "Billing (3)"].
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $groupOf  permission => registry group
     * @return list<string>
     */
    private function summary(array $permissions, array $groupOf): array
    {
        if (in_array('*', $permissions, true)) {
            return ['Full access'];
        }

        $groups = [];

        foreach ($permissions as $permission) {
            if (str_ends_with($permission, '.*')) {
                $prefix = substr($permission, 0, -1);
                $matched = array_values(array_unique(array_filter($groupOf, fn (string $group, string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_BOTH)));
                $groups[$matched === [] ? ucfirst(explode('.', $permission)[0]) : implode(', ', $matched)] = 'all';

                continue;
            }

            $group = $groupOf[$permission] ?? ucfirst(explode('.', $permission)[0]);

            if (($groups[$group] ?? null) !== 'all') {
                $groups[$group] = (int) ($groups[$group] ?? 0) + 1;
            }
        }

        return array_map(fn (string $group, int|string $count): string => "{$group} ({$count})", array_keys($groups), array_values($groups));
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    private function payload(RoleRequest $request): array
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_map('strval', (array) $request->validated('permissions')));

        return [
            'name' => $request->string('name')->toString(),
            'description' => $request->filled('description') ? $request->string('description')->toString() : null,
            'permissions' => $permissions,
        ];
    }

    /**
     * The permission picker: registry groups, with what the actor may grant.
     *
     * @return array{groups: list<array{group: string, permissions: list<array{key: string, label: string, grantable: bool}>, wildcards: list<array{key: string, grantable: bool}>}>, canGrantAll: bool}
     */
    private function formProps(?User $actor, PermissionRegistry $registry, PermissionGuard $guard): array
    {
        $all = $registry->all();
        $moduleGroups = [];

        foreach ($all as $group => $permissions) {
            foreach (array_keys($permissions) as $key) {
                $moduleGroups[explode('.', $key)[0]][$group] = true;
            }
        }

        $groups = [];

        foreach ($all as $group => $permissions) {
            $keys = array_keys($permissions);
            $modules = array_values(array_unique(array_map(fn (string $key): string => explode('.', $key)[0], $keys)));
            $areaCounts = array_count_values(array_map(fn (string $key): string => implode('.', array_slice(explode('.', $key), 0, 2)), $keys));

            $wildcards = count($modules) === 1 && count($moduleGroups[$modules[0]]) === 1
                ? ["{$modules[0]}.*"]
                : array_map(fn (string $area): string => "{$area}.*", array_keys(array_filter($areaCounts, fn (int $count): bool => $count > 1)));

            $groups[] = [
                'group' => $group,
                'permissions' => array_map(fn (string $key, string $label): array => [
                    'key' => $key,
                    'label' => $label,
                    'sensitive' => $registry->isSensitive($key),
                    'grantable' => $actor !== null && $guard->canGrant($actor, $key),
                ], $keys, array_values($permissions)),
                'wildcards' => array_map(fn (string $wildcard): array => [
                    'key' => $wildcard,
                    'grantable' => $actor !== null && $guard->canGrant($actor, $wildcard),
                ], $wildcards),
            ];
        }

        return ['groups' => $groups, 'canGrantAll' => $actor !== null && $guard->canGrant($actor, '*')];
    }
}
