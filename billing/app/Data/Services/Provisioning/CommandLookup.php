<?php

namespace App\Data\Services\Provisioning;

use App\Enums\ReconcileStatus;

/**
 * Result of ReconcilesCommands::reconcile(). $account is what the driver found (the account it made, or the
 * conflicting one); $message explains a Conflict or Unknown to staff and never contains secrets.
 */
final readonly class CommandLookup
{
    /**
     * @param  array<string, mixed>  $data  for Executed: what the command would have reported had it answered, with the
     *                                      DriverResult::$data keys (`driver_data`, `dedicated_ip`, ...), so a settled
     *                                      command stores the same as a completed one
     */
    public function __construct(
        public ReconcileStatus $status,
        public ?RemoteAccount $account = null,
        public ?string $message = null,
        public array $data = [],
    ) {}
}
