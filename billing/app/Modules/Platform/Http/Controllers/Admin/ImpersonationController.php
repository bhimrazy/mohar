<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Platform\Models\ImpersonationSession;
use App\Modules\Platform\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationController extends Controller
{
    /**
     * Record the request and hand back a short-lived signed link that performs the switch.
     */
    public function start(Request $request, Client $client, Impersonation $impersonation): Response
    {
        $this->authorize('core.clients.impersonate');

        $staff = $request->user();
        abort_if($staff === null, 401);

        $data = $request->validate(['user_id' => ['nullable', 'integer']]);
        $url = $impersonation->start($staff, $client, isset($data['user_id']) ? (int) $data['user_id'] : null, $request);

        return Inertia::location($url);
    }

    public function enter(Request $request, ImpersonationSession $impersonation, Impersonation $service): RedirectResponse
    {
        $this->authorize('core.clients.impersonate');

        $service->enter($impersonation, $request);

        return to_route('client.dashboard');
    }

    public function leave(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $clientId = $impersonation->end($request);

        if ($clientId === null) {
            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You are back in the admin area.')]);

        return to_route('admin.clients.show', $clientId);
    }
}
