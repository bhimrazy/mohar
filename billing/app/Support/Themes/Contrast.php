<?php

namespace App\Support\Themes;

/**
 * WCAG 2.1 contrast ratio between two #RRGGBB colours (mirrored in resources/js/lib/theme-tokens.ts).
 */
final class Contrast
{
    public static function ratio(string $foreground, string $background): float
    {
        $a = self::luminance($foreground);
        $b = self::luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(
            function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 0, 6), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
