<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Admin Panel Theme
    |--------------------------------------------------------------------------
    |
    | Applied when an admin has not saved a custom theme yet. Each admin's
    | overrides are stored per-user in user_settings.settings['theme'].
    |
    | `defaults` is the *light* palette and must stay colors-only — its keys
    | drive the hex validation in ThemeUpdateRequest. The color scheme is a
    | separate key (`mode`) because it is not a color value.
    |
    */

    'defaults' => [
        'sidebar_bg' => '#0f172a',
        'sidebar_hover' => '#1e293b',
        'active_menu' => '#6366f1',
        'sidebar_text' => '#cbd5e1',
        'dashboard_bg' => '#f1f5f9',
        'header_bg' => '#ffffff',
        'header_text' => '#0f172a',
        'card_bg' => '#ffffff',
        'accent' => '#6366f1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Color scheme
    |--------------------------------------------------------------------------
    |
    | `light` and `dark` force the scheme; `system` follows the OS
    | (prefers-color-scheme). In dark mode the structural surfaces come from
    | `dark` below while `accent` / `active_menu` keep the admin's own colors,
    | so a brand accent is never lost.
    |
    */

    'modes' => ['light', 'dark', 'system'],

    'default_mode' => 'system',

    'dark' => [
        'sidebar_bg' => '#0b0f1a',
        'sidebar_hover' => '#1b2333',
        'sidebar_text' => '#cbd5e1',
        'dashboard_bg' => '#0f1420',
        'header_bg' => '#151a26',
        'header_text' => '#e8eaf0',
        'card_bg' => '#171d2b',
    ],
];
