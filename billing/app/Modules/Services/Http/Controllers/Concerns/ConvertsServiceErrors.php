<?php

namespace App\Modules\Services\Http\Controllers\Concerns;

use App\Modules\Services\Exceptions\InvalidStatusTransition;
use App\Modules\Services\Exceptions\ServiceActionException;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Turns business-rule exceptions into a validation error the page can show inline.
 */
trait ConvertsServiceErrors
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     *
     * @throws ValidationException
     */
    protected function attempt(Closure $action, string $field = 'action'): mixed
    {
        try {
            return $action();
        } catch (ServiceActionException|InvalidStatusTransition $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
