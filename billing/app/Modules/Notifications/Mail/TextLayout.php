<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Support\EmailBody;

/**
 * The plain-text part, in the same block order as the HTML (design §3; samples in design/email/directions/plain-text):
 * status line, amount, title, opening, the action as "Label: url", downloads, key facts, line items, notes, footer.
 */
final class TextLayout
{
    /**
     * @param  array{title: string|null, intro: string, action: array{url: string, label: string}|null, secondary: array{url: string, label: string}|null, facts: list<array{0: string, 1: string}>, notes: string}  $parts  text blocks (EmailBody::textParts)
     * @param  string|null  $actionLine  the system button's "Label: url" line, in place of the body's own button
     */
    public static function render(array $parts, MailFrame $frame, MailFooter $footer, ?string $actionLine = null): string
    {
        $secondary = $actionLine !== null ? ($parts['action'] ?? $parts['secondary']) : $parts['secondary'];
        $primary = $actionLine ?? ($parts['action'] !== null ? $parts['action']['label'].': '.$parts['action']['url'] : null);
        $status = $frame->hasStatus() ? $footer->brand.' · '.$frame->statusLabel.(filled($frame->statusWhen) ? ' · '.$frame->statusWhen : '') : null;
        $facts = [...$parts['facts'], ...$frame->facts];

        $blocks = [
            $status,
            $frame->amount !== null ? ($frame->amountLabel ?? __('Amount')).': '.$frame->amount : null,
            $parts['title'] !== null && $parts['title'] !== '' ? $parts['title'] : null,
            $parts['intro'],
            implode("\n", array_filter([
                $primary,
                ...array_map(fn (array $link): string => $link['label'].': '.$link['url'], [...$frame->downloads, ...($secondary !== null ? [$secondary] : [])]),
            ])),
            $facts !== [] ? EmailBody::factsText($facts) : null,
            $frame->lines !== null ? self::lines($frame->lines) : null,
            $parts['notes'],
        ];

        return trim(implode("\n\n", array_filter($blocks, fn (?string $block): bool => $block !== null && trim($block) !== ''))."\n\n--\n".$footer->text());
    }

    /**
     * @param  array{heading: string, items: list<array{name: string, meta: string|null, amount: string}>, totals: list<array{label: string, amount: string, total: bool}>}  $lines
     */
    private static function lines(array $lines): string
    {
        return implode("\n", [
            $lines['heading'],
            ...array_map(fn (array $item): string => '- '.$item['name'].($item['meta'] !== null ? ' ('.$item['meta'].')' : '').': '.$item['amount'], $lines['items']),
            ...array_map(fn (array $total): string => '  '.$total['label'].': '.$total['amount'], $lines['totals']),
        ]);
    }
}
