<?php

namespace App\Modules\Support\Boot;

use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Container\Container;

final class Permissions
{
    public function __invoke(Container $app): void
    {
        $app->make(PermissionRegistry::class)->register('Support', [
            'support.tickets.view' => 'View tickets (assigned departments)',
            'support.tickets.reply' => 'Reply to tickets and add notes',
            'support.tickets.manage' => 'Change status, priority, department, assignee; merge and bulk actions',
            'support.tickets.open' => 'Open tickets on behalf of clients',
            'support.tickets.delete' => 'Delete tickets',
            'support.tickets.access_all' => 'Access tickets of all departments',
            'support.tickets.edit_messages' => 'Edit and delete ticket messages',
            'support.tickets.merge_cross_client' => 'Merge tickets of different clients',
            'support.departments.manage' => 'Manage support departments',
            'support.canned.manage' => 'Manage canned replies',
            'support.kb.manage' => 'Manage the knowledge base',
            'support.announcements.manage' => 'Manage announcements',
            'support.network.manage' => 'Manage network status incidents',
            'support.inbound.manage' => 'View the inbound email log and manage spam rules',
            'support.settings.manage' => 'Manage support settings',
            'support.inbound_webhook.manage' => 'Manage the inbound email webhook secret',
        ], sensitive: ['support.inbound_webhook.manage']);
    }
}
