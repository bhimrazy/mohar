<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Notifications\Actions\SendClientEmail;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Http\Requests\SendClientEmailRequest;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\SampleContext;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual email to a client (spec §9): pick a client template or write an ad-hoc message.
 */
class ClientEmailController extends Controller
{
    public function create(Client $client, SampleContext $samples): Response
    {
        $this->authorize('notifications.send');

        return Inertia::render('admin/notifications/emails/compose', [
            'client' => ['id' => $client->id, 'name' => $client->display_name, 'email' => $client->email],
            'templates' => EmailTemplate::query()->where('audience', TemplateAudience::Client)->where('is_enabled', true)->whereNotIn('key', array_keys(SecretTemplatePolicy::ACTION_LINKS))
                ->orderBy('type')->orderBy('name')->orderBy('id')->get(['key', 'name', 'type'])
                ->map(fn (EmailTemplate $template): array => ['value' => $template->key, 'label' => $template->name.' ('.$template->type->label().')'])->values(),
            'fields' => $samples->fieldsFor(TemplateType::General),
        ]);
    }

    public function store(SendClientEmailRequest $request, Client $client, SendClientEmail $send, ActivityLogger $activity): RedirectResponse
    {
        $sent = $send->handle(
            $client,
            $request->filled('template_key') ? $request->string('template_key')->toString() : null,
            $request->filled('subject') ? $request->string('subject')->toString() : null,
            $request->filled('body') ? $request->string('body')->toString() : null,
        );
        $activity->log('Email sent to client by staff', clientId: $client->id, properties: ['template' => $request->input('template_key'), 'messages' => $sent]);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Nothing was sent.|{1} One email queued.|[2,*] :count emails queued.', $sent, ['count' => $sent])]);

        return to_route('admin.notifications.emails.index', ['client' => $client->id]);
    }
}
