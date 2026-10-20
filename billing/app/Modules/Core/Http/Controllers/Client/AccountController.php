<?php

namespace App\Modules\Core\Http\Controllers\Client;

use App\Events\Core\ClientUpdated;
use App\Http\Controllers\Controller;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Http\Requests\AccountDetailsRequest;
use App\Modules\Core\Http\Resources\ClientResource;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function edit(ClientContext $context): Response
    {
        $context->authorize('profile');

        return Inertia::render('client/account/edit', [
            'client' => new ClientResource($context->client()),
        ]);
    }

    public function update(AccountDetailsRequest $request, ClientContext $context, ActivityLogger $activity): RedirectResponse
    {
        $client = $context->client();
        $client->fill($request->validated());

        // An address the client sets is theirs until a login on the client verifies it (account import).
        if ($client->isDirty('email')) {
            $client->email_source = EmailSource::Client;
        }

        $client->save();
        $activity->log('Client updated account details', $client, $client->id);
        ClientUpdated::dispatch($client->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account details saved.')]);

        return back();
    }

    /**
     * Switch the client account the user is acting for.
     */
    public function switch(Request $request): RedirectResponse
    {
        $clientId = $request->integer('client_id');
        abort_unless($request->user()?->clients()->whereKey($clientId)->exists(), 403);
        $request->session()->put(ClientContext::SESSION_KEY, $clientId);

        return to_route('client.dashboard');
    }
}
