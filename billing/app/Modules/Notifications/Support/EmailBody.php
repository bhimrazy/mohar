<?php

namespace App\Modules\Notifications\Support;

use App\Contracts\Branding\BrandProvider;
use Illuminate\Mail\Markdown;

/**
 * Turns a rendered template body (Markdown) into the parts of a branded email:
 *  - HTML: Markdown tables become the key-facts block, the first paragraph holding only a link becomes
 *    the primary button (brand accent), "# Heading" + first paragraph read as title + summary;
 *  - preheader: the summary line shown in inbox previews;
 *  - plain text: the same content without Markdown syntax (tables as "Label: value", links as "Label: url").
 */
final class EmailBody
{
    public static function html(string $markdown): string
    {
        $html = Markdown::parse($markdown)->toHtml();
        $html = (string) preg_replace('/<table>/', '<table class="facts" cellpadding="0" cellspacing="0">', $html);
        $html = (string) preg_replace_callback(
            '#<p>\s*<a href="([^"]+)">([^<]+)</a>\s*</p>#',
            fn (array $match): string => self::button(html_entity_decode($match[1]), html_entity_decode($match[2])),
            $html,
            1,
        );

        // The layout parses its slot as Markdown again; without blank lines the body stays one raw HTML block.
        return (string) preg_replace("/\n{2,}/", "\n", $html);
    }

    public static function preheader(string $html): ?string
    {
        $afterHeading = str_contains($html, '</h1>') ? substr($html, (int) strpos($html, '</h1>')) : $html;

        if (! preg_match('#<p>(.*?)</p>#s', $afterHeading, $match)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[1]))));

        return $text === '' ? null : mb_strimwidth($text, 0, 140, '…');
    }

    public static function text(string $markdown): string
    {
        $lines = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*\|?\s*:?-{3,}/', $line)) {
                continue;
            }

            if (preg_match('/^\s*\|(.+)\|\s*$/', $line, $match)) {
                $cells = array_map('trim', explode('|', $match[1]));
                $line = count($cells) === 2 ? "{$cells[0]}: {$cells[1]}" : implode(' · ', $cells);
            }

            $line = (string) preg_replace('/^#{1,6}\s+/', '', $line);
            $line = (string) preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1: $2', $line);
            $lines[] = (string) preg_replace('/(\*\*|__)(.+?)\1/', '$2', $line);
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    private static function button(string $url, string $label): string
    {
        $brand = app(BrandProvider::class)->brand();

        return view('notifications::mail.button', [
            'url' => $url,
            'label' => $label,
            'background' => $brand->accent ?? '#4f4bc4',
            'foreground' => $brand->accentForeground ?? '#ffffff',
        ])->render();
    }
}
