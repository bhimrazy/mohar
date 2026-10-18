<?php

namespace App\Data\Services\Provisioning;

use App\Enums\ReconcileStatus;

/**
 * Result of ReconcilesCommands::reconcile(). $account is what the driver found (the account it made, or the
 * conflicting one); $message explains a Conflict or Unknown to staff and never contains secrets.
 */
final readonly class CommandLookup
{
    public function __construct(
        public ReconcileStatus $status,
        public ?RemoteAccount $account = null,
        public ?string $message = null,
    ) {}
}
