<?php

namespace App\Support\Billing;

use AssertionError;

/**
 * Thrown by GatewayConformance when a gateway driver breaks what Billing relies on. It is an AssertionError, so
 * PHPUnit and Pest report it as a failed test without the shipped kit depending on PHPUnit.
 *
 * @api
 */
final class GatewayConformanceFailure extends AssertionError {}
