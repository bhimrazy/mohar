<?php

namespace App\Modules\Notifications\Boot;

use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Support\Security\EncryptedModel;
use App\Support\Security\EncryptedStorage as EncryptedStorageRegistry;
use Illuminate\Contracts\Container\Container;

final class EncryptedStorage
{
    public function __invoke(Container $app): void
    {
        // Encrypted casts, for `security:reencrypt` (ADR 0022).
        $encrypted = $app->make(EncryptedStorageRegistry::class);
        $encrypted->register('notifications_webhook_endpoints', new EncryptedModel(WebhookEndpoint::class));
    }
}
