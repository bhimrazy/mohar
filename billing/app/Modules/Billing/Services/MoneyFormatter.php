<?php

namespace App\Modules\Billing\Services;

use App\Data\Currencies\CurrencyDisplay;
use App\Support\Currencies\MoneyFormatter as CurrencyFormatter;

/**
 * Money on invoice and quote PDFs: the currency's configured display (Admin → Currencies), the same as
 * pages and mail. The PDF font (DejaVu Sans) has no Devanagari, so a prefix or suffix in that script
 * (e.g. a customised "रू ") prints as the ISO code instead of empty boxes.
 */
class MoneyFormatter
{
    public function __construct(private CurrencyFormatter $formatter) {}

    public function format(int $amount, string $currency): string
    {
        $display = $this->formatter->display($currency);

        if (preg_match('/\p{Devanagari}/u', $display->prefix.$display->suffix) === 1) {
            $display = new CurrencyDisplay($display->code, $display->code.' ', '', $display->decimals, $display->format);
        }

        return CurrencyFormatter::render($amount, $display);
    }
}
