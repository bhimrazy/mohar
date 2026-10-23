<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Like;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Actions\Staff\CreateStaff;
use App\Modules\Platform\Actions\Staff\InviteStaff;
use App\Modules\Platform\Actions\Staff\ResetStaffTwoFactor;
use App\Modules\Platform\Actions\Staff\UpdateStaff;
use App\Modules\Platform\Http\Requests\StaffRequest;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Services\PermissionGuard;
use App\Modules\Platform\Support\AccountMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request, Settings $settings): Response
    {
        $this->authorize('core.staff.view');

        $staff = User::query()
            ->where('is_staff', true)
            ->with('role:id,name')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $like = Like::contains($request->string('search')->toString());
                $query->where(function ($query) use ($like): void {
                    Like::where($query, 'name', $like);
                    Like::where($query, 'email', $like, 'or');
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status')->toString() === 'active'))
            ->when($request->string('two_factor')->toString() === 'off', fn ($query) => $query->whereNull('two_factor_confirmed_at'))
            ->when($request->string('two_factor')->toString() === 'on', fn ($query) => $query->whereNotNull('two_factor_confirmed_at'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/platform/staff/index', [
            'staff' => $staff->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'status' => $user->is_active ? 'active' : 'inactive',
                'two_factor' => $user->hasEnabledTwoFactorAuthentication(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'is_self' => $user->is($request->user()),
            ]),
            'invitations' => StaffInvitation::query()->pending()->with('role:id,name')->orderByDesc('id')->get()
                ->map(fn (StaffInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'name' => $invitation->name,
                    'role' => $invitation->role->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'expired' => $invitation->isExpired(),
                ]),
            'filters' => $request->only(['search', 'status', 'two_factor']),
            'twoFactorRequired' => (bool) $settings->get('security.staff_2fa_required', true),
            'can' => ['manage' => $request->user()?->hasPermission('core.staff.manage') ?? false],
        ]);
    }

    public function create(Request $request, PermissionGuard $guard): Response
    {
        $this->authorize('core.staff.manage');

        return Inertia::render('admin/platform/staff/form', ['roles' => $this->assignableRoles($request->user(), $guard)]);
    }

    public function store(StaffRequest $request, InviteStaff $invite, CreateStaff $create): RedirectResponse
    {
        $role = Role::query()->findOrFail($request->integer('role_id'));
        $actor = $request->user();
        abort_if($actor === null, 401);

        if ($request->input('mode') === 'invite') {
            $invite->handle($actor, $request->string('email')->toString(), $request->input('name'), $role);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $request->input('email')])]);
        } else {
            $create->handle($actor, $request->string('name')->toString(), $request->string('email')->toString(), $role, $request->string('password')->toString());
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Staff member :email created.', ['email' => $request->input('email')])]);
        }

        return to_route('admin.platform.staff.index');
    }

    public function edit(Request $request, User $staff, PermissionGuard $guard): Response
    {
        $this->authorize('core.staff.manage');
        abort_unless($staff->is_staff, 404);

        return Inertia::render('admin/platform/staff/form', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'role_id' => $staff->role_id,
                'is_active' => $staff->is_active,
                'two_factor' => $staff->hasEnabledTwoFactorAuthentication(),
                'last_login_at' => $staff->last_login_at?->toIso8601String(),
                'last_login_ip' => $staff->last_login_ip,
                'created_at' => $staff->created_at?->toIso8601String(),
                'is_self' => $staff->is($request->user()),
            ],
            'roles' => $this->assignableRoles($request->user(), $guard, $staff->role),
        ]);
    }

    public function update(StaffRequest $request, User $staff, UpdateStaff $update): RedirectResponse
    {
        abort_unless($staff->is_staff, 404);
        $actor = $request->user();
        abort_if($actor === null, 401);

        $mailed = $update->handle($actor, $staff, [
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role_id' => $request->integer('role_id'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $name = $staff->name;
        Inertia::flash('toast', match (true) {
            $mailed->verificationSent && $mailed->oldAddressTold => ['type' => 'success', 'message' => __('Staff member :name saved.', ['name' => $name])],
            $mailed->oldAddressTold => ['type' => 'warning', 'message' => __('Staff member :name saved, but the verification email could not be sent. Once mail works again, they can resend it from their profile page.', ['name' => $name])],
            $mailed->verificationSent => ['type' => 'warning', 'message' => __('Staff member :name saved, but the notice to their previous email address could not be sent. The activity log has the details.', ['name' => $name])],
            default => ['type' => 'warning', 'message' => __('Staff member :name saved, but neither the verification email nor the notice to their previous email address could be sent. Once mail works again, they can resend the verification email from their profile page.', ['name' => $name])],
        });

        return to_route('admin.platform.staff.index');
    }

    public function resetTwoFactor(Request $request, User $staff, ResetStaffTwoFactor $reset, AccountMail $mail): RedirectResponse
    {
        $this->authorize('core.staff.manage');
        abort_unless($staff->is_staff, 404);
        $actor = $request->user();
        abort_if($actor === null, 401);

        $reset->handle($actor, $staff);
        Inertia::flash('toast', $mail->toast(['type' => 'success', 'message' => __('Two-factor authentication reset for :name.', ['name' => $staff->name])]));

        return back();
    }

    /**
     * Roles the actor may assign (all their permissions must be grantable by the actor).
     *
     * @return list<array{id: int, name: string, description: ?string, assignable: bool}>
     */
    private function assignableRoles(?User $actor, PermissionGuard $guard, ?Role $current = null): array
    {
        return array_values(Role::query()->orderBy('name')->orderBy('id')->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'assignable' => $actor !== null && $guard->canManageRole($actor, $role),
            ])
            ->filter(fn (array $role): bool => $role['assignable'] || $role['id'] === $current?->id)
            ->all());
    }
}
