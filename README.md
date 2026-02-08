# LaraGrep

Transform natural language questions into safe, parameterized SQL SELECT queries using OpenAI or Anthropic. LaraGrep exposes an API endpoint, loads your database schema metadata, and returns human-readable answers powered by AI.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- An OpenAI or Anthropic API key

## Installation

```bash
composer require laragrep/laragrep
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=laragrep-config
```

## Configuration

### API Key & Provider

Set these in your `.env`:

```env
LARAGREP_PROVIDER=openai
LARAGREP_API_KEY=sk-...
LARAGREP_MODEL=gpt-4o-mini
```

For Anthropic:

```env
LARAGREP_PROVIDER=anthropic
LARAGREP_API_KEY=sk-ant-...
LARAGREP_MODEL=claude-sonnet-4-20250514
```

### Schema Loading Mode

LaraGrep supports three modes for providing table metadata to the AI:

| Mode     | Env Value | Behavior                                              |
|----------|-----------|-------------------------------------------------------|
| manual   | `manual`  | Only use tables defined in config (default)           |
| auto     | `auto`    | Auto-load from `information_schema` (MySQL/MariaDB)   |
| merged   | `merged`  | Auto-load first, then overlay config definitions      |

```env
LARAGREP_SCHEMA_MODE=manual
```

- **manual** is the safest default — no accidental schema exposure.
- **auto** is ideal for quick setup when all tables are fair game.
- **merged** lets you auto-load and then add descriptions, relationships, or virtual tables on top.

### Manual Table Definitions

Define your tables in `config/laragrep.php` under `contexts.default.tables`:

```php
'tables' => [
    [
        'name' => 'orders',
        'description' => 'Customer orders.',
        'columns' => [
            ['name' => 'id', 'type' => 'bigint unsigned', 'description' => 'Primary key.'],
            ['name' => 'user_id', 'type' => 'bigint unsigned', 'description' => 'FK to users.id.'],
            ['name' => 'total', 'type' => 'decimal(10,2)', 'description' => 'Order total.'],
            ['name' => 'created_at', 'type' => 'datetime', 'description' => 'Creation timestamp.'],
        ],
        'relationships' => [
            ['type' => 'belongsTo', 'table' => 'users', 'foreign_key' => 'user_id'],
        ],
    ],
],
```

When using **auto** or **merged** mode, table and column comments from your database are automatically used as descriptions.

### Named Scopes (Contexts)

Work with multiple databases or table sets by defining named contexts:

```php
'contexts' => [
    'default' => [
        'connection' => env('LARAGREP_CONNECTION'),
        'tables' => [...],
    ],
    'analytics' => [
        'connection' => 'analytics_db',
        'schema_mode' => 'auto',
        'database' => ['type' => 'MariaDB 10.6', 'name' => 'analytics'],
        'exclude_tables' => ['migrations', 'jobs'],
    ],
],
```

Select a scope via the URL: `POST /laragrep/analytics`

### Conversation Persistence

LaraGrep supports multi-turn conversations out of the box. When enabled, previous questions and answers are sent as context so the AI can handle follow-up questions.

```env
LARAGREP_CONVERSATION_ENABLED=true
LARAGREP_CONVERSATION_CONNECTION=sqlite
LARAGREP_CONVERSATION_MAX_MESSAGES=10
LARAGREP_CONVERSATION_TTL_DAYS=10
```

### Route Protection

Add authentication middleware in your config:

```php
'route' => [
    'prefix' => 'laragrep',
    'middleware' => ['auth:sanctum'],
],
```

## Usage

### API Endpoint

```
POST /laragrep/{scope?}
```

**Request body:**

```json
{
    "question": "How many users registered this week?",
    "conversation_id": "optional-uuid-for-follow-ups",
    "debug": false
}
```

**Response:**

```json
{
    "summary": "There were 42 new registrations this week.",
    "conversation_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Debug response** (when `debug: true`):

```json
{
    "summary": "There were 42 new registrations this week.",
    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
    "steps": [
        {
            "query": "SELECT COUNT(*) as total FROM users WHERE created_at >= ?",
            "bindings": ["2025-01-20"],
            "results": [{"total": 42}]
        }
    ],
    "bindings": ["2025-01-20"],
    "results": [{"total": 42}],
    "debug": {
        "queries": [
            {"query": "SELECT COUNT(*) ...", "bindings": [...], "time": 1.23}
        ]
    }
}
```

### Programmatic Usage

```php
use LaraGrep\LaraGrep;

$answer = app(LaraGrep::class)->answerQuestion(
    question: 'How many orders were placed today?',
    debug: false,
    scope: 'default',
);

echo $answer['summary'];
```

## Extending

### Custom AI Client

Implement `LaraGrep\Contracts\AiClientInterface` and rebind in a service provider:

```php
$this->app->singleton(AiClientInterface::class, fn () => new MyCustomClient());
```

### Custom Metadata Loader

Implement `LaraGrep\Contracts\MetadataLoaderInterface` for PostgreSQL, SQLite, etc.:

```php
$this->app->singleton(MetadataLoaderInterface::class, fn ($app) => new PostgresSchemaLoader($app['db']));
```

### Custom Conversation Store

Implement `LaraGrep\Contracts\ConversationStoreInterface` for Redis, file-based storage, etc.:

```php
$this->app->singleton(ConversationStoreInterface::class, fn () => new RedisConversationStore());
```

## Environment Variables

| Variable                            | Default              | Description                          |
|-------------------------------------|----------------------|--------------------------------------|
| `LARAGREP_PROVIDER`                 | `openai`             | AI provider (`openai`, `anthropic`)  |
| `LARAGREP_API_KEY`                  | —                    | API key for the AI provider          |
| `LARAGREP_MODEL`                    | `gpt-4o-mini`        | Model identifier                     |
| `LARAGREP_BASE_URL`                 | —                    | Override API endpoint URL            |
| `LARAGREP_MAX_TOKENS`              | `1024`               | Max response tokens                  |
| `LARAGREP_SCHEMA_MODE`             | `manual`             | Schema loading mode                  |
| `LARAGREP_USER_LANGUAGE`           | `en`                 | AI response language                 |
| `LARAGREP_CONNECTION`              | —                    | Database connection name             |
| `LARAGREP_DATABASE_TYPE`           | —                    | DB type hint for AI (e.g., MySQL 8)  |
| `LARAGREP_DATABASE_NAME`           | `DB_DATABASE`        | DB name hint for AI                  |
| `LARAGREP_EXCLUDE_TABLES`          | —                    | Comma-separated tables to hide       |
| `LARAGREP_DEBUG`                    | `false`              | Enable debug mode                    |
| `LARAGREP_ROUTE_PREFIX`            | `laragrep`           | API route prefix                     |
| `LARAGREP_CONVERSATION_ENABLED`    | `true`               | Enable conversation persistence      |
| `LARAGREP_CONVERSATION_CONNECTION` | `sqlite`             | DB connection for conversations      |
| `LARAGREP_CONVERSATION_MAX_MESSAGES`| `10`                | Max messages per conversation        |
| `LARAGREP_CONVERSATION_TTL_DAYS`   | `10`                 | Auto-delete conversations after days |

## Security

- Only `SELECT` queries are generated and executed — mutations are rejected.
- All queries use parameterized bindings to prevent SQL injection.
- Table references are validated against the known schema metadata.
- Protect the endpoint with middleware (e.g., `auth:sanctum`).

## License

MIT
