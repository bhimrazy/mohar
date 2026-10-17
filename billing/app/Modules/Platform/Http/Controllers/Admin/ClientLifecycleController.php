<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Platform\Actions\Clients\CloseClient;
use App\Modules\Platform\Actions\Clients\MergeClients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientLifecycleController extends Controller
{
    public function close(Client $client, CloseClient $close): RedirectResponse
    {
        $this->authorize('core.clients.close');

        $close->handle($client);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client :name closed.', ['name' => $client->display_name])]);

        return to_route('admin.clients.show', $client);
    }

    public function reopen(Client $client, CloseClient $close): RedirectResponse
    {
        $this->authorize('core.clients.close');

        $close->reopen($client);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client :name reopened.', ['name' => $client->display_name])]);

        return to_route('admin.clients.show', $client);
    }

    public function mergePreview(Request $request, Client $client, MergeClients $merge): JsonResponse
    {
        $this->authorize('core.clients.merge');

        $target = $request->filled('target_id') ? Client::query()->find($request->integer('target_id')) : null;
        $problem = $merge->problem($client, $target);

        return response()->json([
            'target' => $target ? ['id' => $target->id, 'name' => $target->display_name, 'currency' => $target->currency, 'status' => $target->status] : null,
            'problem' => $problem,
            'items' => $target && ! $client->is($target) ? $merge->preview($client, $target) : [],
        ]);
    }

    public function merge(Request $request, Client $client, MergeClients $merge): RedirectResponse
    {
        $this->authorize('core.clients.merge');

        $data = $request->validate([
            'target_id' => ['required', 'integer', 'exists:clients,id'],
            'confirmation' => ['required', 'string', 'in:MERGE'],
        ], ['confirmation.in' => __('Type MERGE to confirm.')]);
        $target = Client::query()->whereKey((int) $data['target_id'])->firstOrFail();

        $merge->handle($client, $target);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client #:source merged into :target.', ['source' => $client->id, 'target' => $target->display_name])]);

        return to_route('admin.clients.show', $target);
    }
}
