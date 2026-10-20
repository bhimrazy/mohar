<?php

namespace App\Modules\Storefront;

use App\Events\Billing\GatewayConfigurationChanged;
use App\Events\Catalog\ProductCreated;
use App\Events\Catalog\ProductPriceChanged;
use App\Events\Catalog\ProductRetired;
use App\Events\Catalog\ProductStockDepleted;
use App\Events\Catalog\ProductUpdated;
use App\Events\Core\ExchangeRatesUpdated;
use App\Events\Core\SettingsUpdated;
use App\Events\Support\AnnouncementPublished;
use App\Events\Support\NetworkIncidentOpened;
use App\Events\Support\NetworkIncidentResolved;
use App\Events\Support\NetworkIncidentUpdated;
use App\Modules\Storefront\Http\Controllers\Public\HomeController;
use App\Modules\Storefront\Http\Controllers\Public\SitemapController;
use App\Modules\Storefront\Http\Middleware\ShareSite;
use App\Modules\Storefront\Services\ContentStore;
use App\Modules\Storefront\Support\StorefrontCache;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Seo\SitemapRegistry;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Storefront: the public home page, the shared public layout's data (header, footer), Admin → Website with draft,
 * preview and publish, the public 404 page and /sitemap.xml (docs/product/storefront-marketing.md).
 */
class StorefrontServiceProvider extends ModuleServiceProvider
{
    /** Events after which cached prices, groups, gateways or help content may be stale (spec §10). */
    private const FLUSH_ON = [
        ProductCreated::class, ProductUpdated::class, ProductRetired::class, ProductPriceChanged::class, ProductStockDepleted::class,
        ExchangeRatesUpdated::class, SettingsUpdated::class, GatewayConfigurationChanged::class,
        AnnouncementPublished::class, NetworkIncidentOpened::class, NetworkIncidentUpdated::class, NetworkIncidentResolved::class,
    ];

    public function moduleKey(): string
    {
        return 'storefront';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(StorefrontCache::class);
        $this->app->scoped(ContentStore::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ShareSite::class);

        if (! $this->app->routesAreCached()) {
            Route::get('sitemap.xml', SitemapController::class)->name('storefront.sitemap');
        }

        Event::listen(self::FLUSH_ON, function (): void {
            $this->app->make(StorefrontCache::class)->flush();
        });

        $this->app->make(SitemapRegistry::class)->register('storefront', function (): array {
            $store = $this->app->make(ContentStore::class);
            $home = $store->published()['seo']['noindex'] ? [] : [['loc' => route('home'), 'lastmod' => $store->publishedRevision()?->published_at?->toIso8601String()]];

            return [...$home, ['loc' => route('storefront.domain-pricing'), 'lastmod' => null]];
        });

        // A 404 thrown inside a public route (unknown product, article or announcement) renders the public 404 page.
        $this->callAfterResolving(ExceptionHandler::class, function (object $handler): void {
            if ($handler instanceof Handler) {
                $handler->renderable(fn (NotFoundHttpException $exception, Request $request): ?Response => $request->isMethod('GET') && ! $request->expectsJson() && ShareSite::isPublic($request) && ! HomeController::isStaffOrApiPath($request)
                    ? HomeController::notFoundResponse($request)
                    : null);
            }
        });

        $this->app->make(Navigation::class)->add('admin', new NavItem('Website', 'admin.storefront.website.edit', 'Globe', 'Setup', 75, 'storefront.content.manage', 'admin.storefront.*'));

        $this->app->make(PermissionRegistry::class)->register('Website', [
            'storefront.content.manage' => 'Edit and preview the website draft',
            'storefront.publish' => 'Publish the website',
        ], sensitive: ['storefront.publish']);
    }
}
