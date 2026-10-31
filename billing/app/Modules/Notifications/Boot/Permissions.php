<?php

namespace App\Modules\Notifications\Boot;

use App\Modules\Notifications\NotificationsServiceProvider;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Container\Container;

final class Permissions
{
    public function __invoke(Container $app): void
    {
        $app->make(PermissionRegistry::class)->register('Notifications', [
            'notifications.templates.view' => 'View and preview email templates',
            'notifications.templates.manage' => 'Edit, create and delete email templates',
            'notifications.log.view' => 'View the email log',
            'notifications.log.resend' => 'Resend emails from the log',
            'notifications.send' => 'Send emails to clients',
            'notifications.subscriptions.manage' => 'Choose which staff roles receive which alerts',
            'notifications.webhooks.manage' => 'Manage webhook endpoints, secrets and deliveries',
            'notifications.delivery.manage' => 'Change email senders, Reply-To, global BCC and template CC/BCC',
            'notifications.settings.manage' => 'Manage email & notification settings',
        ], sensitive: NotificationsServiceProvider::SENSITIVE_PERMISSIONS);
    }
}
