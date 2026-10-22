<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\PasswordConfirmedResponse as PasswordConfirmedResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's answer to a confirmed password. A confirm through the step-up dialog (JSON) is not a return to a
 * remembered file or page, so it drops the URL a download link stored in `url.intended` (#272); the full-page
 * confirm still returns to it.
 */
class PasswordConfirmedResponse implements PasswordConfirmedResponseContract
{
    public function toResponse($request): Response
    {
        $request->session()->forget('step_up_download');

        if ($request->wantsJson()) {
            $request->session()->forget('url.intended');

            return new JsonResponse('', 201);
        }

        return redirect()->intended(Fortify::redirects('password-confirmation'));
    }
}
