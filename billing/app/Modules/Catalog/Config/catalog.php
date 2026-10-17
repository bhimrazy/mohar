<?php

return [
    /*
    | Defaults for new products (editable in Settings → Catalog).
    */
    'default_auto_setup' => 'on_payment',
    'default_taxable' => true,

    /*
    | When enabled, a daily automation task regenerates non-default-currency price rows from the default
    | currency × exchange rate (overwriting manual prices in other currencies).
    */
    'auto_convert_prices' => false,

    /*
    | Store shows longer cycles as a "/mo" equivalent.
    */
    'show_monthly_breakdown' => false,
];
