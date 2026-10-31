<?php

namespace App\Modules\Orders\Boot;

use App\Contracts\Catalog\PriceBook;
use App\Modules\Core\Models\Currency;
use App\Support\Seo\SitemapRegistry;
use Illuminate\Contracts\Container\Container;

final class Sitemap
{
    public function __invoke(Container $app): void
    {
        $app->make(SitemapRegistry::class)->register('orders', function () use ($app): array {
            $urls = [
                ['loc' => route('store.index'), 'lastmod' => null],
                ['loc' => route('store.domains'), 'lastmod' => null],
            ];

            if (! $app->bound(PriceBook::class)) {
                return $urls;
            }

            $currency = Currency::default()->code;

            foreach ($app->make(PriceBook::class)->storefront($currency) as $group) {
                $urls[] = ['loc' => route('store.groups.show', $group['slug']), 'lastmod' => null];

                foreach ($group['products'] as $product) {
                    $urls[] = ['loc' => route('store.products.show', ['group' => $group['slug'], 'product' => $product['slug']]), 'lastmod' => null];
                }
            }

            return $urls;
        });
    }
}
