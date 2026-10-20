<?php

namespace Mohar\Simulators\Contracts;

/**
 * A simulator of a payment provider (Stripe, Khalti, eSewa, ...): its request log and recordings also apply the kernel
 * Redactor's payment rule (card numbers, CVCs, expiry, Luhn-valid digits in text). Other simulators keep the default
 * rule only, so keys such as `expires_at` or `company` keep their values and types.
 */
interface RedactsPayments {}
