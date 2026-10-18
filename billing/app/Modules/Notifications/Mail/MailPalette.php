<?php

namespace App\Modules\Notifications\Mail;

use App\Contracts\Branding\BrandProvider;

/**
 * Email colours (design/email/email-design.md §5, §8): one token set for light and dark, from which the layout's
 * inline styles and its dark rules (prefers-color-scheme, Outlook.com's [data-ogsc]/[data-ogsb]) are generated.
 * The accent follows the client theme when its email pairs pass WCAG AA (button label on accent, link on card,
 * button against the dark card); otherwise the default accent is used.
 */
final class MailPalette
{
    /** The card's maximum width in px (§4: direction A, about 74 characters per line). */
    public const WIDTH = 640;

    public const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

    public const TONES = ['accent', 'success', 'warning', 'danger', 'info', 'muted'];

    private const LIGHT = [
        'frame' => '#EFEEEA', 'card' => '#FFFFFF', 'panel' => '#F7F8FB', 'border' => '#E4E7EE', 'strong' => '#1A2133',
        'body' => '#323A4E', 'muted' => '#5B6478', 'link' => '#4F4BC4', 'accent' => '#4F4BC4', 'onAccent' => '#FFFFFF', 'code' => '#F1F2F6',
    ];

    private const DARK = [
        'frame' => '#0F121A', 'card' => '#181C26', 'panel' => '#1E2330', 'border' => '#2A3040', 'strong' => '#EEF0F5',
        'body' => '#C9CEDA', 'muted' => '#9AA3B5', 'link' => '#A9ACF0', 'accent' => '#8B8FEA', 'onAccent' => '#12142A', 'code' => '#12151D',
    ];

    /** tone => [dot, text, subtle] */
    private const TONE_LIGHT = [
        'accent' => ['#4F4BC4', '#3F3BA8', '#EEEDFB'], 'success' => ['#3D9A6E', '#23704D', '#EAF6F0'],
        'warning' => ['#D6A03A', '#875A0E', '#FBF3E2'], 'danger' => ['#CF5A55', '#A6332F', '#FBEDEC'],
        'info' => ['#4B8BC9', '#2B6297', '#EAF2FA'], 'muted' => ['#8A93A6', '#5B6478', '#F1F2F6'],
    ];

    private const TONE_DARK = [
        'accent' => ['#8B8FEA', '#B3B5F3', '#23244A'], 'success' => ['#5BBF8E', '#7FD1A8', '#172A22'],
        'warning' => ['#E2B154', '#EBC27A', '#2D2619'], 'danger' => ['#E07D78', '#F09C97', '#32191B'],
        'info' => ['#6FA8E0', '#94BFEA', '#17253A'], 'muted' => ['#6B7385', '#9AA3B5', '#232836'],
    ];

    /** @param array<string, string> $light @param array<string, string> $dark */
    private function __construct(public readonly array $light, public readonly array $dark) {}

    public static function current(): self
    {
        $brand = app(BrandProvider::class)->brand();

        return self::forAccent($brand->accent, $brand->accentForeground);
    }

    /**
     * The palette with a theme accent, when it passes AA for every email pair; otherwise the default.
     */
    public static function forAccent(?string $accent, ?string $onAccent): self
    {
        $light = self::LIGHT;
        $dark = self::DARK;

        if (self::isHex($accent) && strcasecmp((string) $accent, self::LIGHT['accent']) !== 0) {
            $onAccent = self::isHex($onAccent) ? (string) $onAccent : '#FFFFFF';
            $darkAccent = self::lightenUntil((string) $accent, self::DARK['card'], 4.5);

            if ($darkAccent !== null && self::contrast($onAccent, (string) $accent) >= 4.5 && self::contrast((string) $accent, self::LIGHT['card']) >= 4.5
                && self::contrast(self::DARK['onAccent'], $darkAccent) >= 4.5) {
                $light = [...$light, 'accent' => strtoupper((string) $accent), 'link' => strtoupper((string) $accent), 'onAccent' => strtoupper($onAccent)];
                $dark = [...$dark, 'accent' => $darkAccent, 'link' => $darkAccent];
            }
        }

        return new self($light, $dark);
    }

