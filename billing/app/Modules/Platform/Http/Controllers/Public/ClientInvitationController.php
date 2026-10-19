<?php

namespace App\Modules\Platform\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Platform\Actions\Clients\AcceptClientInvitation;
use App\Modules\Platform\Http\Requests\AcceptClientInvitationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The page a new client login's invitation opens: choose a password once, before the link expires.
 */
class ClientInvitationController extends Controller
{
    public function show(Request $request, string $token): SymfonyResponse
    {
        $invitation = ClientInvitation::findByToken($token);

        // The token is in the URL: never leak it through the Referer header.
        $response = Inertia::render('auth/accept-account-invitation', [
            'token' => $token,
            'email' => $invitation?->user->email,
            'account' => $invitation?->client->display_name,
            'state' => $invitation?->state() ?? 'invalid',
        ])->toResponse($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * @throws ValidationException when the invitation expired or was used
     */
    public function accept(AcceptClientInvitationRequest $request, string $token, AcceptClientInvitation $accept): RedirectResponse
    {
        $invitation = ClientInvitation::findByToken($token);
        abort_if($invitation === null, 404);

        $user = $accept->handle($invitation, $request->string('password')->toString());

        // Never a session past the second factor: such a login goes through the normal login and challenge.
        if ($user->two_factor_confirmed_at !== null || $user->two_factor_secret !== null) {
            return to_route('login')->with('status', __('Your password is set. Log in to continue.'));
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('client.dashboard');
    }
}
