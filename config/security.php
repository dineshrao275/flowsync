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
        // Defaults to our own collector (POST /api/csp-report -> `csp.violation` log lines).
        'report_uri' => env('SECURITY_CSP_REPORT_URI', '/api/csp-report'),
        // Extra origins per directive, merged onto the built-in set (comma lists in env).
        'extra' => [
            'script-src' => array_filter(explode(',', (string) env('SECURITY_CSP_SCRIPT_SRC', ''))),
            'connect-src' => array_filter(explode(',', (string) env('SECURITY_CSP_CONNECT_SRC', ''))),
            'frame-src' => array_filter(explode(',', (string) env('SECURITY_CSP_FRAME_SRC', ''))),
            'img-src' => array_filter(explode(',', (string) env('SECURITY_CSP_IMG_SRC', ''))),
        ],
    ],

    // Upload content checks (F11.8). Magic-byte sniffing is always on; set `command` to wire an antivirus
    // (e.g. "clamscan --no-summary {file}": exit 0 clean, 1 infected). `fail_closed` refuses uploads when
    // the scanner itself errors; the default lets them through and logs `upload_scan.unavailable`.
    'upload_scan' => [
        'command' => env('SECURITY_UPLOAD_SCAN_COMMAND'),
        'timeout' => (int) env('SECURITY_UPLOAD_SCAN_TIMEOUT', 20),
        'fail_closed' => (bool) env('SECURITY_UPLOAD_SCAN_FAIL_CLOSED', false),
    ],

    // Where a broken audit hash chain is reported (in addition to a critical log line).
    // Comma-separated emails; empty = log only.
    'audit_alert_emails' => array_filter(array_map('trim', explode(',', (string) env('SECURITY_AUDIT_ALERT_EMAILS', '')))),
];
