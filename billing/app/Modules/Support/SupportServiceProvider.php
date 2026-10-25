<?php

namespace App\Modules\Support;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Contracts\Support\PublicHelpDirectory;
use App\Contracts\Support\TicketStaffDirectory;
use App\Data\Notifications\SensitiveValue;
use App\Events\Support\TicketClosed;
use App\Events\Support\TicketOpened;
use App\Events\Support\TicketReplied;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Core\Support\RetentionRunDate;
use App\Modules\Support\Actions\AutoCloseTickets;
use App\Modules\Support\Actions\PruneAttachments;
use App\Modules\Support\Actions\PurgeInboundRaw;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\SupportPublicHelp;
use App\Modules\Support\Services\SupportSettings;
use App\Modules\Support\Services\SupportTicketStaff;
use App\Modules\Support\Services\TicketAccess;
use App\Support\Automation\Automation;
use App\Support\CalendarDate;
use App\Support\Dashboard\Dashboard;
use App\Support\Events\WebhookEvents;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Seo\SitemapRegistry;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class SupportServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'support';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(SupportSettings::class);
        $this->app->scoped(TicketAccess::class);
        $this->app->bind(PublicHelpDirectory::class, SupportPublicHelp::class);
        $this->app->bind(TicketStaffDirectory::class, SupportTicketStaff::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('support:auto-close')->hourly()->withoutOverlapping()->onOneServer();
    }

    protected function bootModule(): void
    {
        $webhooks = $this->app->make(WebhookEvents::class);
        $webhooks->register(TicketOpened::class, 'ticket.opened', 'ticket', 'ticketId');
        $webhooks->register(TicketReplied::class, 'ticket.replied', 'ticket', 'ticketId');
        $webhooks->register(TicketClosed::class, 'ticket.closed', 'ticket', 'ticketId');

        $this->app->make(SitemapRegistry::class)->register('support', fn (): iterable => [
            ['loc' => route('support.kb.index'), 'lastmod' => null],
            ['loc' => route('support.announcements.index'), 'lastmod' => null],
            ...$this->app->make(SupportPublicHelp::class)->sitemapEntries(),
        ]);
        $this->registerRateLimits();
        $this->registerNavigation();
        $this->registerDashboard();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearch();
        $this->registerMergeFields();
        $this->registerAutomation();
    }

    private function registerRateLimits(): void
    {
        RateLimiter::for('support-guest', fn (Request $request): Limit => Limit::perMinute(6)->by('support-guest|'.$request->ip()));
        RateLimiter::for('support-public', fn (Request $request): Limit => Limit::perMinute(60)->by('support-public|'.$request->ip()));
        RateLimiter::for('support-inbound', fn (Request $request): Limit => Limit::perMinute(120)->by('support-inbound|'.$request->ip()));
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Tickets', 'admin.support.tickets.index', 'LifeBuoy', 'Support', 10, 'support.tickets.view', 'admin.support.tickets.*',
            badge: fn (?User $user): ?int => $user !== null ? ($this->awaitingCount($user) ?: null) : null));
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

    /**
     * Tickets awaiting a staff reply that this user can see; the nav badge and the to-do share one count per request.
     */
    private function awaitingCount(User $user): int
    {
        return RequestMemo::remember('support.awaiting.'.$user->id, fn (): int => $this->awaitingQuery($user)->count());
    }

    private function registerDashboard(): void
    {
        $dashboard = $this->app->make(Dashboard::class);
        $scoped = fn (?User $user) => $user !== null
            ? app(TicketAccess::class)->scope(Ticket::query()->notMerged(), $user)
            : Ticket::query()->whereRaw('1 = 0');

        $dashboard->card('admin', 'support.open', fn (?User $user): array => [
            'label' => 'Open tickets',
            'value' => $scoped($user)->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('admin.support.tickets.index'),
            'icon' => 'LifeBuoy',
        ], 'support.tickets.view', 50);

        $dashboard->card('admin', 'support.awaiting', fn (?User $user): array => [
            'label' => 'Awaiting staff reply',
            'value' => $user !== null ? $this->awaitingCount($user) : 0,
            'href' => route('admin.support.tickets.index', ['view' => 'awaiting']),
            'icon' => 'Inbox',
        ], 'support.tickets.view', 51, mirrorsTodo: true);

        $dashboard->card('admin', 'support.mine', fn (?User $user): array => [
            'label' => 'My assigned tickets',
            'value' => $scoped($user)->where('assigned_to', $user?->id)->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('admin.support.tickets.index', ['view' => 'mine']),
            'icon' => 'UserRound',
        ], 'support.tickets.view', 52);

        $dashboard->widget('admin', 'support.awaiting_list', 'list', fn (?User $user): array => [
            'title' => 'Tickets awaiting reply',
            'href' => route('admin.support.tickets.index', ['view' => 'awaiting']),
            'empty' => 'No tickets are waiting for a reply.',
            'items' => $user === null ? [] : array_values($this->awaitingQuery($user)->with('department:id,name')
                ->orderByDesc('last_reply_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Ticket $ticket): array => [
                    'title' => '#'.$ticket->number.' '.$ticket->subject,
                    'subtitle' => $ticket->requester_name.' · '.$ticket->department->name,
                    'avatar' => $ticket->requester_name,
                    'href' => route('admin.support.tickets.show', $ticket),
                    'meta' => $ticket->last_reply_at?->diffForHumans(),
                    'status' => $ticket->status->value,
                ])->all()),
        ], 'support.tickets.view', 53, 'md');

        $dashboard->todo('admin', 'support.awaiting', fn (?User $user): array => [
            'label' => 'Tickets awaiting staff reply',
            'count' => $user !== null ? $this->awaitingCount($user) : 0,
            'href' => route('admin.support.tickets.index', ['view' => 'awaiting']),
            'tone' => 'warning',
            'icon' => 'LifeBuoy',
        ], 'support.tickets.view', 20);

        $dashboard->todo('admin', 'support.unassigned', fn (?User $user): array => [
            'label' => 'Unassigned open tickets',
            'count' => $scoped($user)->whereNull('assigned_to')->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('admin.support.tickets.index', ['view' => 'unassigned']),
            'tone' => 'info',
            'icon' => 'UserRound',
        ], 'support.tickets.view', 21);

        $dashboard->todo('admin', 'support.incidents', fn (?User $user): array => [
            'label' => 'Active network incidents',
            'count' => NetworkIncident::query()->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Scheduled->value])->count(),
            'href' => route('admin.support.network.index'),
            'tone' => 'danger',
            'icon' => 'Activity',
        ], 'support.network.manage', 22);

        $dashboard->card('client', 'support.open', fn (?User $user, Client $client): ?array => app(ClientContext::class)->allows($user, $client, 'support') ? [
            'label' => 'Open tickets',
            'value' => Ticket::query()->notMerged()->where('client_id', $client->id)->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('client.support.tickets.index'),
            'icon' => 'LifeBuoy',
        ] : null, order: 60);

        $dashboard->widget('client', 'support.tickets', 'list', function (?User $user, Client $client): ?array {
            if (! app(ClientContext::class)->allows($user, $client, 'support')) {
                return null;
            }

            // Tickets staff answered (awaiting the client) first, then the latest activity.
            $items = Ticket::query()->notMerged()->where('client_id', $client->id)->where('status', '!=', TicketStatus::Closed->value)
                ->orderByRaw('case when status = ? then 0 else 1 end', [TicketStatus::Answered->value])
                ->orderByDesc('last_reply_at')->orderByDesc('id')
                ->limit(3)
                ->get(['id', 'number', 'subject', 'status', 'last_reply_at'])
                ->map(fn (Ticket $ticket): array => [
                    'title' => $ticket->subject,
                    'subtitle' => '#'.$ticket->number,
                    'href' => route('client.support.tickets.show', $ticket->number),
                    // The client's view of who is waiting: staff answered → them; they replied → us.
                    'status' => match ($ticket->status) {
                        TicketStatus::Answered => 'awaiting-you',
                        TicketStatus::CustomerReply => 'waiting-for-us',
                        default => $ticket->status->value,
                    },
                    'meta' => $ticket->last_reply_at?->toIso8601String(),
                ])->values()->all();

            return [
                'title' => 'Support',
                'items' => $items,
                'href' => route('client.support.tickets.index'),
                'empty' => 'No open tickets. We are here if you need help.',
                'emptyAction' => ['label' => 'Open a ticket', 'href' => route('client.support.tickets.create')],
                // The empty state already offers "Open a ticket"; the header links are for clients with tickets.
                'actions' => $items === [] ? [] : [
                    ['label' => 'New ticket', 'href' => route('client.support.tickets.create'), 'icon' => 'Plus'],
                    ...(Route::has('support.kb.index') ? [['label' => 'Help articles', 'href' => route('support.kb.index'), 'icon' => 'BookOpen']] : []),
                ],
            ];
        }, order: 30);
    }

    /**
     * Tickets awaiting a staff reply that the staff member may see (status index + department scope).
     *
     * @return Builder<Ticket>
     */
    private function awaitingQuery(User $user): Builder
    {
        return app(TicketAccess::class)->scope(Ticket::query()->notMerged(), $user)
            ->whereIn('status', app(SupportSettings::class)->awaitingReplyStatuses());
    }

    private function registerPermissions(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Support', [
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

    private function registerSettings(): void
    {
        $statuses = fn (): array => collect(TicketStatus::active())->mapWithKeys(fn (TicketStatus $status): array => [$status->value => $status->label()])->all();

        $this->app->make(SettingsRegistry::class)->section('support', 'Support', [
            new SettingDefinition('support.ticket_number_format', 'Ticket number format', help: 'Tokens: %A A–Z, %a a–z, %n 0–9, %y %m %d date, %i id. Max 20 characters.', rules: ['max:20']),
            new SettingDefinition('support.auto_close_hours', 'Auto-close idle tickets after (hours)', 'integer', '0 turns auto-close off.', rules: ['min:0']),
            new SettingDefinition('support.auto_close_statuses', 'Statuses that auto-close', 'multiselect', 'Idle tickets in these statuses are closed after the hours above.', options: $statuses),
            new SettingDefinition('support.awaiting_reply_statuses', 'Statuses awaiting a staff reply', 'multiselect', 'Tickets in these statuses count as waiting for staff.', options: $statuses),
            new SettingDefinition('support.attachment_extensions', 'Client attachment types', help: 'Comma-separated extensions.'),
            new SettingDefinition('support.staff_attachment_extensions', 'Staff attachment types', help: 'Comma-separated extensions.'),
            new SettingDefinition('support.attachment_max_mb', 'Max attachment size (MB)', 'integer', rules: ['min:1', 'max:100']),
            new SettingDefinition('support.attachment_max_files', 'Max attachments per message', 'integer', rules: ['min:1', 'max:20']),
            new SettingDefinition('support.prune_attachments_after_months', 'Delete files of tickets closed more than (months)', 'integer', '0 keeps files forever.', rules: ['min:0']),
            new SettingDefinition('support.reply_ratings_enabled', 'Let clients rate staff replies', 'boolean'),
            new SettingDefinition('support.show_clients_only_to_guests', 'Show clients-only departments to guests', 'boolean'),
            new SettingDefinition('support.network_status_requires_login', 'Network status requires login', 'boolean'),
            new SettingDefinition('support.inbound_max_per_sender', 'Max inbound emails per sender per 15 minutes', 'integer', rules: ['min:1']),
            new SettingDefinition('support.allow_unverified_email_replies', 'Accept email replies from unknown senders', 'boolean'),
            new SettingDefinition('support.prevent_email_reopen', 'Email replies cannot reopen closed tickets', 'boolean'),
            new SettingDefinition('support.default_department_id', 'Default department for unknown addresses', 'select', 'Email sent to an address no department uses opens a ticket here.',
                options: fn (): array => ['0' => 'None'] + Department::query()->ordered()->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all()),
            new SettingDefinition('support.reply_marker', 'Reply marker line'),
            new SettingDefinition('support.auto_add_cc', 'Add Cc addresses of inbound emails to the ticket', 'boolean'),
        ], 'support.settings.manage');

        // The inbound webhook's only credential: write-only, encrypted at rest, and behind a sensitive permission, so a
        // support.* role can neither read nor change it and a change needs a recent password confirmation.
        $this->app->make(SettingsRegistry::class)->section('support_inbound', 'Inbound email webhook', [
            new SettingDefinition('support.inbound_secret', 'Inbound email webhook secret', help: 'HMAC-SHA256 secret for the X-Support-Signature header. Write-only: enter a new value to replace it.', rules: ['min:32'], secret: true),
        ], 'support.inbound_webhook.manage');
    }

    private function registerSearch(): void
    {
        $search = $this->app->make(GlobalSearch::class);

        $search->provider('Tickets', function (string $term): array {
            $user = auth()->user();

            if (! $user instanceof User) {
                return [];
            }

            return array_values(app(TicketAccess::class)->scope(Ticket::query(), $user)
                ->where(fn ($query) => $query->whereLike('number', '%'.$term.'%')->orWhereLike('subject', '%'.$term.'%'))
                ->orderByDesc('id')->limit(5)->get()
                ->map(fn (Ticket $ticket): array => [
                    'title' => '#'.$ticket->number.' '.$ticket->subject,
                    'subtitle' => $ticket->requester_name.' · '.$ticket->status->label(),
                    'href' => route('admin.support.tickets.show', $ticket),
                ])->all());
        }, 'support.tickets.view');

        $search->provider('Knowledge base', fn (string $term): array => array_values(KbArticle::query()
            ->whereLike('title', '%'.$term.'%')->orderBy('title')->orderBy('id')->limit(5)->get()
            ->map(fn (KbArticle $article): array => [
                'title' => $article->title,
                'subtitle' => $article->is_published ? 'Published' : 'Draft',
                'href' => route('admin.support.kb.articles.edit', $article),
            ])->all()), 'support.kb.manage');
    }

    private function registerMergeFields(): void
    {
        $registry = $this->app->make(MergeFieldRegistry::class);

        $registry->register('ticket', function (int $id): array {
            $ticket = Ticket::query()->with('department')->findOrFail($id);

            return [
                'ticket_id' => $ticket->id,
                'client_id' => $ticket->client_id,
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'status' => $ticket->status->label(),
                'priority' => $ticket->priority->label(),
                'department' => $ticket->department->name,
                'department_email' => $ticket->department->email,
                'requester_name' => $ticket->requester_name,
                'requester_email' => $ticket->requester_email,
                'cc' => $ticket->cc ?? [],
                'is_guest' => $ticket->isGuest(),
                // A guest link carries the ticket's access key: sensitive, so only the system's ticket button carries it.
                'url' => $ticket->isGuest()
                    ? new SensitiveValue(route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]), secret: $ticket->access_key)
                    : route('client.support.tickets.show', $ticket->number),
                'admin_url' => route('admin.support.tickets.show', $ticket),
                'email_subject' => '[#'.$ticket->number.'] '.$ticket->subject,
                'autoresponse' => $ticket->department->send_autoresponse,
            ];
        });

        $registry->register('ticket_reply', function (int $id): array {
            $message = TicketMessage::query()->with(['ticket', 'attachments'])->findOrFail($id);

            return [
                'reply_id' => $message->id,
                'ticket_id' => $message->ticket_id,
                'client_id' => $message->ticket->client_id,
                'author_name' => $message->author_name,
                'author_type' => $message->author_type->value,
                'body' => $message->body,
                'body_html' => $message->body_html,
                'excerpt' => Str::limit(strip_tags($message->body_html), 200),
                'message_id' => $message->email_message_id,
                'is_note' => $message->isNote(),
                'is_autoresponse' => $message->is_autoresponse,
                'attachments' => $message->attachments->map(fn ($attachment): array => [
                    'id' => $attachment->id, 'name' => $attachment->original_name, 'size_bytes' => $attachment->size_bytes,
                ])->all(),
            ];
        });
    }

    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);
        $automation->task('support.auto_close', Automation::HOUSEKEEPING, fn (CalendarDate $today): int => app(AutoCloseTickets::class)->handle($today->equals(app(CompanyClock::class)->calendarDate()) ? null : app(CompanyClock::class)->dayBounds($today)[1]), label: 'Close idle tickets', description: 'Closes tickets that have waited on the customer longer than the auto-close period.');
        $automation->task('support.purge_inbound_raw', Automation::HOUSEKEEPING, fn (CalendarDate $today): int => app(PurgeInboundRaw::class)->handle(app(CompanyClock::class)->dayBounds(RetentionRunDate::of($today))[0]), label: 'Purge raw inbound emails', description: 'Deletes stored raw inbound email payloads older than the retention period.');
        $automation->task('support.prune_attachments', Automation::HOUSEKEEPING, fn (CalendarDate $today): int => app(PruneAttachments::class)->handle(app(CompanyClock::class)->dayBounds(RetentionRunDate::of($today))[0]), label: 'Delete old ticket attachments', description: 'Deletes attachment files from tickets closed longer than the configured months (details stay).');
    }
}
