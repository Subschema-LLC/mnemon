<?php

return [
    // Where AdminUserSeeder writes the generated admin password. Injectable so
    // tests never touch — and never delete — the real file: storage_path() is
    // not redirected under APP_ENV=testing, so the default is the same file a
    // native install produces, and there is no password reset flow to recover
    // from clobbering it.
    'admin_password_path' => storage_path('admin-password.txt'),

    // Address the public landing page's contact form notifies, in addition
    // to always persisting the submission as a ContactSubmission row. Empty
    // by default: ContactController skips the send entirely rather than
    // falling back to any hardcoded address — every self-hosted install
    // must opt in to where its own visitors' messages get mailed.
    'contact_to' => env('MNEMON_CONTACT_TO'),

    // Which Claude Code marketplace the device setup script installs from. A
    // fork points its own installs at its own repository without editing the
    // script.
    'plugin' => [
        'marketplace' => env('MNEMON_PLUGIN_MARKETPLACE', 'coopers98/mnemon'),
    ],

    'embedding' => [
        'driver' => env('MNEMON_EMBEDDING_DRIVER', 'openai'),
        // Seconds to wait for one embedding. Without it the HTTP client's
        // 30s default applied to every drawer written.
        'timeout' => (int) env('MNEMON_EMBEDDING_TIMEOUT', 10),
        'drivers' => [
            'openai' => [
                'model' => env('MNEMON_OPENAI_MODEL', 'text-embedding-3-small'),
                'dimensions' => 1536,
            ],
            'ollama' => [
                'model' => env('MNEMON_OLLAMA_MODEL', 'nomic-embed-text'),
                'host' => env('MNEMON_OLLAMA_HOST', 'http://localhost:11434'),
                'dimensions' => 768,
            ],
            // Any OpenAI-style /embeddings endpoint: Mistral, Together,
            // Jina, a local vLLM or LM Studio, and so on. No defaults for the
            // URL, model or width: they differ for every service, and a
            // guessed width stores vectors search never finds. The key is
            // optional, for local servers that take none.
            'openai-compatible' => [
                'base_url' => env('MNEMON_EMBEDDING_BASE_URL'),
                'api_key' => env('MNEMON_EMBEDDING_API_KEY'),
                'model' => env('MNEMON_EMBEDDING_MODEL'),
                'dimensions' => is_numeric(env('MNEMON_EMBEDDING_DIMENSIONS')) ? (int) env('MNEMON_EMBEDDING_DIMENSIONS') : null,
            ],
            // Voyage AI. Its current models return 1024 dimensions unless
            // asked for 256, 512 or 2048. Key: VOYAGE_API_KEY.
            'voyage' => [
                'model' => env('MNEMON_VOYAGE_MODEL', 'voyage-4'),
                'dimensions' => (int) env('MNEMON_VOYAGE_DIMENSIONS', 1024),
            ],
            // Google Gemini. gemini-embedding-2 returns 3072 dimensions at
            // most; 768 keeps the index small with little lost (Google
            // recommends 768, 1536 or 3072). Key: GEMINI_API_KEY.
            'gemini' => [
                'model' => env('MNEMON_GEMINI_MODEL', 'gemini-embedding-2'),
                'dimensions' => (int) env('MNEMON_GEMINI_DIMENSIONS', 768),
            ],
            'none' => [],
        ],
    ],
    'retrieval' => [
        'weights' => [
            'semantic' => 0.6,
            'fulltext' => 0.3,
            'temporal' => 0.1,
        ],
        'temporal_boost_days' => 7,
        'default_limit' => 5,
        'max_limit' => 20,
    ],
    'wiki' => [
        'stale_days' => 30,
        'confidence_decay_days' => 90,
    ],

    // Item 12: Quality Scoring
    'quality' => [
        'enabled' => env('MNEMON_QUALITY_LLM', false), // false = heuristics only
        'low_threshold' => 0.4,
        'min_length' => 100,
        'vague_phrases' => [
            'it depends', 'various factors', 'many things', 'could be', 'might be',
            'in some cases', 'sometimes', 'generally speaking', 'it is important to note',
            'as mentioned', 'basically', 'essentially', 'kind of', 'sort of',
        ],
    ],

    // Item 14: Retention / Forgetting Curves
    'retention' => [
        'half_lives' => [
            'raw' => 30,         // fast decay
            'reviewed' => 90,    // medium decay
            'consolidated' => 365, // slow decay
        ],
        'soft_delete_threshold' => 0.05, // delete drawers below this score
    ],

    'recall' => [
        'confidence_floor' => env('MNEMON_RECALL_FLOOR', 0.45),
        'default_token_budget' => 1500,
    ],

    // The chat model Mnemon's own LLM work runs on: session digests now, wiki
    // compilation next. One setting so the model is changed in one place. Pin
    // a specific model rather than a "latest" alias, so behaviour changes only
    // when the config does.
    'llm' => [
        'model' => env('MNEMON_LLM_MODEL', 'gpt-5.6-luna'),
    ],

    'digest' => [
        // openai, anthropic or openai-compatible. A model marked for digests
        // in the admin panel wins: its provider's driver runs instead.
        'driver' => env('MNEMON_DIGEST_DRIVER', 'openai'),
        'confidence_floor' => env('MNEMON_DIGEST_FLOOR', 0.5),

        // The review queue (Admin -> Review Queue). "off" (the default) keeps
        // today's behaviour: proposals at or above the floor become drawers,
        // the rest are dropped. "low" holds the below-floor ones for review
        // instead of dropping them. "all" holds every proposal: nothing a
        // digest proposes becomes a drawer until the owner approves it. New
        // wings always go to Pending Wings, whatever the mode.
        'review' => env('MNEMON_DIGEST_REVIEW', 'off'),

        // Overrides mnemon.llm.model for digests only. Unset, digests use the
        // shared model. A model marked for digests in the admin panel wins
        // over both.
        'openai_model' => env('MNEMON_DIGEST_OPENAI_MODEL'),

        // The anthropic driver's model when none is marked. Key:
        // ANTHROPIC_API_KEY.
        'anthropic_model' => env('MNEMON_DIGEST_ANTHROPIC_MODEL', 'claude-haiku-5-5'),

        // The openai-compatible driver: any OpenAI-style chat completions
        // API (OpenRouter, Groq, a local Ollama or vLLM...). base_url is the
        // URL before /chat/completions; the key is optional, for local
        // servers. Turn json_mode off for a server that rejects
        // response_format.
        'compatible' => [
            'base_url' => env('MNEMON_DIGEST_BASE_URL'),
            'api_key' => env('MNEMON_DIGEST_API_KEY'),
            'model' => env('MNEMON_DIGEST_MODEL'),
            'json_mode' => (bool) env('MNEMON_DIGEST_JSON_MODE', true),
        ],

        // Seconds to wait on the digest completion. The old hard-coded 20 was
        // too tight for a large transcript and produced `cURL error 28`, which
        // reached the client as an opaque 500 rather than as a retryable
        // timeout. Raise it if digests of long sessions still time out.
        'timeout' => env('MNEMON_DIGEST_TIMEOUT', 60),

        // Upper bound on the model's output (reasoning included) per digest.
        // Caps what one call can spend.
        'max_completion_tokens' => (int) env('MNEMON_DIGEST_MAX_COMPLETION_TOKENS', 16000),

        // Prices are not configured here: they live with each model in the
        // admin panel (LLM models), where marking a model "use for digests"
        // also overrides openai_model above.

        // A typical digest's output (reasoning included), for the backfill
        // estimate brain_status publishes. The LLM models table shows the
        // measured average per model to set it from.
        'typical_output_tokens' => (int) env('MNEMON_DIGEST_TYPICAL_OUTPUT_TOKENS', 3000),
    ],
    // The public, read-only demo (trymnemon.com). Off unless MNEMON_DEMO=true.
    // See docs/design/2026-10-13-public-demo-design.md.
    'demo' => [
        'enabled' => (bool) env('MNEMON_DEMO', false),
        // The one hostname the demo answers to; any other Host gets a 404.
        'host' => env('MNEMON_DEMO_HOST'),
        // Requests per minute per client (per IPv4 address, per IPv6 /64).
        'rate_limit' => (int) env('MNEMON_DEMO_RATE_LIMIT', 120),
        // Set on a real install to link its pages to the public demo.
        'url' => env('MNEMON_DEMO_URL'),
        // Where DemoSeeder reads the synthetic brain from.
        'fixtures' => database_path('demo'),
    ],
];
