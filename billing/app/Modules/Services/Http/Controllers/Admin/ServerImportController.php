<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin → Servers → server → Import accounts (cPanel spec §10): fetch what the server holds, matched once against
 * clients, products and services here. Read-only on both sides; importing is a separate, confirmed step.
 */
class ServerImportController extends Controller
{
    /**
     * Start reading the server's accounts on the queue (FetchAccountsForImport).
     */
    public function fetch(Request $request, Server $server, FetchAccountsForImport $fetch): RedirectResponse
    {
        $this->authorize('services.servers.import');

        $fetch->handle($server, $request->user());
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Reading the accounts on :server. The list appears when it is ready.', ['server' => $server->name])]);

        return back();
    }
}
