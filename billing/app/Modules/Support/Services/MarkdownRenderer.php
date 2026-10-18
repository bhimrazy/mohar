<?php

namespace App\Modules\Support\Services;

use App\Modules\Support\Services\Markdown\ImagesAsLinksExtension;
use Illuminate\Support\Str;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

/**
 * Renders user-supplied Markdown with raw HTML stripped and unsafe links removed (spec §4.2).
 * External links get rel="noopener nofollow noreferrer"; single line breaks are kept (email-style replies);
 * images become links so user content never loads remote resources.
 */
class MarkdownRenderer
{
    public function render(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br />\n"],
            'external_link' => [
                'internal_hosts' => [(string) parse_url((string) config('app.url'), PHP_URL_HOST)],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [new ExternalLinkExtension, new ImagesAsLinksExtension]);
    }
}
