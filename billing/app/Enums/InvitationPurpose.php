<?php

namespace App\Enums;

/**
 * Why a client login is invited (App\Contracts\Auth\ClientLoginInvitations). The invitation flow maps each purpose to
 * its editable template, so callers never name templates.
 *
 * Open enum (ADR 0010): cases may be added in a minor version, so consumers need a default branch.
 *
 * @open
 */
enum InvitationPurpose: string
{
    /** Staff added a login to a client account ("You're invited to a client account"). */
    case LoginAdded = 'login_added';

    /** A client import created the login ("We have moved to a new client area"). */
    case Import = 'import';
}