    /**
     * @return array{dot: string, text: string, subtle: string}
     */
    public function tone(string $tone, bool $dark = false): array
    {
        $tone = in_array($tone, self::TONES, true) ? $tone : 'accent';
        [$dot, $text, $subtle] = ($dark ? self::TONE_DARK : self::TONE_LIGHT)[$tone];

        if ($tone === 'accent') {
            $dot = $dark ? $this->dark['accent'] : $this->light['accent'];
        }

        return ['dot' => $dot, 'text' => $text, 'subtle' => $subtle];
    }

    /**
     * The dark rules: one list, emitted for prefers-color-scheme and for Outlook.com's colour attributes.
     */
    public function darkCss(): string
    {
        $d = $this->dark;
        $rules = [
            '.bg-frame' => ['background-color' => $d['frame']], '.bg-card' => ['background-color' => $d['card']],
            '.bg-panel' => ['background-color' => $d['panel']], '.bg-code' => ['background-color' => $d['code']],
            '.tx-strong,.prose h1,.prose h2,.prose h3,.prose strong' => ['color' => $d['strong']],
            '.tx-body,.prose p,.prose li,.prose td,.prose blockquote' => ['color' => $d['body']],
            '.tx-muted' => ['color' => $d['muted']], '.link,.prose a' => ['color' => $d['link']], '.bd,.prose hr' => ['border-color' => $d['border']],
            '.btn-td' => ['background-color' => $d['accent']],
            '.button' => ['background-color' => $d['accent'], 'border-color' => $d['accent'], 'color' => $d['onAccent']],
        ];

        foreach (self::TONES as $tone) {
            $colours = $this->tone($tone, true);
            $rules[".tx-{$tone}"] = ['color' => $colours['text']];
            $rules[".dotc-{$tone}"] = ['color' => $colours['dot']];
        }

        $declarations = fn (array $properties): string => implode(';', array_map(fn (string $property, string $value): string => "{$property}:{$value} !important", array_keys($properties), $properties));
        $media = $ogsc = $ogsb = [];

        foreach ($rules as $selector => $properties) {
            $media[] = "{$selector}{{$declarations($properties)}}";
            $scoped = implode(',', array_map(fn (string $part): string => '[data-ogsc] '.$part, explode(',', $selector)));
            $text = array_diff_key($properties, ['background-color' => true]);

            if ($text !== []) {
                $ogsc[] = "{$scoped}{{$declarations($text)}}";
            }

            if (isset($properties['background-color'])) {
                $ogsb[] = implode(',', array_map(fn (string $part): string => '[data-ogsb] '.$part, explode(',', $selector)))."{background-color:{$properties['background-color']} !important}";
            }
        }

        $swap = '.logo-l{display:none !important}.logo-d{display:block !important;max-height:none !important;overflow:visible !important}';

        return "@media (prefers-color-scheme: dark){\n".implode("\n", $media)."\n{$swap}\n}\n".implode("\n", $ogsc)."\n".implode("\n", $ogsb)
            ."\n[data-ogsc] .logo-l{display:none !important}[data-ogsc] .logo-d{display:block !important;max-height:none !important;overflow:visible !important}";
    }

    /**
     * WCAG 2.1 contrast ratio of two #RRGGBB colours.
     */
    public static function contrast(string $foreground, string $background): float
    {
        [$a, $b] = [self::luminance($foreground), self::luminance($background)];

        return round((max($a, $b) + 0.05) / (min($a, $b) + 0.05), 2);
    }

    private static function luminance(string $hex): float
    {
        $channel = function (int $value): float {
            $value /= 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channel((int) $r) + 0.7152 * $channel((int) $g) + 0.0722 * $channel((int) $b);
    }

    /**
     * The accent mixed with white in steps until it reads on the dark card; null when it never does.
     */
    private static function lightenUntil(string $hex, string $background, float $ratio): ?string
    {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

        for ($step = 0; $step <= 10; $step++) {
            $mix = $step / 10;
            $candidate = sprintf('#%02X%02X%02X', (int) round($r + (255 - $r) * $mix), (int) round($g + (255 - $g) * $mix), (int) round($b + (255 - $b) * $mix));

            if (self::contrast($candidate, $background) >= $ratio) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isHex(?string $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
