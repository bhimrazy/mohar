<?php

namespace App\Modules\PaymentsRazorpay\Support;

use RuntimeException;

/**
 * Razorpay refused or could not complete a request, or the gateway is misconfigured. Billing turns it into a generic
 * customer message; the text goes to the gateway log and to staff, so it never contains secrets.
 */
final class RazorpayException extends RuntimeException {}
