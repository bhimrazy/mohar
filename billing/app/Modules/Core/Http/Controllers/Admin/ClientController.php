<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Events\Core\ClientRegistered;
use App\Events\Core\ClientUpdated;
use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\ClientRequest;
use App\Modules\Core\Http\Resources\ClientResource;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
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
        ]);
    }

    public function create(): Response
    {
        $this->authorize('core.clients.manage');

        return Inertia::render('admin/clients/form', $this->formOptions());
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = Client::query()->create($request->validated());
        $this->activity->log('Client created', $client, $client->id);
        ClientRegistered::dispatch($client->id, null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client created.')]);

        return to_route('admin.clients.show', $client);
    }

    public function show(Client $client, Navigation $navigation): Response
    {
        $this->authorize('core.clients.view');

        return Inertia::render('admin/clients/show', [
            'client' => new ClientResource($client->load('group')),
            'contacts' => $client->contacts()->get(['id', 'first_name', 'last_name', 'email', 'phone', 'email_preferences']),
            'users' => $client->users()->get(['users.id', 'users.name', 'users.email'])->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_owner' => (bool) $user->getRelation('pivot')->getAttribute('is_owner'),
            ]),
            'links' => $navigation->clientLinks($client->id, request()->user()),
            'activity' => ActivityLog::query()->where('client_id', $client->id)->with('user:id,name')->latest('id')->limit(15)->get(),
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorize('core.clients.manage');

        return Inertia::render('admin/clients/form', [
            'client' => new ClientResource($client),
            ...$this->formOptions(),
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());
        $this->activity->log('Client updated', $client, $client->id, ['changed' => array_keys($client->getChanges())]);
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
            'currencies' => Currency::query()->orderByDesc('is_default')->pluck('code'),
            'groups' => ClientGroup::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => Client::STATUSES,
        ];
    }
}
