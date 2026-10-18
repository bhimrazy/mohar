<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same answer for every "forgot password" request — sent, unknown email or per-user throttled — so
 * the endpoint never reveals whether an account exists (security review PR #28, M2).
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request): Response
    {
        $message = __('If an account exists for that email address, we have sent it a password reset link.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
