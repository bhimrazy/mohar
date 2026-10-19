<?php

namespace App\Modules\Storefront\Http\Controllers\Public;

use App\Contracts\Branding\BrandProvider;
use App\Contracts\Orders\ShopperSession;
use App\Http\Controllers\Controller;
use App\Modules\Storefront\Services\Catalogue;
use App\Modules\Storefront\Services\ContentStore;
use App\Modules\Storefront\Services\HomePage;
use App\Modules\Storefront\Services\PriceDisplay;
use App\Modules\Storefront\Services\SiteData;
use App\Modules\Storefront\Support\PageHead;
use App\Modules\Storefront\Support\StorefrontCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Public pages owned by the Storefront: home, domain prices and the 404 page. Every one
 * renders in the shared public layout.
 */
class HomeController extends Controller
{
    public function __construct(
        private ContentStore $store,
        private HomePage $home,
        private ShopperSession $shopper,
        private SiteData $site,
    ) {}

    public function show(Request $request): Response
    {
        return $this->render($request, $this->store->published());
    }

    public function domainPricing(Request $request, Catalogue $catalogue, PriceDisplay $prices, StorefrontCache $cache): Response
    {
        $currency = $this->shopper->currency($request);
        $display = $prices->forCurrency($currency, $this->store->published());
        $head = PageHead::make('Domain prices', 'Register, renew and transfer prices for every domain extension we sell.', $request->path());

        return Inertia::render('public/storefront/domain-pricing', [
            'domains' => $cache->remember("domains.{$currency}", fn (): array => $catalogue->domains($currency, $display)),
            'currency' => $currency,
            'tax_note' => $catalogue->domainsTaxed() ? $display['note'] : null,
            'search_url' => Route::has('store.domains') ? route('store.domains') : null,
            'seo' => $head,
        ])->withViewData(['seo' => $head]);
    }

    /**
     * Unknown public URLs: the public layout with HTTP 404 and noindex (spec §3).
     */
    public function notFound(Request $request): HttpResponse
    {
        // The GET fallback would otherwise hide routes that exist for other methods: keep their 405.
        $allowed = array_values(array_filter(['POST', 'PUT', 'PATCH', 'DELETE'], function (string $method) use ($request): bool {
            try {
                return ! Route::getRoutes()->match(Request::create($request->getUri(), $method))->isFallback;
            } catch (HttpExceptionInterface) {
                return false;
            }
        }));

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed);
        }

        abort_if(self::isStaffOrApiPath($request), 404);

        return self::notFoundResponse($request);
    }

    /**
     * Admin, client-area and API URLs keep the framework's 404, never the public page.
     */
    public static function isStaffOrApiPath(Request $request): bool
    {
        return $request->is('admin', 'admin/*', 'client', 'client/*', 'api/*');
    }

    public static function notFoundResponse(Request $request): HttpResponse
    {
        $head = PageHead::make('Page not found', 'The page you asked for is not here.', $request->path(), noindex: true);

        return Inertia::render('public/storefront/not-found', [
            'path' => '/'.ltrim($request->path(), '/'),
            'links' => array_values(array_filter([
                ['label' => 'Hosting plans', 'url' => Route::has('store.index') ? route('store.index') : null],
                ['label' => 'Knowledge base', 'url' => Route::has('support.kb.index') ? route('support.kb.index') : null],
                ['label' => 'Home', 'url' => route('home')],
            ], fn (array $link): bool => $link['url'] !== null)),
            'search_url' => Route::has('store.domains') ? route('store.domains') : null,
            'seo' => $head,
        ])->withViewData(['seo' => $head])->toResponse($request)->setStatusCode(404);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function render(Request $request, array $content): Response
    {
        $props = $this->home->props($content, $this->shopper->currency($request), $request->user() !== null);
        $title = trim((string) $content['seo']['title']);
        $head = PageHead::make(
            $title !== '' ? $title : app(BrandProvider::class)->brand()->name.' | '.HomePage::headline($content),
            trim((string) $content['seo']['description']) !== '' ? $content['seo']['description'] : ($content['hero']['subtext'] ?: HomePage::headline($content)),
            '/',
            noindex: $content['seo']['noindex'],
            company: $this->site->company(),
        );

        return Inertia::render('public/storefront/home', [...$props, 'seo' => $head])->withViewData(['seo' => $head]);
    }
}
