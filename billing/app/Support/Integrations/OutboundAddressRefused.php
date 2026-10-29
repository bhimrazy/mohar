<?php

namespace App\Support\Integrations;

use RuntimeException;

/**
 * A staff-entered host or port the outbound address guard refuses. The message is safe to show staff.
 *
 * @api
 */
final class OutboundAddressRefused extends RuntimeException {}
