<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One answer for every failed POST /reset-password, whether the address has no account, the token is wrong or
 * expired, or the broker throttled it, so the form never reveals whether an account exists (security review
 * 2026-10-19, M2). Fortify's default names the broker status ("We can't find a user with that email address").
 */
class PasswordResetFailedResponse implements FailedPasswordResetResponse
{
    public function __construct(private string $status = '') {}

    public function toResponse($request): Response
    {
        $message = __('This password reset link is invalid or has expired. Request a new one.');

        if ($request->wantsJson()) {
            throw ValidationException::withMessages(['email' => [$message]]);
        }

        return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }
}
