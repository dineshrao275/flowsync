<?php

/*
| F11.2 — scrape endpoint. Leave METRICS_TOKEN empty and GET /api/metrics does not exist (404).
| Alert catalogue and thresholds: docs/runbook.md §6.4.
*/
return [
    'metrics_token' => env('METRICS_TOKEN'),
];
