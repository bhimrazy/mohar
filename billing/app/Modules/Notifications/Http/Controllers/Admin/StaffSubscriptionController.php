<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Notifications\Enums\StaffCategory;
use App\Modules\Notifications\Models\RoleEmailSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which staff roles receive which alert categories (spec §2 "Staff subscriptions", §9).
 */
class StaffSubscriptionController extends Controller
{
    public function edit(): Response
    {
        $this->authorize('notifications.subscriptions.manage');
        $subscriptions = RoleEmailSubscription::query()->get()->keyBy('role_id');

        return Inertia::render('admin/notifications/subscriptions', [
            'roles' => Role::query()->withCount('users')->orderBy('name')->orderBy('id')->get()->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => (int) $role->getAttribute('users_count'),
                'is_administrator' => in_array('*', $role->permissions, true),
                'customised' => $subscriptions->has($role->id),
                'categories' => $subscriptions->get($role->id)->categories ?? (in_array('*', $role->permissions, true) ? StaffCategory::values() : []),
            ])->values(),
            'categories' => array_map(fn (StaffCategory $category): array => ['value' => $category->value, 'label' => $category->label(), 'description' => $category->description()], StaffCategory::cases()),
        ]);
    }

    public function update(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('notifications.subscriptions.manage');
        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*.id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'roles.*.categories' => ['present', 'array'],
            'roles.*.categories.*' => ['string', Rule::in(StaffCategory::values())],
        ]);

        foreach ($validated['roles'] as $role) {
            RoleEmailSubscription::query()->updateOrCreate(['role_id' => $role['id']], ['categories' => array_values(array_unique($role['categories']))]);
        }

        $activity->log('Staff email alert subscriptions updated', properties: ['roles' => count($validated['roles'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Staff email alerts saved.')]);

        return back();
    }
}
