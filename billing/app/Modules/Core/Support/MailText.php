<?php

namespace App\Modules\Core\Support;

/**
 * Makes text safe to place in a Markdown mail line. Blade's {{ }} in the notification template already
 * HTML-escapes it, but the result is then parsed as Markdown, so user-controlled values (names, company,
 * notes, emails) could otherwise inject links (`[x](javascript:…)`), images or formatting. Every
 * Markdown-significant character is backslash-escaped so it renders literally.
 */
final class MailText
{
    public static function escape(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~=:])/u', '\\\\$1', $text);
    }
}
