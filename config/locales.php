<?php

return [
    /*
     * The master language catalog (i18n Phase 0). `supported` keys are the
     * only values `PUT /api/language`, `tenants.locale`, and the `locale`
     * middleware ever accept — anything else falls back to `default`.
     *
     * `complete` gates what the switcher offers and what the API persists:
     * a dictionary still under translation stays out of the product until a
     * native review signs it off. `intl` is the BCP 47 tag handed to the
     * SPA's `Intl` formatters; `dir` reserves the RTL axis (no RTL language
     * is in the pipeline, but new CSS should prefer logical properties).
     *
     * This is the product catalog — the SPA dictionaries, the backend
     * messages, the public site chrome and the notification/email copy all
     * resolve their language from here (`lang/<locale>/` folders mirror it).
     */
    'default' => env('APP_LOCALE', 'en'),

    'supported' => [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'dir' => 'ltr',
            'intl' => 'en-US',
            'complete' => true,
        ],
        'es' => [
            'name' => 'Spanish',
            'native' => 'Español',
            'dir' => 'ltr',
            'intl' => 'es-ES',
            'complete' => false,
        ],
    ],
];
