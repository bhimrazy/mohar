<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Events\Core\ClientRegistered;
use App\Events\Core\ClientUpdated;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Http\Requests\ClientRequest;
use App\Modules\Core\Http\Resources\ClientResource;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\Settings;
use App\Support\Navigation\Navigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('core.clients.view');

        $clients = Client::query()
            ->with('group')
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/clients/index', [
            'clients' => ClientResource::collection($clients),
            'filters' => $request->only(['search', 'status']),
            'statuses' => Client::STATUSES,
            'can' => ['create' => $request->user()?->hasPermission('core.clients.create') ?? false],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('core.clients.create');

        return Inertia::render('admin/clients/form', $this->formOptions());
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = Client::query()->forceCreate([...$request->validated(), 'origin' => ClientOrigin::Staff, 'email_source' => EmailSource::Staff]);
        $this->activity->log('Client created', $client, $client->id, event: 'client.created');
        ClientRegistered::dispatch($client->id, null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client created.')]);

        return to_route('admin.clients.show', $client);
    }

    public function show(Request $request, Client $client, Navigation $navigation): Response|RedirectResponse
    {
        $this->authorize('core.clients.view');

        if ($client->merged_into_client_id !== null) {
            return to_route('admin.clients.show', $client->merged_into_client_id);
        }

        $user = $request->user();
        $can = fn (string $permission): bool => $user?->hasPermission($permission) ?? false;

        $invitations = ClientInvitation::query()->where('client_id', $client->id)->get(['user_id', 'expires_at', 'accepted_at'])->keyBy('user_id');

        return Inertia::render('admin/clients/show', [
            'client' => new ClientResource($client->load('group')),
            'contacts' => $client->contacts()->orderBy('first_name')->orderBy('id')->get(['id', 'first_name', 'last_name', 'email', 'phone', 'email_preferences']),
            'users' => $client->users()->orderByDesc('client_user.is_owner')->orderBy('users.id')->get(['users.id', 'users.name', 'users.email', 'users.last_login_at'])
                ->map(fn (User $login): array => [
                    'id' => $login->id,
                    'name' => $login->name,
                    'email' => $login->email,
                    'is_owner' => (bool) $login->getRelation('pivot')->getAttribute('is_owner'),
                    'permissions' => json_decode((string) $login->getRelation('pivot')->getAttribute('permissions'), true) ?: [],
                    'last_login_at' => $login->last_login_at?->toIso8601String(),
                    // An invitation not accepted yet: pending until it expires, then staff can send a new one.
                    'invitation' => ($invitation = $invitations->get($login->id)) === null || $invitation->accepted_at !== null ? null : [
                        'state' => $invitation->isExpired() ? 'expired' : 'pending',
                        'expires_at' => $invitation->expires_at->toIso8601String(),
                    ],
                ]),
            'links' => $navigation->clientLinks($client->id, $user),
            // Audit entries (with IPs and impersonator names) only for staff allowed to read the activity log.
            'activity' => $can('core.activity_log.view')
                ? ActivityLog::query()->where('client_id', $client->id)->with(['user:id,name', 'impersonator:id,name'])->latest('id')->limit(15)->get()
                    ->map(fn (ActivityLog $entry): array => [
                        'id' => $entry->id,
                        'description' => $entry->description,
                        'event' => $entry->event,
                        'actor' => $entry->user?->name,
                        'impersonator' => $entry->impersonator?->name,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ])
                : null,
            'areas' => ClientContext::AREAS,
            'emailTypes' => Contact::EMAIL_TYPES,
            'can' => [
                'update' => $can('core.clients.update'),
                'close' => $can('core.clients.close'),
                'merge' => $can('core.clients.merge'),
                'impersonate' => $can('core.clients.impersonate'),
                'manageContacts' => $can('core.contacts.manage'),
                'manageUsers' => $can('core.users.manage'),
                'resetTwoFactor' => $can('core.users.reset_2fa'),
                'viewActivity' => $can('core.activity_log.view'),
            ],
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorize('core.clients.update');
        abort_if($client->merged_into_client_id !== null, 404);

        return Inertia::render('admin/clients/form', [
            'client' => new ClientResource($client),
            ...$this->formOptions(),
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        abort_if($client->merged_into_client_id !== null, 404);

        // Locked, so a client-area save cannot interleave; staff vouch for an address they enter (account import).
        [$client, $original] = Client::updateDetails($client->id, $request->validated(), EmailSource::Staff);
        $changes = array_keys($client->getChanges());
        $diff = collect($changes)
            ->reject(fn (string $key): bool => $key === 'updated_at')
            ->mapWithKeys(fn (string $key): array => [$key => ['old' => $original[$key] ?? null, 'new' => $client->getAttribute($key)]])
            ->all();
        $this->activity->log('Client updated', $client, $client->id, ['changed' => array_keys($diff), 'diff' => $diff], 'client.updated');
        ClientUpdated::dispatch($client->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client updated.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code'),
            'groups' => ClientGroup::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => Client::EDITABLE_STATUSES,
            'defaultCountry' => (string) app(Settings::class)->get('core.default_country', 'US'),
        ];
    }
}
