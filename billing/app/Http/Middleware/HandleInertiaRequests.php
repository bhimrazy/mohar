<?php

namespace App\Http\Middleware;

use App\Contracts\Branding\BrandProvider;
use App\Modules\Core\Support\ClientContext;
use App\Support\Navigation\Navigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => fn (): string => app(BrandProvider::class)->brand()->name,
            'auth' => [
                'user' => $request->user(),
            ],
            'area' => $area = $this->area($request),
            'navigation' => fn (): array => $request->user() ? app(Navigation::class)->forUser($area, $request->user()) : [],
            'quickActions' => fn (): array => $area === 'admin' && $request->user() ? app(Navigation::class)->quickActions($request->user()) : [],
            'clientAccounts' => fn (): array => $area === 'client' && $request->user()
                ? $request->user()->clients()->get(['clients.id', 'clients.first_name', 'clients.last_name', 'clients.company_name'])
                    ->map(fn ($client): array => ['id' => $client->id, 'name' => $client->display_name])->all()
                : [],
            'currentClientId' => fn (): ?int => $request->session()->get(ClientContext::SESSION_KEY),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Which navigation area the current page belongs to.
     */
    private function area(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.') ? 'admin' : 'client';
    }
}
