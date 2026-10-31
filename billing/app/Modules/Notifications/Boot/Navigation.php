<?php

namespace App\Modules\Notifications\Boot;

use App\Support\Navigation\Navigation as NavigationMenu;
use App\Support\Navigation\NavItem;
use Illuminate\Contracts\Container\Container;

final class Navigation
{
    public function __invoke(Container $app): void
    {
        $navigation = $app->make(NavigationMenu::class);
        $navigation->add('admin', new NavItem('Email templates', 'admin.notifications.templates.index', 'Mail', 'Setup', 40, 'notifications.templates.view', 'admin.notifications.templates.*'));
        $navigation->add('admin', new NavItem('Email log', 'admin.notifications.emails.index', 'Inbox', 'Setup', 91, 'notifications.log.view', 'admin.notifications.emails.*'));
        $navigation->add('admin', new NavItem('Staff email alerts', 'admin.notifications.subscriptions.edit', 'UserCog', 'Setup', 41, 'notifications.subscriptions.manage', 'admin.notifications.subscriptions.*'));
        $navigation->add('admin', new NavItem('Webhooks', 'admin.notifications.webhooks.index', 'Webhook', 'Setup', 42, 'notifications.webhooks.manage', 'admin.notifications.webhooks.*'));
        $navigation->add('admin-client', new NavItem('Emails', 'admin.notifications.emails.index', 'Mail', order: 80, permission: 'notifications.log.view'));

        $navigation->add('client', new NavItem('Email preferences', 'client.notifications.preferences.edit', 'Mail', 'Setup', 91, null, 'client.notifications.preferences.*'));
        $navigation->add('client', new NavItem('Email history', 'client.notifications.emails.index', 'Inbox', 'Setup', 92, null, 'client.notifications.emails.*'));
    }
}
