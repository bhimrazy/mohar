<?php

/*
| Defaults for Admin → Settings → Appearance. Runtime values live in settings (themes.*).
*/
return [
    // Theme per area: preset key (calm, operator, warm, slate) or a custom theme key.
    'default_admin' => env('THEME_ADMIN', 'calm'),
    'default_client' => env('THEME_CLIENT', 'calm'),
    'default_public' => env('THEME_PUBLIC', 'calm'),

    // "theme" keeps each theme's own spacing; or compact, comfortable, spacious.
    'density' => 'theme',

    // Let staff pick their own admin theme and density (stored on the user).
    'allow_user_choice' => true,

    // Brand assets on the objects disk (served through /branding/{kind}).
    'logo_light' => null,
    'logo_dark' => null,
    'favicon' => null,
];
