<?php

namespace App\Http\Responses;

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\PasswordConfirmedResponse as PasswordConfirmedResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's answer to a confirmed password; Fortify sends it only after the password matched, so it is where step-up
 * is recorded (RequireRecentPasswordConfirmation::markConfirmed()). A confirm through the step-up dialog (JSON) is not a return to a
 * remembered file, so it drops `url.intended` when that is the download that sent the person to the confirm page
 * (#272, #305); any other remembered page is kept. The full-page confirm still returns to it.
 */
class PasswordConfirmedResponse implements PasswordConfirmedResponseContract
{
    public function toResponse($request): Response
    {
        RequireRecentPasswordConfirmation::markConfirmed($request);
        $download = RequireRecentPasswordConfirmation::confirmingDownload($request);
        $request->session()->forget(RequireRecentPasswordConfirmation::DOWNLOAD_KEY);

        if ($request->wantsJson()) {
            if ($download) {
                $request->session()->forget('url.intended');
            }

            return new JsonResponse('', 201);
        }

        return redirect()->intended(Fortify::redirects('password-confirmation'));
    }
}
