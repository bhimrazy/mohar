<?php

namespace App\Modules\Support\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The ticket changed after the staff member loaded it (spec §4.2 collision detection).
 * JSON clients get 409 with the change summary; Inertia forms get a "version" validation
 * error (409 is reserved by the Inertia protocol for asset-version reloads).
 */
class TicketVersionConflict extends RuntimeException
{
    /**
     * @param  list<string>  $changes
     */
    public function __construct(public readonly int $currentVersion, public readonly array $changes)
    {
        parent::__construct('The ticket was changed by someone else since you opened it.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => $this->getMessage(),
                'version' => $this->currentVersion,
                'changes' => $this->changes,
            ], 409);
        }

        throw ValidationException::withMessages([
            'version' => $this->getMessage().' '.implode(' ', $this->changes),
        ]);
    }
}
