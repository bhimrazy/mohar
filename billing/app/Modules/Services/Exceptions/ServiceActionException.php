<?php

namespace App\Modules\Services\Exceptions;

use DomainException;

/**
 * A business rule rejected a staff or client action (shown to the user as a validation error).
 */
final class ServiceActionException extends DomainException {}
