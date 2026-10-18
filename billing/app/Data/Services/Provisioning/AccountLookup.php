<?php

namespace App\Data\Services\Provisioning;

use App\Enums\AccountLookupStatus;

/**
 * Result of FindsAccounts::findAccount(). $account is the matching (Executed) or conflicting (Conflict) account;
 * $message explains a Conflict or Unknown to staff and never contains secrets.
 */
final readonly class AccountLookup
{
    public function __construct(
        public AccountLookupStatus $status,
        public ?RemoteAccount $account = null,
        public ?string $message = null,
    ) {}
}
