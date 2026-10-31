<?php

namespace App\Modules\Support\Boot;

use App\Models\User;
use App\Modules\Support\Queries\AwaitingTickets;
use App\Support\Navigation\Navigation as NavigationMenu;
use App\Support\Navigation\NavItem;
use Illuminate\Contracts\Container\Container;

final class Navigation
{
    public function __invoke(Container $app): void
    {
        $navigation = $app->make(NavigationMenu::class);
        $navigation->add('admin', new NavItem('Tickets', 'admin.support.tickets.index', 'LifeBuoy', 'Support', 10, 'support.tickets.view', 'admin.support.tickets.*',
            badge: fn (?User $user): ?int => $user !== null ? (AwaitingTickets::count($user) ?: null) : null));
        $navigation->add('admin', new NavItem('Departments', 'admin.support.departments.index', 'Inbox', 'Support', 20, 'support.departments.manage', 'admin.support.departments.*'));
        $navigation->add('admin', new NavItem('Canned replies', 'admin.support.canned-replies.index', 'Mail', 'Support', 30, 'support.canned.manage', 'admin.support.canned-replies.*'));
        $navigation->add('admin', new NavItem('Knowledge base', 'admin.support.kb.articles.index', 'BookOpen', 'Support', 40, 'support.kb.manage', 'admin.support.kb.*'));
        $navigation->add('admin', new NavItem('Announcements', 'admin.support.announcements.index', 'Megaphone', 'Support', 50, 'support.announcements.manage', 'admin.support.announcements.*'));
        $navigation->add('admin', new NavItem('Network status', 'admin.support.network.index', 'Activity', 'Support', 60, 'support.network.manage', 'admin.support.network.*'));
        $navigation->add('admin', new NavItem('Email piping', 'admin.support.inbound.index', 'Webhook', 'Support', 70, 'support.inbound.manage', 'admin.support.inbound.*'));

        $navigation->add('client', new NavItem('Tickets', 'client.support.tickets.index', 'LifeBuoy', 'Support', 10, null, 'client.support.tickets.*'));
        $navigation->add('client', new NavItem('Knowledge base', 'support.kb.index', 'BookOpen', 'Support', 20, null, 'support.kb.*'));
        $navigation->add('client', new NavItem('Announcements', 'support.announcements.index', 'Megaphone', 'Support', 30, null, 'support.announcements.*'));
        $navigation->add('client', new NavItem('Network status', 'support.network.index', 'Activity', 'Support', 40, null, 'support.network.*'));

        $navigation->add('admin-client', new NavItem('Tickets', 'admin.support.tickets.index', 'LifeBuoy', order: 50, permission: 'support.tickets.view'));
        $navigation->add('admin-create', new NavItem('New ticket', 'admin.support.tickets.create', 'LifeBuoy', order: 40, permission: 'support.tickets.open'));
    }
}
