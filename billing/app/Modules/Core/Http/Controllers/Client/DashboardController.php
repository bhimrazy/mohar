<?php

namespace App\Modules\Core\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Resources\ClientResource;
use App\Modules\Core\Support\ClientContext;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ClientContext $context, Dashboard $dashboard): Response
    {
        return Inertia::render('client/dashboard', [
            'client' => new ClientResource($context->client()),
            'cards' => $dashboard->resolve('client', $request->user(), $context->client()),
        ]);
    }
}
