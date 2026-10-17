<?php

namespace App\Modules\Support\Http\Controllers\Client;

use App\Contracts\Services\ClientServiceDirectory;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Support\Actions\CloseTicket;
use App\Modules\Support\Actions\OpenTicket;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Actions\RateReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Data\TicketDraft;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\CustomerReplyRequest;
use App\Modules\Support\Http\Requests\CustomerTicketRequest;
use App\Modules\Support\Http\Requests\RatingRequest;
use App\Modules\Support\Http\Resources\TicketPresenter;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\AttachmentResponder;
use App\Modules\Support\Services\CustomerDepartments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client-area tickets. Every lookup is scoped to the current client account, including the target of
 * a merged ticket, and related services are validated through ClientServiceDirectory.
 */
class TicketController extends Controller
{
    public function __construct(
        private ClientContext $context,
        private TicketPresenter $presenter,
        private CustomerDepartments $departments,
        private ClientServiceDirectory $services,
    ) {}

    public function index(Request $request): Response
    {
        $this->context->authorize('support');
        $state = $request->query('state') === 'closed' ? 'closed' : ($request->query('state') === 'all' ? 'all' : 'open');

        $tickets = Ticket::query()->notMerged()->with('department:id,name')
            ->where('client_id', $this->context->id())
            ->when($state === 'open', fn (Builder $query) => $query->where('status', '!=', TicketStatus::Closed->value))
            ->when($state === 'closed', fn (Builder $query) => $query->where('status', TicketStatus::Closed->value))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('subject', '%'.$request->string('search')->toString().'%')
                ->orWhereLike('number', '%'.$request->string('search')->toString().'%')))
            ->orderByDesc('last_reply_at')->orderByDesc('id')
            ->paginate(15)->withQueryString();

        return Inertia::render('client/support/tickets/index', [
            'tickets' => $tickets->through(fn (Ticket $ticket): array => $this->presenter->listItem($ticket, TicketPresenter::CLIENT)),
            'filters' => [...$request->only(['search']), 'state' => $state],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->context->authorize('support');
        $slug = $request->filled('department') ? $request->string('department')->toString() : null;

        return Inertia::render('client/support/tickets/create', [
            'departments' => $this->departments->options($this->departments->available(false, $slug)),
            'selected' => $slug !== null ? (string) Department::query()->where('slug', $slug)->value('id') : null,
            'priorities' => TicketPriority::options(),
            'services' => $this->services->forClient($this->context->id()),
            'limits' => $this->presenter->uploadLimits(false),
        ]);
    }

    public function store(CustomerTicketRequest $request, OpenTicket $open): RedirectResponse
    {
        $this->context->authorize('support');
        /** @var User $user */
        $user = $request->user();
        $department = Department::query()->findOrFail($request->integer('department_id'));
        abort_unless($this->departments->canOpenIn($department, false), 403);

        $ticket = $open->handle(new TicketDraft(
            departmentId: $department->id,
            subject: $request->string('subject')->toString(),
            body: $request->string('body')->toString(),
            author: TicketAuthor::client($user),
            requesterName: $user->name,
            requesterEmail: $user->email,
            clientId: $this->context->id(),
            userId: $user->id,
            priority: $request->filled('priority') ? TicketPriority::from($request->string('priority')->toString()) : TicketPriority::Medium,
            serviceId: $request->filled('service_id') ? $request->integer('service_id') : null,
            files: $request->incomingFiles(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number opened. We\'ll reply as soon as we can.', ['number' => $ticket->number])]);

        return to_route('client.support.tickets.show', $ticket->number);
    }

    public function show(string $number): Response|RedirectResponse
    {
        $ticket = $this->ticket($number);

        if ($ticket->merged_into_id !== null) {
            $target = $ticket->resolveMergeTarget();

            return $target->client_id === $this->context->id()
                ? to_route('client.support.tickets.show', $target->number)
                : abort(404);
        }

        $ticket->load(['department', 'messages' => fn ($query) => $query->public(), 'messages.attachments']);

        return Inertia::render('client/support/tickets/show', [
            'ticket' => $this->presenter->detail($ticket, TicketPresenter::CLIENT),
            'messages' => $this->presenter->messages($ticket->messages, $ticket, TicketPresenter::CLIENT),
            'limits' => $this->presenter->uploadLimits(false),
        ]);
    }

    public function reply(CustomerReplyRequest $request, string $number, PostReply $reply): RedirectResponse
    {
        $ticket = $this->ticket($number)->resolveMergeTarget();
        abort_unless($ticket->client_id === $this->context->id(), 404);
        /** @var User $user */
        $user = $request->user();
        $reply->handle($ticket, TicketAuthor::client($user), $request->string('body')->toString(), $request->incomingFiles());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reply sent.')]);

        return to_route('client.support.tickets.show', $ticket->number);
    }

    public function close(Request $request, string $number, CloseTicket $close): RedirectResponse
    {
        $ticket = $this->ticket($number);
        /** @var User $user */
        $user = $request->user();
        $close->handle($ticket, ClosedBy::Client, TicketAuthor::client($user));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number closed.', ['number' => $ticket->number])]);

        return to_route('client.support.tickets.show', $ticket->number);
    }

    public function rate(RatingRequest $request, string $number, TicketMessage $message, RateReply $rate): RedirectResponse
    {
        $ticket = $this->ticket($number);
        abort_unless($message->ticket_id === $ticket->id, 404);
        $rate->handle($message, $request->integer('rating'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thanks for your feedback.')]);

        return back();
    }

    public function attachment(Request $request, string $number, TicketAttachment $attachment, AttachmentResponder $responder): StreamedResponse
    {
        $ticket = $this->ticket($number);
        abort_unless($attachment->ticket_id === $ticket->id && $attachment->isCustomerVisible(), 404);

        return $responder->respond($attachment, $request->boolean('inline'));
    }

    private function ticket(string $number): Ticket
    {
        $this->context->authorize('support');

        return Ticket::query()->where('number', $number)->where('client_id', $this->context->id())->firstOrFail();
    }
}
