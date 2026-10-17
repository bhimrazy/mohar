<?php

namespace App\Modules\Platform\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\Staff\AcceptStaffInvitation;
use App\Modules\Platform\Http\Requests\AcceptInvitationRequest;
use App\Modules\Platform\Models\StaffInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class StaffInvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->find($token);

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $invitation?->email,
            'name' => $invitation?->name,
            'role' => $invitation?->role->name,
            'state' => match (true) {
                $invitation === null => 'invalid',
                $invitation->accepted_at !== null => 'used',
                $invitation->isExpired() => 'expired',
                default => 'valid',
            },
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token, AcceptStaffInvitation $accept): RedirectResponse
    {
        $invitation = $this->find($token);
        abort_if($invitation === null, 404);

        $user = $accept->handle($invitation, $request->string('name')->toString(), $request->string('password')->toString());

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('admin.dashboard');
    }

    private function find(string $token): ?StaffInvitation
    {
        return StaffInvitation::query()->with('role')->where('token_hash', StaffInvitation::hashToken($token))->first();
    }
}
