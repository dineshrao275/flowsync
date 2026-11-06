<?php

declare(strict_types=1);

/*
 | AI assistant. Every value here is environment-driven so a provider or model switch is a
 | config change only (see docs/architecture/ai-assistant.md). Nothing in this file is
 | secret-bearing in source: keys come from the environment and never reach the SPA.
 */
return [
    'enabled' => (bool) env('AI_ENABLED', false),

    // gemini | groq | fake (add an adapter + a `providers` entry to support another)
    'provider' => env('AI_PROVIDER', 'gemini'),

    // Empty = the provider's default_model below.
    'model' => env('AI_MODEL'),

    // Generic credential/base URL for the ACTIVE provider; a provider-specific key wins when set.
    'api_key' => env('AI_API_KEY'),
    'base_url' => env('AI_BASE_URL'),

    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 60),
    'max_retries' => (int) env('AI_MAX_RETRIES', 2),
    'retry_base_ms' => (int) env('AI_RETRY_BASE_MS', 400),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 4096),

    'streaming_enabled' => (bool) env('AI_STREAMING_ENABLED', true),
    'tool_calling_enabled' => (bool) env('AI_TOOL_CALLING_ENABLED', true),

    // Platform defaults per tenant per day; a plan's `ai_requests_per_day` / `ai_tokens_per_day` limits win.
    'daily_request_limit' => env('AI_DAILY_REQUEST_LIMIT') !== null ? (int) env('AI_DAILY_REQUEST_LIMIT') : null,
    'daily_token_limit' => env('AI_DAILY_TOKEN_LIMIT') !== null ? (int) env('AI_DAILY_TOKEN_LIMIT') : null,

    // Explicit, opt-in only. Empty = never fall back to another provider (privacy: data stays with the chosen one).
    'fallback_provider' => env('AI_FALLBACK_PROVIDER'),

    // A free-tier provider may use prompts for product improvement; refuse it in production unless acknowledged.
    'allow_free_tier' => (bool) env('AI_ALLOW_FREE_TIER', false),

    // Orchestrator bounds.
    'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 4),
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 20),
    'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 8000),

    // Document-to-task: extraction caps and proposal bounds.
    'document_max_kb' => (int) env('AI_DOCUMENT_MAX_KB', 10240),
    'document_max_chars' => (int) env('AI_DOCUMENT_MAX_CHARS', 60000),
    'document_max_pages' => (int) env('AI_DOCUMENT_MAX_PAGES', 100),
    'proposal_max_items' => (int) env('AI_PROPOSAL_MAX_ITEMS', 50),
    'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 20),

    'providers' => [
        'gemini' => [
            'driver' => 'gemini',
            'label' => 'Google Gemini',
            'key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'default_model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
            'free_tier' => true,
            'capabilities' => [
                'text' => true, 'streaming' => true, 'tools' => true, 'parallel_tools' => true,
                'json_schema_output' => true, 'structured_with_tools' => false, 'documents' => true,
                'context_window' => 1_000_000,
            ],
        ],
        'groq' => [
            'driver' => 'openai', // OpenAI-compatible chat completions: another such API is a config entry only
            'label' => 'Groq',
            'key' => env('GROQ_API_KEY'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'default_model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'free_tier' => true,
            'capabilities' => [
                'text' => true, 'streaming' => true, 'tools' => true, 'parallel_tools' => true,
                'json_schema_output' => false, 'structured_with_tools' => false, 'documents' => false,
                'context_window' => 131_072,
            ],
            // Per-model corrections to the provider defaults above.
            'models' => [
                'openai/gpt-oss-120b' => ['parallel_tools' => false, 'json_schema_output' => true],
                'openai/gpt-oss-20b' => ['parallel_tools' => false, 'json_schema_output' => true],
                'qwen/qwen3.8-27b' => ['json_schema_output' => true],
            ],
        ],
        'fake' => [
            'driver' => 'fake',
            'label' => 'Fake (tests)',
            'key' => 'fake',
            'base_url' => '',
            'default_model' => 'fake-1',
            'free_tier' => false,
            'capabilities' => [
                'text' => true, 'streaming' => true, 'tools' => true, 'parallel_tools' => true,
                'json_schema_output' => true, 'structured_with_tools' => true, 'documents' => false,
                'context_window' => 32_000,
            ],
        ],
    ],

    // Estimated USD per 1M tokens [input, output], for the usage/cost view only (not billing).
    'pricing' => [
        'gemini-3.1-flash-lite' => [0.25, 1.50],
        'gemini-3.5-flash-lite' => [0.30, 2.50],
        'gemini-3.8-flash' => [0.75, 3.75],
        'openai/gpt-oss-120b' => [0.15, 0.60],
        'openai/gpt-oss-20b' => [0.075, 0.30],
        'qwen/qwen3.8-27b' => [0.80, 4.00],
    ],
];
