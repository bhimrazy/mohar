<?php

namespace App\Modules\PaymentsStripe\Support;

use RuntimeException;

/**
 * Stripe refused or could not complete a request, or the gateway is misconfigured. Billing turns it into a generic
 * customer message; the text goes to the gateway log and to staff, so it never contains secrets.
 */
final class StripeException extends RuntimeException {}
