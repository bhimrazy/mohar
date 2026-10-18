<?php

namespace App\Modules\PaymentsNepal\Support;

use RuntimeException;

/**
 * The provider refused or could not complete a request (initiate failed, misconfiguration). Billing turns it
 * into a generic customer message; the text goes to the gateway log, so it never contains secrets.
 */
final class ProviderException extends RuntimeException {}
