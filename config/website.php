<?php

return [
    /*
    | Top-level public slugs that must never become CMS pages: they would
    | shadow a real route (`/{slug}` is registered after every other
    | top-level route, and the controller 404s these regardless).
    */
    'reserved_slugs' => [
        'app',
        'api',
        'storage',
        'build',
        'up',
        'page',
        'blog',
        'sitemap.xml',
        'robots.txt',
        'favicon.svg',
        'favicon.ico',
        'reset-password',
        'login',
        'register',
    ],
];
