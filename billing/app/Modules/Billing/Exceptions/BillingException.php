<?php

namespace App\Modules\Billing\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A billing rule was violated (e.g. refund above the refundable amount). The message is safe to show users,
 * so HTTP requests get it back inline like a validation error instead of a server error.
 */
class BillingException extends DomainException
{
    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage(), 'errors' => ['billing' => [$this->getMessage()]]], 422);
        }

        return back()->withErrors(['billing' => $this->getMessage()]);
    }
}
