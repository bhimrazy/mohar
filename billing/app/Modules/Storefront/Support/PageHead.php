<?php

namespace App\Modules\Storefront\Support;

use App\Contracts\Branding\BrandProvider;

/**
 * The document head for public pages (spec §7.1): title, meta description, canonical without query string, robots,
 * Open Graph and JSON-LD. Rendered by the Blade root so it is in the first HTML response, and mirrored by the
 * page's <Head> so client-side visits keep it current.
 */
final class PageHead
{
    /**
     * @param  array{name: string, legal_name: string|null, address: string|null, email: string|null, phone: string|null, ids: list<array{label: string, value: string}>}|null  $company  adds Organization and WebSite JSON-LD
     * @return array{title: string, description: string, canonical: string, robots: string, image: string|null, json_ld: list<array<string, mixed>>}
     */
    public static function make(string $title, string $description, string $path, bool $noindex = false, ?array $company = null): array
    {
        $brand = app(BrandProvider::class)->brand();
        $canonical = url($path);
        // The shipped default logo is the product's own mark; only a host's upload speaks for the host.
        $logo = $brand->hasUploadedLogo ? $brand->logoLight : null;
        $jsonLd = [];

        if ($company !== null) {
            $vat = collect($company['ids'])->firstWhere('label', 'VAT no.') ?? collect($company['ids'])->first();
            $jsonLd[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $company['name'],
                'legalName' => $company['legal_name'],
                'url' => $canonical,
                'logo' => $logo,
                'email' => $company['email'],
                'telephone' => $company['phone'],
                'address' => $company['address'],
                'vatID' => $vat['value'] ?? null,
            ]);
            $jsonLd[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $company['name'], 'url' => $canonical];
        }

        return [
            'title' => self::title($title, $brand->name),
            'description' => mb_substr(trim($description), 0, 160),
            'canonical' => $canonical,
            'robots' => $noindex ? 'noindex, nofollow' : 'index, follow',
            'image' => $logo,
            'json_ld' => $jsonLd,
        ];
    }

    /**
     * The same rule as the browser's title callback: the brand is appended unless the title already names it.
     */
    public static function title(string $title, string $brand): string
    {
        $title = trim($title);

        return match (true) {
            $title === '' => $brand,
            str_contains($title, $brand) => $title,
            default => "{$title} · {$brand}",
        };
    }
}
