<?php

namespace App\Http\Middleware;

use App\Contracts\Branding\BrandProvider;
use App\Contracts\Currencies\CurrencyDirectory;
use App\Data\Currencies\CurrencyDisplay;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\DateFormats;
use App\Support\Countries;
use App\Support\Localization\Catalogue;
use App\Support\Navigation\Navigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
        // Read once: the once-prop key and the catalogue must come from the same locale. SetLocale (ADR 0028 §4) has
        // to run before this middleware in the web group.
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => fn (): string => app(BrandProvider::class)->brand()->name,
            'auth' => [
                'user' => $request->user() ? [...$request->user()->toArray(), 'has_password' => $request->user()->hasPassword()] : null,
                // For the confirm dialog and page: how long a password confirmation lasts (AUTH_STEP_UP_SECONDS).
                'step_up_minutes' => intdiv(RequireRecentPasswordConfirmation::timeout(), 60),
            ],
            // Display settings per currency (prefix, suffix, grouping) so formatMoney matches server output.
            'currencyFormats' => fn (): array => array_map(fn (CurrencyDisplay $display): array => $display->toArray(), app(CurrencyDirectory::class)->displays()),
            'area' => $area = $this->area($request),
            'navigation' => fn (): array => $request->user() ? app(Navigation::class)->forUser($area, $request->user()) : [],
            'quickActions' => fn (): array => $area === 'admin' && $request->user() ? app(Navigation::class)->quickActions($request->user()) : [],
            'clientAccounts' => fn (): array => $area === 'client' && $request->user()
                ? $request->user()->clients()->get(['clients.id', 'clients.first_name', 'clients.last_name', 'clients.company_name'])
                    ->map(fn ($client): array => ['id' => $client->id, 'name' => $client->display_name])->all()
                : [],
            'currentClientId' => fn (): ?int => $request->session()->get(ClientContext::SESSION_KEY),
            'dateFormat' => fn (): string => DateFormats::current(),
            'locale' => str_replace('_', '-', $locale),
            // Sent once per locale and catalogue version, then reused by the browser across visits (ADR 0028).
            'translations' => Inertia::once(fn (): object => (object) Catalogue::messages($locale))
                ->as('i18n:'.$locale.':'.Catalogue::version($locale)),
            'countries' => Inertia::once(fn (): array => Countries::CODES),
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
