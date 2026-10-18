<?php

namespace App\Modules\Notifications\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Emails sent to the account (spec §10), always scoped to the current client. Needs the "profile" area.
 */
class EmailHistoryController extends Controller
{
    public function index(ClientContext $context): Response
    {
        $context->authorize('profile');

        $messages = EmailMessage::query()
            ->where('client_id', $context->id())
            ->whereNull('staff_id')
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

    public function show(ClientContext $context, string $uuid): Response
    {
        $context->authorize('profile');

        $message = EmailMessage::query()
            ->where('client_id', $context->id())
            ->whereNull('staff_id')
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
}
