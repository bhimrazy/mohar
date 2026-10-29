<?php

namespace App\Support\Services;

use RuntimeException;

/**
 * Thrown by provisioning drivers for a transient fault where the request provably did not run (connection refused,
 * panel busy). The host retries the command with backoff. A fault after the request was sent is not retryable:
 * return DriverResult::unknown() instead.
 *
 * @api
 */
class RetryableProvisioningException extends RuntimeException {}
