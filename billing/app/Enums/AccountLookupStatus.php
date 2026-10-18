<?php

namespace App\Enums;

/**
 * Answer of FindsAccounts::findAccount().
 *
 * Closed enum (ADR 0010): the verify sweep branches on every case.
 */
enum AccountLookupStatus: string
{
    /** The account exists and is ours: the earlier command ran. */
    case Executed = 'executed';

    /** The username or domain exists but belongs to someone else. Staff must decide. */
    case Conflict = 'conflict';

    /** Neither the username nor the domain exists. */
    case Absent = 'absent';

    /** The lookup itself failed; nothing is known. */
    case Unknown = 'unknown';
}
