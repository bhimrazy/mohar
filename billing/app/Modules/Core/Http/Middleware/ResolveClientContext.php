<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Support\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the client account for client-area routes ("client.area" middleware).
 */
class ResolveClientContext
{
    public function __construct(private ClientContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $clients = $user->clients()->where('status', '!=', 'closed');
        $selected = $request->session()->get(ClientContext::SESSION_KEY);
        $client = ($selected ? (clone $clients)->whereKey($selected)->first() : null) ?? $clients->orderBy('clients.id')->first(); // deterministic default: the oldest linked account

        if ($client === null) {
            return $user->is_staff ? redirect()->route('admin.dashboard') : abort(403, 'No client account is linked to this login.');
        }

        $request->session()->put(ClientContext::SESSION_KEY, $client->id);
        $this->context->set($client, $user);

        return $next($request);
    }
}
