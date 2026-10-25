<?php

namespace App\Support\Reports;

use Dompdf\FontMetrics;

/**
 * Measures text the way the PDF will print it, with the font metrics of the PDF library itself (DejaVu Sans at the
 * size the report view uses), so the export can plan page breaks. Glyph widths are cached per character, so a
 * 1,000-row report asks the font a few hundred times, not once per cell. Kerning is ignored, which can make a
 * measured line a hair wider or narrower than the printed one; the page budget leaves slack for that.
 */
final class TextMeasure
{
    /** The font and size of the report view (`font-size: 9px` on the body). */
    public const FONT = 'DejaVu Sans';

    public const SIZE = 9.0;

    private ?FontMetrics $metrics = null;

    /** @var array<string, float> */
    private array $glyphs = [];

    /** @var array<string, string|null> */
    private array $fonts = [];

    /** Height of one printed line, in the same units as widths. */
    public function lineHeight(): float
    {
        $metrics = $this->metrics();

        return $metrics->getFontHeight($this->font(false), self::SIZE);
    }

    public function width(string $text, bool $bold = false): float
    {
        $total = 0.0;

        foreach (mb_str_split($text) as $char) {
            $total += $this->glyph($char, $bold);
        }

        return $total;
    }

    /**
     * The number of lines `$text` takes in a box `$width` wide: it wraps at spaces, and a word wider than the box
     * is broken wherever it overflows (`word-wrap: break-word`).
     */
    public function lines(string $text, float $width, bool $bold = false): int
    {
        $width = max($width, 1.0);
        $space = $this->glyph(' ', $bold);
        $lines = 1;
        $used = 0.0;

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $length = $this->width($word, $bold);

            if ($length > $width) {
                // Breaks mid-word: the word starts on a fresh line when the current one has text.
                if ($used > 0) {
                    $lines++;
                }

                $used = 0.0;

                foreach (mb_str_split($word) as $char) {
                    $glyph = $this->glyph($char, $bold);

                    if ($used + $glyph > $width && $used > 0) {
                        $lines++;
                        $used = 0.0;
                    }

                    $used += $glyph;
                }
            } elseif ($used === 0.0) {
                $used = $length;
            } elseif ($used + $space + $length <= $width) {
                $used += $space + $length;
            } else {
                $lines++;
                $used = $length;
            }
        }

        return $lines;
    }

    private function glyph(string $char, bool $bold): float
    {
        $key = ($bold ? 'b' : 'n').$char;

        return $this->glyphs[$key] ??= $this->metrics()->getTextWidth($char, $this->font($bold), self::SIZE);
    }

    private function font(bool $bold): string
    {
        $style = $bold ? 'bold' : 'normal';

        return $this->fonts[$style] ??= (string) $this->metrics()->getFont(self::FONT, $style);
    }

    private function metrics(): FontMetrics
    {
        return $this->metrics ??= app('dompdf')->getFontMetrics();
    }
}
