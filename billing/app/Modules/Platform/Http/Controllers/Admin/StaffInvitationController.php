<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Actions\Staff\InviteStaff;
use App\Modules\Platform\Models\StaffInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StaffInvitationController extends Controller
{
    public function resend(Request $request, StaffInvitation $invitation, InviteStaff $invite): RedirectResponse
    {
        $this->authorize('core.staff.manage');
        abort_if($invitation->accepted_at !== null, 404);
        $actor = $request->user();
        abort_if($actor === null, 401);

        $invite->resend($actor, $invitation);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent to :email.', ['email' => $invitation->email])]);

        return back();
    }

    public function destroy(StaffInvitation $invitation, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('core.staff.manage');
        abort_if($invitation->accepted_at !== null, 404);

        $invitation->delete();
        $activity->log("Staff invitation for {$invitation->email} cancelled", event: 'staff.invitation_cancelled');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation for :email cancelled.', ['email' => $invitation->email])]);

        return back();
    }
}
