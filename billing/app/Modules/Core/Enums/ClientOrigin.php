<?php

namespace App\Modules\Core\Enums;

/**
 * How a client record was created. Self-created clients (sign-up, checkout) chose their own email address, so a match
 * on that address is trusted only once an owner login has verified it (account import, AccountImportMatcher).
 */
enum ClientOrigin: string
{
    case SignUp = 'signup';
    case Checkout = 'checkout';
    case Staff = 'staff';
    case Import = 'import';
    case Api = 'api';

    /**
     * Whether the client created themselves, with an address nobody has checked.
     */
    public function isSelfCreated(): bool
    {
        return $this === self::SignUp || $this === self::Checkout;
    }
}
