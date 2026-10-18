<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Actions\EditMessage;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Actions\ProcessInboundEmail;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Drivers\Inbound\GenericDriver;
use App\Modules\Support\Enums\InboundEmailStatus;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\MessageUpdateRequest;
use App\Modules\Support\Http\Requests\StaffReplyRequest;
use App\Modules\Support\Models\InboundEmail;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\TicketAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TicketMessageController extends Controller
{
    public function __construct(private TicketAccess $access) {}

    public function reply(StaffReplyRequest $request, Ticket $ticket, PostReply $reply): RedirectResponse
    {
        $user = $this->staffFor($request, $ticket);

        $reply->handle(
            $ticket,
            TicketAuthor::staff($user),
            $request->string('body')->toString(),
            $request->incomingFiles(),
            $request->filled('status') ? TicketStatus::from($request->string('status')->toString()) : null,
            $request->filled('version') ? $request->integer('version') : null,
            $request->boolean('force'),
            TicketSource::Staff,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reply sent on #:number.', ['number' => $ticket->number])]);

        return to_route('admin.support.tickets.show', $ticket);
    }

    public function note(StaffReplyRequest $request, Ticket $ticket, AddNote $note): RedirectResponse
    {
        $user = $this->staffFor($request, $ticket);
        $note->handle($ticket, $user, $request->string('body')->toString(), $request->incomingFiles());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note added.')]);

        return to_route('admin.support.tickets.show', $ticket);
    }

    public function update(MessageUpdateRequest $request, Ticket $ticket, TicketMessage $message, EditMessage $edit): RedirectResponse
    {
        $user = $this->staffFor($request, $ticket);
        $edit->update($message, $user, $request->string('body')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message updated.')]);

        return to_route('admin.support.tickets.show', $ticket);
    }

    public function destroy(Request $request, Ticket $ticket, TicketMessage $message, EditMessage $edit): RedirectResponse
    {
        $this->authorize('support.tickets.edit_messages');
        $user = $this->staffFor($request, $ticket);
        $edit->delete($message, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message deleted.')]);

        return to_route('admin.support.tickets.show', $ticket);
    }

    public function import(Request $request, Ticket $ticket, InboundEmail $inboundEmail, ProcessInboundEmail $pipeline, GenericDriver $driver): RedirectResponse
    {
        $this->authorize('support.tickets.reply');
        $this->staffFor($request, $ticket);

        if ($inboundEmail->status !== InboundEmailStatus::Blocked || $inboundEmail->raw_path === null
            || ! Storage::disk(AttachmentStore::DISK)->exists($inboundEmail->raw_path)) {
            throw ValidationException::withMessages(['inbound' => __('This email can no longer be imported.')]);
        }

        $payload = json_decode((string) Storage::disk(AttachmentStore::DISK)->get($inboundEmail->raw_path), true);
        $pipeline->import($inboundEmail, $driver->parse(is_array($payload) ? $payload : []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email reply imported.')]);

        return to_route('admin.support.tickets.show', $ticket);
    }

    private function staffFor(Request $request, Ticket $ticket): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canAccess($user, $ticket), 403, __('You do not have access to this department.'));

        return $user;
    }
}
