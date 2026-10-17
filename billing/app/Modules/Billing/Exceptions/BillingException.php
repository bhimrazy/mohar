<?php

namespace App\Modules\Billing\Exceptions;

use DomainException;

/**
 * A billing rule was violated (e.g. refund above the refundable amount). The message is safe to show staff.
 */
class BillingException extends DomainException {}
