<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "openai", "anthropic"
    |
    */

    'provider' => env('LARAGREP_PROVIDER', 'openai'),

    'api_key' => env('LARAGREP_API_KEY'),

    'base_url' => env('LARAGREP_BASE_URL'),

    'model' => env('LARAGREP_MODEL', 'gpt-4o-mini'),

    'max_tokens' => (int) env('LARAGREP_MAX_TOKENS', 1024),

    'anthropic_version' => env('LARAGREP_ANTHROPIC_VERSION', '2023-06-01'),

    /*
    |--------------------------------------------------------------------------
    | Schema Loading Mode
    |--------------------------------------------------------------------------
    |
    | Controls how table metadata is provided to the AI:
    |
    | "manual"  - Only use tables defined in the contexts below (default).
    | "auto"    - Auto-load from information_schema (MySQL/MariaDB).
    | "merged"  - Auto-load first, then overlay manual definitions on top.
    |
    */

    'schema_mode' => env('LARAGREP_SCHEMA_MODE', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Agent Loop
    |--------------------------------------------------------------------------
    |
    | Maximum number of query iterations the AI can perform per question.
    | The AI executes one query at a time, sees the results, and decides
    | whether to run another query or provide the final answer.
    |
    | Simple questions typically need 1-2 iterations. Complex analytical
    | questions may need more. Higher values increase capability but also
    | cost (more API calls per question).
    |
    */

    'max_iterations' => (int) env('LARAGREP_MAX_ITERATIONS', 10),

    /*
    |--------------------------------------------------------------------------
    | Smart Schema
    |--------------------------------------------------------------------------
    |
    | When the number of tables reaches this threshold, LaraGrep makes an
    | initial AI call to identify only the tables relevant to the question.
    | Subsequent agent loop iterations use the filtered schema, reducing
    | token usage significantly for large databases.
    |
    | Set to null to disable, or a number (e.g., 20) to enable automatically
    | when the schema has that many tables or more. Can also be overridden
    | per context.
    |
    */

    'smart_schema' => env('LARAGREP_SMART_SCHEMA'),

    /*
    |--------------------------------------------------------------------------
    | Custom System Prompt
    |--------------------------------------------------------------------------
    |
    | Override the built-in system prompt. Leave null to use the default.
    |
    */

    'system_prompt' => env('LARAGREP_SYSTEM_PROMPT'),

    /*
    |--------------------------------------------------------------------------
    | User Language
    |--------------------------------------------------------------------------
    |
    | ISO language code for AI responses (e.g., "en", "pt-BR", "es").
    |
    */

    'user_language' => env('LARAGREP_USER_LANGUAGE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Conversation Persistence
    |--------------------------------------------------------------------------
    |
    | Enable multi-turn conversation support. When enabled, previous questions
    | and answers are sent as context to the AI for follow-up questions.
    |
    */

    'conversation' => [
        'enabled' => (bool) env('LARAGREP_CONVERSATION_ENABLED', true),
        'connection' => env('LARAGREP_CONVERSATION_CONNECTION', 'sqlite'),
        'table' => env('LARAGREP_CONVERSATION_TABLE', 'laragrep_conversations'),
        'max_messages' => (int) env('LARAGREP_CONVERSATION_MAX_MESSAGES', 10),
        'ttl_days' => (int) env('LARAGREP_CONVERSATION_TTL_DAYS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contexts (Scopes)
    |--------------------------------------------------------------------------
    |
    | Each context can override: connection, database, exclude_tables, tables,
    | and schema_mode. The "default" context is used when no scope is specified.
    |
    | Select a context via the URL: POST /laragrep/{scope}
    |
    */

    'contexts' => [
        'default' => [
            'connection' => env('LARAGREP_CONNECTION'),
            'exclude_tables' => array_values(array_filter(
                array_map('trim', explode(',', (string) env('LARAGREP_EXCLUDE_TABLES', '')))
            )),
            'database' => [
                'type' => env('LARAGREP_DATABASE_TYPE', ''),
                'name' => env('LARAGREP_DATABASE_NAME', env('DB_DATABASE', '')),
            ],
            'tables' => [
                // Define your table metadata using fluent classes. Example:
                //
                // Table::make('users')
                //     ->description('Registered application users.')
                //     ->columns([
                //         Column::id(),
                //         Column::string('name')->description('Full name.'),
                //         Column::string('email')->description('Unique email address.'),
                //     ])
                //     ->relationships([
                //         Relationship::hasMany('posts', 'user_id'),
                //     ]),
                //
                // use LaraGrep\Config\{Table, Column, Relationship};
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, responses include executed queries, bindings, and timing.
    | Can also be toggled per-request via the "debug" body parameter.
    |
    */

    'debug' => (bool) env('LARAGREP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */

    'route' => [
        'prefix' => env('LARAGREP_ROUTE_PREFIX', 'laragrep'),
        'middleware' => [],
    ],

];
