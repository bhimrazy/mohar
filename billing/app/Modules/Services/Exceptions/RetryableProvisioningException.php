<?php

namespace App\Modules\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by drivers for transient faults (timeouts, panel busy). The command is retried with backoff.
 */
class RetryableProvisioningException extends RuntimeException {}
