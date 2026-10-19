<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\NotificationMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email log (spec §9): search by client, recipient, template and status; view the stored (redacted)
 * copy; resend as a new attempt.
 */
class EmailLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('notifications.log.view');

        $client = $request->filled('client') ? Client::query()->find($request->integer('client')) : null;
        $messages = EmailMessage::query()->with('client:id,first_name,last_name,company_name')
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('template'), fn ($query) => $query->where('template_key', $request->string('template')->toString()))
            ->when($request->filled('client'), fn ($query) => $query->where('client_id', $request->integer('client')))
            ->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $names = $this->templateNames();

        return Inertia::render('admin/notifications/emails/index', [
            'messages' => $messages->through(fn (EmailMessage $message): array => $this->summary($message, $names)),
            'statuses' => array_map(fn (EmailStatus $status): array => ['value' => $status->value, 'label' => $status->label()], EmailStatus::cases()),
            'templates' => EmailMessage::query()->whereNotNull('template_key')->distinct()->orderBy('template_key')->pluck('template_key')
                ->map(fn (string $key): array => ['value' => $key, 'label' => $names[$key] ?? $key])->sortBy('label')->values(),
            'client' => $client === null ? null : ['id' => $client->id, 'name' => $client->display_name],
            'filters' => $request->only(['search', 'status', 'template', 'client']),
            'canSend' => $client !== null && ($request->user()?->hasPermission('notifications.send') ?? false),
        ]);
    }

    public function show(Request $request, EmailMessage $message): Response
    {
        $this->authorize('notifications.log.view');

        return Inertia::render('admin/notifications/emails/show', [
            'message' => [
                ...$this->summary($message, $this->templateNames()),
                'cc' => $message->cc ?? [],
                'bcc' => $message->bcc ?? [],
                'from' => $message->from ?? config('mail.from.address'),
                'locale' => $message->locale,
                'body_html' => $message->body_html,
                'body_text' => $message->body_text,
                'has_redactions' => $message->has_redactions,
                'body_purged' => $message->body_purged_at !== null,
                'suppressed_reason' => $message->suppressed_reason,
                'failure_reason' => $message->failure_reason,
                'provider_message_id' => $message->provider_message_id,
                'related' => $message->related_type === null ? null : ['type' => $message->related_type, 'id' => $message->related_id],
                'queued_at' => $message->queued_at?->toIso8601String(),
            ],
            'canResend' => $this->canResend($request, $message),
            // Mail with a private link can't be resent (its copy has the link masked): say why, and where to get a new one.
            'resendBlocked' => $message->has_redactions ? __('This email contained a private link. Send the customer a new link instead.') : null,
            'newLink' => $message->has_redactions ? $this->newLinkAction($message) : null,
        ]);
    }

    public function resend(EmailMessage $message, NotificationMailer $mailer, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('notifications.log.resend');
        abort_if($message->body_purged_at !== null || ($message->body_html === null && $message->body_text === null), 422, __('The body of this email is no longer stored.'));
        abort_if($message->has_redactions, 422, __('This email contained a private link. Send the customer a new link instead.'));

        $mailer->resend($message);
        $activity->log('Email resent from the log', $message, $message->client_id, ['recipient' => $message->recipient, 'template' => $message->template_key]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email queued again.')]);

        return back();
    }

    /**
     * @return array<string, string> template key => name
     */
    private function templateNames(): array
    {
        return EmailTemplate::query()->pluck('name', 'key')->all();
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function summary(EmailMessage $message, array $names): array
    {
        return [
            'id' => $message->id,
            'uuid' => $message->uuid,
            'template_key' => $message->template_key,
            // Staff see the template's name; a deleted template keeps its key, a staff-written message is "Written by staff".
            'template_name' => $message->template_key === null ? __('Written by staff') : ($names[$message->template_key] ?? $message->template_key),
            'to' => $message->to,
            'recipient' => $message->recipient,
            'subject' => $message->subject,
            'status' => $message->status->value,
            'attempts' => $message->attempts,
            'client' => $message->client === null ? null : ['id' => $message->client->id, 'name' => $message->client->display_name],
            'created_at' => $message->created_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }

    /**
     * Where staff can give the recipient a fresh private link, if there is such a place: the ticket (Support's
     * "Copy customer link") for ticket mail. Reset and verification links are requested by the user again.
     *
     * @return array{label: string, href: string}|null
     */
    private function newLinkAction(EmailMessage $message): ?array
    {
        if ($message->related_type === 'ticket' && $message->related_id !== null && Route::has('admin.support.tickets.show')) {
            return ['label' => __('Open the ticket'), 'href' => route('admin.support.tickets.show', $message->related_id)];
        }

        return null;
    }

    private function canResend(Request $request, EmailMessage $message): bool
    {
        return ($request->user()?->hasPermission('notifications.log.resend') ?? false)
            && ! $message->has_redactions && $message->body_purged_at === null && $message->status !== EmailStatus::Queued;
    }
}
