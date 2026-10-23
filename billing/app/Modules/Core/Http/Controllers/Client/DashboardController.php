<?php

namespace App\Modules\Core\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Resources\ClientResource;
use App\Modules\Core\Support\ClientContext;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client home from the kernel dashboard registry: `notices` (banners such as an invoice to pay), `cards` (counters)
 * and `panels` (list widgets). Each module registers its own and hides them from users without its area, so a
 * module that is not installed or not permitted simply contributes nothing.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ClientContext $context, Dashboard $dashboard): Response
    {
        $widgets = collect($dashboard->resolveWidgets('client', $request->user(), null, $context->client()))->groupBy('type');

        return Inertia::render('client/dashboard', [
            'client' => new ClientResource($context->client()),
            'notices' => $widgets->get('notice', collect())->values(),
            'cards' => $widgets->get('stat', collect())->values(),
            'panels' => $widgets->get('list', collect())->values(),
        ]);
    }
}
