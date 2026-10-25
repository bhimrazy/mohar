<?php

namespace App\Modules\PaymentsCybersource\Support;

use RuntimeException;

/**
 * Cybersource refused or could not complete a request, or the gateway is misconfigured. Billing shows the customer a
 * generic message; the text goes to the gateway log, so it never contains secrets.
 */
final class CybersourceException extends RuntimeException {}
