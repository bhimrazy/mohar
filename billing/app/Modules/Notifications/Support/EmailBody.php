<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Mail\MailPalette;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Mail\Markdown;

/**
 * Turns a rendered template body into the blocks of the branded layout (design §3), by the template conventions:
 *  - "# Heading" is the title; the paragraphs before the key facts (or the button) are the opening, card 1;
 *  - the first table is the key-facts block (label | value rows);
 *  - a line of the template's own text that holds only a link is the primary button, the next one the secondary
 *    link. Only the template source declares them (markActions() tags those links with a per-render token before
 *    merge fields are filled in), so a link in a client's message or any merged value never becomes a button;
 *  - what follows the facts or the button is the record's notes, card 2.
 * The plain-text part is written text built from the same blocks: lists as "- ", links as "text (url)", facts as
 * aligned "Label  value" rows, never Markdown or HTML.
 */
final class EmailBody
{
    private const BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'table', 'tr', 'hr'];

    public static function html(string $markdown): string
    {
        // The layout prints the parts as they are; without blank lines nothing reads them as Markdown again.
        return (string) preg_replace("/\n{2,}/", "\n", Markdown::parse($markdown)->toHtml());
    }

    /**
     * @param  string|null  $actionToken  the token markActions() put on the template's own button links; null = none
     * @param  bool  $actionTables  framework mail only: its <x-mail::button> table is the button
     * @return array{title: string|null, intro: string, action: array{url: string, label: string}|null, secondary: array{url: string, label: string}|null, facts: list<array{0: string, 1: string}>, notes: string}
     */
    public static function parts(string $html, ?string $actionToken = null, bool $actionTables = false): array
    {
        $document = self::document($html);
        $nodes = array_values(array_filter(
            iterator_to_array($document->body->childNodes),
            fn (Node $node): bool => $node instanceof Element || ($node instanceof Text && trim((string) $node->textContent) !== ''),
        ));
        $title = $action = $secondary = $factsAt = $actionAt = null;
        $facts = $taken = $intro = $notes = [];

        foreach ($nodes as $index => $node) {
            $name = $node instanceof Element ? strtolower($node->localName) : '#text';

            if ($name === 'h1' && $title === null) {
                $title = self::inline((string) $node->textContent);
            } elseif ($secondary === null && ($link = self::buttonLink($node, $actionToken, $actionTables)) !== null) {
                $action === null ? $action = $link : $secondary = $link;
                $actionAt ??= $index;
            } elseif ($node instanceof Element && $name === 'table' && $facts === [] && ($rows = self::factRows($node)) !== []) {
                $facts = $rows;
                $factsAt = $index;
            } else {
                continue;
            }

            $taken[$index] = true;
        }

        // Card 1 holds what comes before the key facts (or before the button when there are none); card 2 the rest.
        $split = $factsAt ?? $actionAt ?? PHP_INT_MAX;

        foreach ($nodes as $index => $node) {
            if (! isset($taken[$index])) {
                $markup = $node instanceof Element ? $document->saveHtml($node) : e((string) $node->textContent);
                $index < $split ? $intro[] = $markup : $notes[] = $markup;
            }
        }

        // A marked link that is not a button (a third one, or inside other text) is an ordinary link.
        $unmark = fn (string $markup): string => $actionToken === null ? $markup : str_replace('#'.$actionToken, '', $markup);

        return [
            'title' => $title,
            'intro' => $unmark(implode("\n", $intro)),
            'action' => $action,
            'secondary' => $secondary,
            'facts' => array_map(fn (array $row): array => [$unmark($row[0]), $unmark($row[1])], $facts),
            'notes' => $unmark(implode("\n", $notes)),
        ];
    }

    /**
     * The text part's blocks, from the same rendered (escaped, and for guest mail sanitised) HTML as the HTML part:
     * merge values stay literal text, and a link the HTML dropped is dropped here too, keeping its text.
     *
     * @return array{title: string|null, intro: string, action: array{url: string, label: string}|null, secondary: array{url: string, label: string}|null, facts: list<array{0: string, 1: string}>, notes: string}
     */
    public static function textParts(string $html, ?string $actionToken = null): array
    {
        $parts = self::parts($html, $actionToken);

        return [
            ...$parts,
            'intro' => self::toText($parts['intro']),
            'notes' => self::toText($parts['notes']),
            'facts' => array_map(fn (array $row): array => [self::toText($row[0]), self::toText($row[1])], $parts['facts']),
        ];
    }

    /**
     * Tags the links on the template's own action lines (a line that, apart from {% %} tags, holds only Markdown
     * links) with the per-render token, before any merge field is filled in. Only these can become buttons.
     */
    public static function markActions(string $source, string $token): string
    {
        // A Markdown link whose target may hold merge fields ({{ invoice.url }}).
        $target = '(?:\{\{.*?\}\}|[^)\s{])*';
        $link = '\[[^\]\n]*\]\('.$target.'\)';

        return (string) preg_replace_callback('/^.*$/m', function (array $line) use ($link, $target, $token): string {
            $bare = trim((string) preg_replace('/\{%.*?%\}/', '', $line[0]));

            if ($bare === '' || preg_match('/^(?:'.$link.'\s*)+$/', $bare) !== 1) {
                return $line[0];
            }

            return (string) preg_replace('/(\[[^\]\n]*\]\('.$target.')\)/', '$1#'.$token.')', $line[0]);
        }, $source);
    }

    /**
     * HTML to readable text: paragraphs separated by a blank line, list items as "- ", links as "text (url)",
     * preformatted text indented, everything else reduced to its words.
     */
    public static function toText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = self::document($html);
        $text = $document->body === null ? '' : self::walk($document->body);
        $text = (string) preg_replace(["/[ \t]+\n/", "/\n (?=\S)/", "/\n{3,}/"], ["\n", "\n", "\n\n"], $text);

        return trim($text, "\n ");
    }

    public static function preheader(string $html): ?string
    {
        $parts = self::parts($html);
        $text = trim((string) preg_replace('/\s+/', ' ', self::toText($parts['intro'])));

        return $text === '' ? null : mb_strimwidth($text, 0, 140, '…');
    }

    /**
     * The facts as aligned "Label  value" rows for the text part.
     *
     * @param  list<array{0: string, 1: string}>  $rows
     */
    public static function factsText(array $rows): string
    {
        $width = max([0, ...array_map(fn (array $row): int => mb_strlen($row[0]), $rows)]) + 2;

        return implode("\n", array_map(fn (array $row): string => rtrim(($row[0] === '' ? '' : str_pad($row[0], $width + strlen($row[0]) - mb_strlen($row[0]))).str_replace("\n", ', ', $row[1])), $rows));
    }

    public static function button(string $url, string $label): string
    {
        $palette = MailPalette::current();

        return view('notifications::mail.button', [
            'url' => $url,
            'label' => $label,
            'background' => $palette->light['accent'],
            'foreground' => $palette->light['onAccent'],
        ])->render();
    }

    private static function document(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NOERROR);
    }

    /**
     * A paragraph holding only one link that the template declared as its action (marked with the token), or, in
     * framework mail, its button table.
     *
     * @return array{url: string, label: string}|null
     */
    private static function buttonLink(Node $node, ?string $actionToken, bool $actionTables): ?array
    {
        if (! $node instanceof Element) {
            return null;
        }

        $name = strtolower($node->localName);
        $link = null;

        if ($name === 'table' && $actionTables && in_array('action', preg_split('/\s+/', (string) $node->getAttribute('class')) ?: [], true)) {
            $link = $node->querySelector('a');
        } elseif ($name === 'p' && $actionToken !== null) {
            $elements = array_values(array_filter(iterator_to_array($node->childNodes), fn (Node $child): bool => $child instanceof Element || trim((string) $child->textContent) !== ''));
            $link = count($elements) === 1 && $elements[0] instanceof Element && strtolower($elements[0]->localName) === 'a' ? $elements[0] : null;
        }

        $url = trim((string) $link?->getAttribute('href'));

        if ($link === null || $url === '') {
            return null;
        }

        if (! $actionTables || $name === 'p') {
            if (! str_ends_with($url, '#'.$actionToken)) {
                return null;
            }

            $url = substr($url, 0, -strlen('#'.$actionToken));
        }

        return $url === '' ? null : ['url' => $url, 'label' => self::inline((string) $link->textContent)];
    }

    /**
     * Label/value rows of a key-facts table (two cells per row).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function factRows(Element $table): array
    {
        $rows = [];

        foreach ($table->querySelectorAll('tr') as $row) {
            $cells = array_values(array_filter(iterator_to_array($row->childNodes), fn (Node $cell): bool => $cell instanceof Element));
            $html = array_map(fn (Element $cell): string => trim((string) $cell->innerHTML), $cells);

            // A fact without a value (an optional field that is empty) is left out.
            if (trim(strip_tags($html[count($html) - 1] ?? '')) === '') {
                continue;
            }

            $rows[] = count($html) >= 2 ? [$html[0], implode(' · ', array_slice($html, 1))] : ['', $html[0] ?? ''];
        }

        return $rows;
    }

    private static function walk(Node $node, string $listMarker = '- '): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $out .= (string) preg_replace('/\s+/u', ' ', (string) $child->textContent);

                continue;
            }

            if (! $child instanceof Element) {
                continue;
            }

            $name = strtolower($child->localName);
            $inner = fn (string $marker = '- '): string => self::walk($child, $marker);

            $out .= match ($name) {
                'br' => "\n",
                'a' => self::link($inner(), (string) $child->getAttribute('href')),
                'img' => (string) $child->getAttribute('alt'),
                'li' => $listMarker.trim($inner())."\n",
                'ul' => "\n\n".trim($inner('- '), ' ')."\n",
                'ol' => "\n\n".trim($inner('1. '), ' ')."\n",
                'pre' => "\n\n".implode("\n", array_map(fn (string $line): string => '    '.$line, explode("\n", rtrim((string) $child->textContent))))."\n\n",
                'hr' => "\n\n",
                'td', 'th' => trim($inner()).'  ',
                'script', 'style', 'head', 'title' => '',
                default => in_array($name, self::BLOCKS, true) ? "\n\n".trim($inner())."\n\n" : $inner(),
            };
        }

        return $out;
    }

    private static function link(string $text, string $href): string
    {
        $text = trim($text);
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || $text === $href || 'mailto:'.$text === $href) {
            return $text;
        }

        return $text === '' ? $href : "{$text} ({$href})";
    }

    private static function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
