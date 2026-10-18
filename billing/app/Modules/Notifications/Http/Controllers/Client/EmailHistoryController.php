<?php

namespace App\Modules\Notifications\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Emails sent to the account (spec §10), always scoped to the current client. Needs the "profile" area.
 * Owners see all of the account's mail; other logins only mail addressed to them (security review L4).
 */
class EmailHistoryController extends Controller
{
    public function index(Request $request, ClientContext $context): Response
    {
        $context->authorize('profile');

        $messages = $this->visible($request, $context)
            ->where('status', EmailStatus::Sent)
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->paginate(25, ['id', 'uuid', 'recipient', 'subject', 'sent_at']);

        return Inertia::render('client/notifications/emails/index', [
            'messages' => $messages->through(fn (EmailMessage $message): array => [
                'uuid' => $message->uuid,
                'recipient' => $message->recipient,
                'subject' => $message->subject,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ]),
        ]);
    }

    public function show(Request $request, ClientContext $context, string $uuid): Response
    {
        $context->authorize('profile');

        $message = $this->visible($request, $context)
            ->where('status', EmailStatus::Sent)
            ->where('uuid', $uuid)
            ->firstOrFail();

        return Inertia::render('client/notifications/emails/show', [
            'message' => [
                'uuid' => $message->uuid,
                'recipient' => $message->recipient,
                'subject' => $message->subject,
                'sent_at' => $message->sent_at?->toIso8601String(),
                'body_html' => $message->body_html,
                'body_text' => $message->body_text,
            ],
        ]);
    }

    /**
     * @return Builder<EmailMessage>
     */
    private function visible(Request $request, ClientContext $context): Builder
    {
        $user = $request->user();
        $isOwner = $user instanceof User && $context->client()->users()->whereKey($user->id)->wherePivot('is_owner', true)->exists();

        return EmailMessage::query()
            ->where('client_id', $context->id())
            ->whereNull('staff_id')
            ->when(! $isOwner, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('user_id', $user?->id)
                ->orWhere('recipient', mb_strtolower((string) $user?->email))));
    }
}
