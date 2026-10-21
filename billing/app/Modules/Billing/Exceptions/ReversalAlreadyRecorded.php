<?php

namespace App\Modules\Billing\Exceptions;

use RuntimeException;

/**
 * A provider refund or dispute whose account-credit line is already in the ledger: another delivery of it booked
 * it. Thrown inside the callback's transaction so nothing this delivery wrote is kept; the caller answers "duplicate".
 */
class ReversalAlreadyRecorded extends RuntimeException {}
