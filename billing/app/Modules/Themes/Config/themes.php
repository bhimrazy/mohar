<?php

/*
| Defaults for Admin → Settings → Appearance. Runtime values live in settings (themes.*).
*/
return [
    // Theme per area: preset key (calm, operator, warm, slate) or a custom theme key.
    'default_admin' => 'calm',
    'default_client' => 'calm',
    'default_public' => 'calm',

    // "theme" keeps each theme's own spacing; or compact, comfortable, spacious.
    'density' => 'theme',

    // Let staff pick their own admin theme and density (stored on the user).
    'allow_user_choice' => true,

    // Brand: the display name is the core.company_name setting (falls back to config('app.name')).
    'tagline' => 'Web hosting billing, sealed and settled.',

    // Uploaded overrides on the objects disk (served through /branding/{kind}); null = default.
    'logo_light' => null,
    'logo_dark' => null,
    'mark' => null,
    'favicon' => null,

    // Default brand assets shipped in public/.
    'defaults' => [
        'logo-light' => 'brand/logo.svg',
        'logo-dark' => 'brand/logo-dark.svg',
        'mark' => 'brand/mark.svg',
        'favicon' => 'favicon.svg',
        'logo-email' => 'brand/logo.png',
    ],
];
