<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Actions\CloseTicket;
use App\Modules\Support\Actions\MergeTickets;
use App\Modules\Support\Actions\OpenTicket;
use App\Modules\Support\Actions\UpdateTicket;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Data\TicketDraft;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\InboundEmailStatus;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\BulkTicketRequest;
use App\Modules\Support\Http\Requests\MergeTicketsRequest;
use App\Modules\Support\Http\Requests\StaffTicketRequest;
use App\Modules\Support\Http\Requests\UpdateTicketRequest;
use App\Modules\Support\Http\Resources\TicketPresenter;
use App\Modules\Support\Models\CannedReply;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\InboundEmail;
use App\Modules\Support\Models\StaffSignature;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\SupportSettings;
use App\Modules\Support\Services\TicketAccess;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public const VIEWS = ['all', 'awaiting', 'mine', 'flagged', 'unassigned', 'closed'];

    public function __construct(
        private TicketAccess $access,
        private TicketPresenter $presenter,
        private SupportSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('support.tickets.view');
        /** @var User $user */
        $user = $request->user();
        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'all';
        $awaiting = $this->settings->awaitingReplyStatuses();

        $query = $this->access->scope(Ticket::query(), $user)
            ->notMerged()
            ->with(['department:id,name', 'client', 'assignee:id,name'])
            ->search($request->string('search')->toString())
            ->when($view === 'awaiting', fn (Builder $query) => $query->whereIn('status', $awaiting))
            ->when($view === 'mine', fn (Builder $query) => $query->where('assigned_to', $user->id)->where('status', '!=', TicketStatus::Closed->value))
            ->when($view === 'flagged', fn (Builder $query) => $query->where('is_pinned', true))
            ->when($view === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_to')->where('status', '!=', TicketStatus::Closed->value))
            ->when($view === 'closed', fn (Builder $query) => $query->where('status', TicketStatus::Closed->value))
            ->when($view === 'all' && ! $request->filled('status'), fn (Builder $query) => $query->where('status', '!=', TicketStatus::Closed->value))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('department'), fn (Builder $query) => $query->where('department_id', $request->integer('department')))
            ->when($request->filled('priority'), fn (Builder $query) => $query->where('priority', $request->string('priority')->toString()))
            ->when($request->filled('client'), fn (Builder $query) => $query->where('client_id', $request->integer('client')))
            ->when($request->filled('tag'), fn (Builder $query) => $query->whereLike('tags', '%"'.$request->string('tag')->toString().'"%'))
            ->when($request->query('assigned') === 'me', fn (Builder $query) => $query->where('assigned_to', $user->id))
            ->orderByDesc('is_pinned');

        if ($view === 'awaiting') {
            // Oldest *unanswered* client message first, so repeated client replies don't bump (spec §13).
            $query->orderBy(TicketMessage::query()->selectRaw('min(created_at)')
                ->whereColumn('support_ticket_messages.ticket_id', 'support_tickets.id')
                ->where('type', 'reply')
                ->whereIn('author_type', ['client', 'guest'])
                ->where(fn (Builder $messages) => $messages
                    ->whereNull('support_tickets.last_staff_reply_at')
                    ->orWhereColumn('support_ticket_messages.created_at', '>', 'support_tickets.last_staff_reply_at')));
        } else {
            $query->orderByDesc('last_reply_at');
        }

        $tickets = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('admin/support/tickets/index', [
            'tickets' => $tickets->through(fn (Ticket $ticket): array => $this->presenter->listItem($ticket)),
            'filters' => [...$request->only(['search', 'status', 'department', 'priority', 'client', 'tag', 'assigned']), 'view' => $view],
            'client' => $request->filled('client') ? Client::query()->find($request->integer('client'))?->only(['id', 'first_name', 'last_name', 'company_name']) : null,
            'statuses' => TicketStatus::options(),
            'priorities' => TicketPriority::options(),
            'departments' => $this->departmentOptions($user),
            'staff' => $this->staffOptions(),
            'signature' => StaffSignature::query()->where('user_id', $user->id)->value('signature'),
            'can' => [
                'open' => $user->hasPermission('support.tickets.open'),
                'manage' => $user->hasPermission('support.tickets.manage'),
                'delete' => $user->hasPermission('support.tickets.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('support.tickets.open');
        /** @var User $user */
        $user = $request->user();
        $client = $request->filled('client') ? Client::query()->find($request->integer('client')) : null;

        return Inertia::render('admin/support/tickets/create', [
            'departments' => $this->departmentOptions($user),
            'priorities' => TicketPriority::options(),
            'statuses' => TicketStatus::options(),
            'client' => $client ? [
                'id' => $client->id,
                'display_name' => $client->display_name,
                'name' => trim($client->first_name.' '.$client->last_name),
                'email' => $client->email,
            ] : null,
            'limits' => $this->presenter->uploadLimits(true),
        ]);
    }

    public function store(StaffTicketRequest $request, OpenTicket $open): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ticket = $open->handle(new TicketDraft(
            departmentId: $request->integer('department_id'),
            subject: $request->string('subject')->toString(),
            body: $request->string('body')->toString(),
            author: TicketAuthor::staff($user),
            requesterName: $request->string('requester_name')->toString(),
            requesterEmail: $request->string('requester_email')->toString(),
            clientId: $request->filled('client_id') ? $request->integer('client_id') : null,
            priority: TicketPriority::from($request->string('priority')->toString()),
            source: TicketSource::Staff,
            status: $request->filled('status') ? TicketStatus::from($request->string('status')->toString()) : null,
            serviceId: $request->filled('service_id') ? $request->integer('service_id') : null,
            cc: array_values(array_map('strval', (array) $request->input('cc', []))),
            files: $request->incomingFiles(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number opened.', ['number' => $ticket->number])]);

        return to_route('admin.support.tickets.show', $ticket);
    }

    public function show(Request $request, Ticket $ticket, TicketRecorder $recorder): Response|RedirectResponse
    {
        $this->authorize('support.tickets.view');
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canAccess($user, $ticket), 403, __('You do not have access to this department.'));

        if ($ticket->merged_into_id !== null) {
            return to_route('admin.support.tickets.show', $ticket->resolveMergeTarget());
        }

        $ticket->load(['department', 'client', 'assignee:id,name', 'messages.attachments']);

        return Inertia::render('admin/support/tickets/show', [
            'ticket' => $this->presenter->detail($ticket),
            'client' => $ticket->client ? [
                'id' => $ticket->client->id,
                'display_name' => $ticket->client->display_name,
                'email' => $ticket->client->email,
                'status' => $ticket->client->status,
            ] : null,
            'messages' => $this->presenter->messages($ticket->messages, $ticket, TicketPresenter::ADMIN),
            'events' => TicketEvent::query()->where('ticket_id', $ticket->id)->latest('id')->limit(30)->get()
                ->map(fn (TicketEvent $event): array => [
                    'id' => $event->id,
                    'description' => $recorder->describe($event),
                    'created_at' => $event->created_at?->toIso8601String(),
                ]),
            'blocked' => InboundEmail::query()->where('ticket_id', $ticket->id)->where('status', InboundEmailStatus::Blocked->value)
                ->orderByDesc('received_at')->orderByDesc('id')->get(['id', 'from', 'subject', 'received_at']),
            'mergeCandidates' => Ticket::query()->notMerged()->whereKeyNot($ticket->id)
                ->when($ticket->client_id !== null, fn (Builder $query) => $query->where('client_id', $ticket->client_id), fn (Builder $query) => $query->where('requester_email', $ticket->requester_email))
                ->orderByDesc('id')->limit(20)->get(['id', 'number', 'subject', 'status', 'created_at'])
                ->map(fn (Ticket $candidate): array => ['id' => $candidate->id, 'number' => $candidate->number, 'subject' => $candidate->subject, 'status' => $candidate->status->value]),
            'departments' => $this->departmentOptions($user),
            'staff' => $this->eligibleStaff($ticket->department_id),
            'statuses' => TicketStatus::options(),
            'priorities' => TicketPriority::options(),
            'cannedReplies' => CannedReply::query()->with('category:id,name')->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()
                ->map(fn (CannedReply $reply): array => ['id' => $reply->id, 'name' => $reply->name, 'body' => $reply->body, 'category' => $reply->category?->name]),
            'limits' => $this->presenter->uploadLimits(true),
            'can' => [
                'reply' => $user->hasPermission('support.tickets.reply'),
                'manage' => $user->hasPermission('support.tickets.manage'),
                'delete' => $user->hasPermission('support.tickets.delete'),
                'editMessages' => $user->hasPermission('support.tickets.edit_messages'),
            ],
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $update): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket);
        /** @var User $user */
        $user = $request->user();

        // Staff may hand a ticket to any department; they are sent back to the queue if they lose access.
        $update->handle($ticket, $user, $request->changes(), $request->filled('version') ? $request->integer('version') : null, $request->boolean('force'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number updated.', ['number' => $ticket->number])]);

        return $this->access->canAccess($user, $ticket)
            ? to_route('admin.support.tickets.show', $ticket)
            : to_route('admin.support.tickets.index');
    }

    public function destroy(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('support.tickets.delete');
        $this->authorizeTicket($request, $ticket);
        $number = $ticket->number;

        DB::transaction(fn () => $ticket->delete());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number deleted.', ['number' => $number])]);

        return to_route('admin.support.tickets.index');
    }

    public function merge(MergeTicketsRequest $request, MergeTickets $merge): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tickets = Ticket::query()->whereIn('id', $request->ticketIds())->get();

        foreach ($tickets as $ticket) {
            abort_unless($this->access->canAccess($user, $ticket), 403);
        }

        $target = $merge->handle($request->ticketIds(), $user, $user->hasPermission('support.tickets.merge_cross_client'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tickets merged into #:number.', ['number' => $target->number])]);

        return to_route('admin.support.tickets.show', $target);
    }

    public function bulk(BulkTicketRequest $request, CloseTicket $close, UpdateTicket $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tickets = $this->access->scope(Ticket::query(), $user)->whereIn('id', $request->ticketIds())->get();
        $action = $request->string('action')->toString();

        foreach ($tickets as $ticket) {
            match ($action) {
                'close' => $close->handle($ticket, ClosedBy::Staff, TicketAuthor::staff($user)),
                'assign' => $update->handle($ticket, $user, ['assigned_to' => $request->filled('assigned_to') ? $request->integer('assigned_to') : null]),
                default => $ticket->delete(),
            };
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} :count ticket updated.|[2,*] :count tickets updated.', $tickets->count(), ['count' => $tickets->count()])]);

        return back();
    }

    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canAccess($user, $ticket), 403, __('You do not have access to this department.'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function departmentOptions(User $user): array
    {
        $ids = $this->access->hasFullAccess($user) ? null : $this->access->departmentIds($user);

        return array_values(Department::query()->ordered()->orderBy('id')
            ->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids ?? []))
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => ['value' => (string) $department->id, 'label' => $department->name])
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function staffOptions(): array
    {
        return array_values(User::query()->where('is_staff', true)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $staff): array => ['value' => (string) $staff->id, 'label' => $staff->name])->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function eligibleStaff(int $departmentId): array
    {
        return array_values(User::query()->where('is_staff', true)->with('role')->orderBy('name')->orderBy('id')->get()
            ->filter(fn (User $staff): bool => $this->access->isEligibleAssignee($staff, $departmentId))
            ->map(fn (User $staff): array => ['value' => (string) $staff->id, 'label' => $staff->name])->all());
    }
}
