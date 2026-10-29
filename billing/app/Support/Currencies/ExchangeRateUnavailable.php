<?php

namespace App\Support\Currencies;

use RuntimeException;

/**
 * A rate source could not deliver usable rates (timeout, HTTP error, malformed or empty response).
 * Messages are fixed sentences safe to show staff and store in the automation log.
 *
 * @api
 */
class ExchangeRateUnavailable extends RuntimeException {}
