<?php

namespace App\Modules\Domains\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * A registrar call failed. Retryable errors are transient (connection refused, rate limited) and the
 * request is known not to have been executed; anything else must not be blindly re-submitted.
 */
class RegistrarException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return 'registrar_error';
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode()], 422);
        }

        return back()->withErrors(['registrar' => $this->getMessage()]);
    }
}
