<?php

namespace App\Modules\Core\Enums;

/**
 * Who set the client's current email address. An address the client set is trusted (account import) only once a
 * login on that client has verified it; staff and the importer vouch for the addresses they enter.
 */
enum EmailSource: string
{
    case Staff = 'staff';
    case Import = 'import';
    case Client = 'client';

    /**
     * Whether staff or the importer vouch for the address.
     */
    public function isVouched(): bool
    {
        return $this !== self::Client;
    }
}
