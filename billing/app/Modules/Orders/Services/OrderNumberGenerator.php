<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use Closure;
use RuntimeException;

/**
 * Unique random 10-digit order numbers (no leading zero), retried on collision.
 */
class OrderNumberGenerator
{
    public const LENGTH = 10;

    private const MAX_ATTEMPTS = 10;

    /** @var Closure(): string */
    private Closure $random;

    /**
     * @param  (Closure(): string)|null  $random  source of candidate numbers (tests)
     */
    public function __construct(?Closure $random = null)
    {
        $this->random = $random ?? fn (): string => (string) random_int(10 ** (self::LENGTH - 1), 10 ** self::LENGTH - 1);
    }

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = ($this->random)();

            if (! Order::query()->where('number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique order number.');
    }
}
