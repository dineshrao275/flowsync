<?php

/*
 | HTTP security headers (P8.5). `csp.mode`: off | report_only | enforce.
 | Report-only is the safe rollout default; flip SECURITY_CSP_MODE=enforce once
 | the browser console shows no violations for your deployment.
 */
return [
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), payment=(self), usb=()'),
    ],

    'csp' => [
        'mode' => env('SECURITY_CSP_MODE', 'report_only'),
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),
        // Extra origins per directive, merged onto the built-in set (comma lists in env).
        'extra' => [
            'script-src' => array_filter(explode(',', (string) env('SECURITY_CSP_SCRIPT_SRC', ''))),
            'connect-src' => array_filter(explode(',', (string) env('SECURITY_CSP_CONNECT_SRC', ''))),
            'frame-src' => array_filter(explode(',', (string) env('SECURITY_CSP_FRAME_SRC', ''))),
            'img-src' => array_filter(explode(',', (string) env('SECURITY_CSP_IMG_SRC', ''))),
        ],
    ],
];
