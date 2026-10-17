<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Events\Core\StaffCreated;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\StaffInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptStaffInvitation
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when the invitation expired, was used, or the email is taken
     */
    public function handle(StaffInvitation $invitation, string $name, string $password): User
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages(['token' => __('This invitation has already been used.')]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => __('This invitation has expired. Ask an administrator to resend it.')]);
        }

        if (User::query()->where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages(['token' => __('An account with this email address already exists.')]);
        }

        $user = DB::transaction(function () use ($invitation, $name, $password): User {
            $user = User::query()->create(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
            $user->forceFill([
                'is_staff' => true,
                'role_id' => $invitation->role_id,
                'email_verified_at' => now(),
                'invited_at' => $invitation->created_at,
            ])->save();
            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        $this->activity->log('Staff account created from an invitation', $user, properties: ['email' => $user->email], event: 'staff.created', actor: $user);
        StaffCreated::dispatch($user->id);

        return $user;
    }
}
