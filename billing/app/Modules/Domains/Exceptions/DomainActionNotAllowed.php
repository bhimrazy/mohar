<?php

namespace App\Modules\Domains\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An action was attempted in a status that does not allow it (e.g. a status transition the
 * state machine rejects, or client management of an expired domain).
 */
class DomainActionNotAllowed extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'code' => 'action_not_allowed'], 422);
        }

        return back()->withErrors(['domain' => $this->getMessage()]);
    }
}
