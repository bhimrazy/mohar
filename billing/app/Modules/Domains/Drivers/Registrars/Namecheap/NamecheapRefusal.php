<?php

namespace App\Modules\Domains\Drivers\Registrars\Namecheap;

use App\Support\Domains\RegistrarException;

/**
 * Namecheap refused a request with these error numbers, before doing the work. The driver reads the numbers (a name
 * that is not in the account, a name that is taken); everything else sees an ordinary RegistrarException.
 */
final class NamecheapRefusal extends RegistrarException
{
    /** The domain is not found, or is not associated with this account. */
    public const NOT_IN_ACCOUNT = [2_016_166, 2_019_166];

    /**
     * @param  list<int>  $numbers
     */
    public function __construct(string $message, public readonly array $numbers)
    {
        parent::__construct($message);
    }

    public function isNotInAccount(): bool
    {
        return array_intersect($this->numbers, self::NOT_IN_ACCOUNT) !== [];
    }
}
