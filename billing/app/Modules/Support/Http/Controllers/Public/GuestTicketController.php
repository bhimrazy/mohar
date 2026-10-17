<?php

namespace App\Modules\Support\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Support\Actions\CloseTicket;
use App\Modules\Support\Actions\OpenTicket;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Actions\RateReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Data\TicketDraft;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\TicketPriority;
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
use App\Modules\Support\Services\RequesterResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guest tickets: submit with name + email, then view/reply through the link carrying the
 * ticket's random access key. A matching email only attaches the client; it never signs the guest in.
 */
class GuestTicketController extends Controller
{
    public function __construct(private TicketPresenter $presenter, private CustomerDepartments $departments) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null && ! $request->user()->is_staff && $request->user()->clients()->exists()) {
            return to_route('client.support.tickets.create', $request->only('department'));
        }

        $slug = $request->filled('department') ? $request->string('department')->toString() : null;

        return Inertia::render('public/support/tickets/create', [
            'departments' => $this->departments->options($this->departments->available(true, $slug)),
            'selected' => $slug !== null ? (string) Department::query()->where('slug', $slug)->value('id') : null,
            'priorities' => TicketPriority::options(),
            'limits' => $this->presenter->uploadLimits(false),
        ]);
    }

    public function store(CustomerTicketRequest $request, OpenTicket $open, RequesterResolver $requesters): RedirectResponse
    {
        $department = Department::query()->findOrFail($request->integer('department_id'));
        abort_unless($this->departments->canOpenIn($department, true), 403, __('This department is for registered clients. Please sign in.'));

        $email = mb_strtolower($request->string('email')->toString());
        $name = $request->string('name')->toString();
        $match = $requesters->match($email);

        $ticket = $open->handle(new TicketDraft(
            departmentId: $department->id,
            subject: $request->string('subject')->toString(),
            body: $request->string('body')->toString(),
            author: TicketAuthor::guest($name, $email),
            requesterName: $name,
            requesterEmail: $email,
            clientId: $match['client_id'] ?? null,
            contactId: $match['contact_id'] ?? null,
            priority: $request->filled('priority') ? TicketPriority::from($request->string('priority')->toString()) : TicketPriority::Medium,
            files: $request->incomingFiles(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number opened. Keep this page\'s link to follow up.', ['number' => $ticket->number])]);

        return to_route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]);
    }

    public function show(Request $request, string $number): Response
    {
        $ticket = $this->ticket($request, $number);

        if ($ticket->merged_into_id !== null) {
            return Inertia::render('public/support/tickets/merged', ['number' => $ticket->number]);
        }

        $ticket->load(['department', 'messages' => fn ($query) => $query->public(), 'messages.attachments']);

        return Inertia::render('public/support/tickets/show', [
            'ticket' => $this->presenter->detail($ticket, TicketPresenter::GUEST),
            'messages' => $this->presenter->messages($ticket->messages, $ticket, TicketPresenter::GUEST),
            'accessKey' => $ticket->access_key,
            'limits' => $this->presenter->uploadLimits(false),
        ]);
    }

    public function reply(CustomerReplyRequest $request, string $number, PostReply $reply): RedirectResponse
    {
        $ticket = $this->ticket($request, $number);
        abort_if($ticket->merged_into_id !== null, 404);
        $reply->handle($ticket, TicketAuthor::guest($ticket->requester_name, $ticket->requester_email), $request->string('body')->toString(), $request->incomingFiles());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reply sent.')]);

        return to_route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]);
    }

    public function close(Request $request, string $number, CloseTicket $close): RedirectResponse
    {
        $ticket = $this->ticket($request, $number);
        $close->handle($ticket, ClosedBy::Client, TicketAuthor::guest($ticket->requester_name, $ticket->requester_email));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number closed.', ['number' => $ticket->number])]);

        return to_route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]);
    }

    public function rate(RatingRequest $request, string $number, TicketMessage $message, RateReply $rate): RedirectResponse
    {
        $ticket = $this->ticket($request, $number);
        abort_unless($message->ticket_id === $ticket->id, 404);
        $rate->handle($message, $request->integer('rating'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thanks for your feedback.')]);

        return back();
    }

    public function attachment(Request $request, string $number, TicketAttachment $attachment, AttachmentResponder $responder): StreamedResponse
    {
        $ticket = $this->ticket($request, $number);
        abort_unless($attachment->ticket_id === $ticket->id && $attachment->isCustomerVisible(), 404);

        return $responder->respond($attachment, $request->boolean('inline'));
    }

    /**
     * The ticket for a number + access key. Wrong or missing keys look exactly like unknown numbers.
     */
    private function ticket(Request $request, string $number): Ticket
    {
        $ticket = Ticket::query()->where('number', $number)->first();
        $key = (string) $request->input('key', $request->query('key', ''));

        abort_if($ticket === null || $key === '' || ! hash_equals($ticket->access_key, $key), 404);

        return $ticket;
    }
}
