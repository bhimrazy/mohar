<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Models\User;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new client login accepts its invitation: the password is set once, before the link expires.
 */
class AcceptClientInvitation
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when the invitation expired or was used
     */
    public function handle(ClientInvitation $invitation, #[\SensitiveParameter] string $password): User
    {
        $user = DB::transaction(function () use ($invitation, $password): User {
            // Single use: the row is locked and marked accepted in the same transaction as the password.
            $locked = ClientInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->state() !== 'valid') {
                throw ValidationException::withMessages(['token' => $locked->state() === 'used'
                    ? __('This invitation has already been used. Log in with your email and password.')
                    : __('This invitation has expired. Ask the account owner or our team to send a new one.')]);
            }

            $locked->update(['accepted_at' => now()]);
            $user = $invitation->user;
            $user->forceFill(['password' => $password, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            return $user;
        });

        $this->activity->log('Client login accepted its invitation', $user, $invitation->client_id, ['email' => $user->email], 'client.invitation_accepted', $user);

        return $user;
    }
}
