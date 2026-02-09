# LaraGrep

Transform natural language questions into safe, parameterized SQL SELECT queries using OpenAI or Anthropic. LaraGrep uses an **agent loop** — the AI executes queries one at a time, sees the results, and iteratively reasons until it can provide a final answer.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- An OpenAI or Anthropic API key

## Installation

```bash
composer require behindsolution/laragrep
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=laragrep-config
```

Run the migrations (creates the `laragrep_conversations` and `laragrep_logs` tables):

```bash
php artisan migrate
```

> **Using a dedicated SQLite connection?** Create the file first: `touch database/laragrep.sqlite`
>
> To publish the migration files for customization: `php artisan vendor:publish --tag=laragrep-migrations`

## How It Works

Unlike simple text-to-SQL tools, LaraGrep uses an **agent loop**:

1. You ask a question in natural language
2. The AI analyzes the schema and decides which query to run
3. LaraGrep validates and executes the query safely
4. The AI sees the results and decides: run another query, or provide the final answer
5. Repeat until the AI has enough data to answer (up to `max_iterations`)

This means the AI can:
- Build on previous query results to answer complex questions
- Self-correct if a query returns unexpected data
- Break down complex analysis into multiple steps
- Batch independent queries** in a single iteration to save API calls

```
"How many users and how many orders do I have?"

  → AI: Sends 2 queries in one batch (independent)        (1 API call)
  → AI: Sees both results, provides the final answer       (1 API call)
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

For Ollama (local models):

```env
LARAGREP_PROVIDER=openai
LARAGREP_API_KEY=ollama
LARAGREP_MODEL=qwen3-coder:30b
LARAGREP_BASE_URL=http://localhost:11434/v1/chat/completions
```

Ollama exposes an OpenAI-compatible API, so it works with the `openai` provider. The API key can be any non-empty string — Ollama does not validate it. This keeps your data fully local, with no external API calls.

### Agent Loop

Control how many query iterations the AI can perform per question:

```env
LARAGREP_MAX_ITERATIONS=10
```

Simple questions typically resolve in 1-2 iterations. Complex analytical questions may need more. Higher values increase capability but also cost (more API calls per question).

### Query Protection

Prevent the AI from accidentally running heavy queries:

```env
LARAGREP_MAX_ROWS=20
LARAGREP_MAX_QUERY_TIME=3
```

- **max_rows** — Automatically injects `LIMIT` into queries that don't have one. Default: `20`. Set to `0` to disable.
- **max_query_time** — Maximum execution time per query in seconds. Kills slow queries (full table scans, massive joins) before they block the database. Default: `3`. Supports MySQL, MariaDB, PostgreSQL, and SQLite.

### Smart Schema

For large databases, LaraGrep can make an initial AI call to identify only the tables relevant to the question. The agent loop then runs with a filtered schema, significantly reducing token usage.

```env
LARAGREP_SMART_SCHEMA=20
```

When set to a number (e.g., `20`), smart schema activates automatically when the total table count reaches that threshold. Set to `null` (default) to disable. Can also be overridden per context.

With 200 tables and only 5 relevant, this reduces token usage by ~60% across the agent loop iterations.

### Schema Loading Mode

LaraGrep supports three modes for providing table metadata to the AI:

| Mode     | Env Value | Behavior                                              |
|----------|-----------|-------------------------------------------------------|
| manual   | `manual`  | Only use tables defined in config (default)           |
| auto     | `auto`    | Auto-load from `information_schema` (MySQL/MariaDB/PostgreSQL) |
| merged   | `merged`  | Auto-load first, then overlay config definitions      |

```env
LARAGREP_SCHEMA_MODE=manual
```

- **manual** is the safest default — no accidental schema exposure.
- **auto** is ideal for quick setup when all tables are fair game.
- **merged** lets you auto-load and then add descriptions, relationships, or virtual tables on top.

### Table Definitions

Define your tables in `config/laragrep.php` using fluent classes with IDE autocomplete:

```php
use LaraGrep\Config\Table;
use LaraGrep\Config\Column;
use LaraGrep\Config\Relationship;

'tables' => [
    Table::make('orders')
        ->description('Customer orders.')
        ->columns([
            Column::id(),
            Column::bigInteger('user_id')->unsigned()->description('FK to users.id.'),
            Column::decimal('total', 10, 2)->description('Order total.'),
            Column::enum('status', ['pending', 'paid', 'cancelled']),
            Column::json('metadata')
                ->description('Order metadata')
                ->template(['shipping_method' => 'express', 'tracking_code' => 'BR123456789']),
            Column::timestamp('created_at'),
        ])
        ->relationships([
            Relationship::belongsTo('users', 'user_id'),
        ]),
],
```

The `Column` class supports the same types as Laravel migrations: `id()`, `bigInteger()`, `integer()`, `smallInteger()`, `tinyInteger()`, `string()`, `text()`, `decimal()`, `float()`, `boolean()`, `date()`, `dateTime()`, `timestamp()`, `json()`, `enum()`. Modifiers: `->unsigned()`, `->nullable()`, `->description()`.

For JSON columns, `->template()` provides an example structure so the AI knows how to query it with `JSON_EXTRACT`.

#### Organizing Large Schemas

For projects with many tables, extract each definition into its own class:

```php
// app/LaraGrep/Tables/OrdersTable.php
namespace App\LaraGrep\Tables;

use LaraGrep\Config\Table;
use LaraGrep\Config\Column;
use LaraGrep\Config\Relationship;

class OrdersTable
{
    public static function define(): Table
    {
        return Table::make('orders')
            ->description('Customer orders.')
            ->columns([
                Column::id(),
                Column::bigInteger('user_id')->unsigned(),
                Column::decimal('total', 10, 2),
                Column::timestamp('created_at'),
            ])
            ->relationships([
                Relationship::belongsTo('users', 'user_id'),
            ]);
    }
}
```

Then import them in your config:

```php
// config/laragrep.php
'tables' => [
    \App\LaraGrep\Tables\UsersTable::define(),
    \App\LaraGrep\Tables\OrdersTable::define(),
    \App\LaraGrep\Tables\ProductsTable::define(),
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
LARAGREP_CONVERSATION_RETENTION_DAYS=10
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

**cURL example:**

```bash
curl -X POST http://localhost/laragrep \
  -H "Content-Type: application/json" \
  -d '{"question": "How many users registered this week?"}'
```

With authentication and options:

```bash
curl -X POST http://localhost/laragrep \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "question": "How many users registered this week?",
    "conversation_id": "optional-uuid-for-follow-ups",
    "debug": true
  }'
```

Using a named scope:

```bash
curl -X POST http://localhost/laragrep/analytics \
  -H "Content-Type: application/json" \
  -d '{"question": "What are the top 5 products by revenue?"}'
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
            "results": [{"total": 42}],
            "reason": "Counting users registered in the current week"
        }
    ],
    "bindings": ["2025-01-20"],
    "results": [{"total": 42}],
    "debug": {
        "queries": [
            {"query": "SELECT COUNT(*) ...", "bindings": [...], "time": 1.23}
        ],
        "iterations": 1
    }
}
```

### Programmatic Usage

```php
use LaraGrep\LaraGrep;

$laraGrep = app(LaraGrep::class);

$answer = $laraGrep->answerQuestion(
    question: 'How many orders were placed today?',
    scope: 'default',
);

echo $answer['summary'];
```

The `answerQuestion()` method always returns the full response including `summary`, `steps` (agent loop trace), and `debug` (query log with timing). The API endpoint strips `steps` and `debug` unless `"debug": true` is sent in the request.

### Formatting Results

Use `formatResult()` to transform raw query results into structured formats via AI. This is useful for building exports, notifications, or reports programmatically.

**Export format** — tabular data for spreadsheets:

```php
$answer = $laraGrep->answerQuestion('Top 10 products by revenue');

$tables = $laraGrep->formatResult($answer, 'export');
// [
//     [
//         'title' => 'Top 10 Products by Revenue',
//         'headers' => ['Product', 'Revenue', 'Units Sold'],
//         'rows' => [
//             ['Widget Pro', 45230.00, 890],
//             ['Gadget X', 38100.50, 650],
//         ],
//     ],
// ]
```

The structure is always `title`, `headers`, `rows` — feed it into any CSV, XLSX, or PDF library.

**Notification format** — ready-to-render content for email, Slack, or webhooks:

```php
$notification = $laraGrep->formatResult($answer, 'notification');
// [
//     'title' => 'Weekly Sales Report',
//     'html' => '<p>Sales this week totaled...</p><table>...</table>',
//     'text' => 'Sales this week totaled...\nProduct | Revenue...',
// ]
```

Three fixed keys: `title`, `html` (for email/web), `text` (for Slack/SMS/logs). The AI handles all formatting — you just inject into your template.

### Saved Queries (Recipes)

When enabled, LaraGrep auto-saves a "recipe" after each answer — the question, scope, and SQL queries that worked. The response includes a `recipe_id` that the frontend can reference for exports, notifications, or scheduled re-execution.

#### Enabling

```env
LARAGREP_RECIPES_ENABLED=true
```

After enabling, publish and run the migration for the `laragrep_recipes` table.

#### How It Works

Every API response now includes a `recipe_id`:

```json
{
    "summary": "Sales this week totaled...",
    "conversation_id": "uuid",
    "recipe_id": 42
}
```

#### Dispatching a Recipe

The frontend can dispatch a recipe for export or notification:

```bash
curl -X POST http://localhost/laragrep/recipes/42/dispatch \
  -H "Content-Type: application/json" \
  -d '{"format": "notification", "period": "now"}'
```

The `period` parameter controls timing:
- `"now"` — immediate execution (default)
- `"2026-02-10 08:00:00"` — scheduled for a specific date/time

LaraGrep fires a `RecipeDispatched` event and returns `{"status": "dispatched"}`. Your app handles the rest via a listener:

```php
// app/Listeners/HandleRecipeDispatch.php
use LaraGrep\Events\RecipeDispatched;
use LaraGrep\LaraGrep;

public function handle(RecipeDispatched $event)
{
    $job = new ProcessRecipeJob($event->recipe, $event->format, $event->userId);

    if ($event->period === 'now') {
        dispatch($job);
    } else {
        dispatch($job)->delay(Carbon::parse($event->period));
    }
}
```

```php
// app/Jobs/ProcessRecipeJob.php
use LaraGrep\Monitor\MonitorRecorder;

public function handle(MonitorRecorder $recorder)
{
    // Using MonitorRecorder ensures replays are tracked in the monitor.
    // When the monitor is disabled, MonitorRecorder resolves to null —
    // in that case, inject LaraGrep directly instead.
    $answer = $recorder->replayRecipe($this->recipe, $this->userId);
    $result = $recorder->formatResult($answer, $this->format);

    // Send email, generate Excel, post to Slack, etc.
}
```

#### Viewing a Recipe

```bash
curl http://localhost/laragrep/recipes/42
```

#### Programmatic Usage

You can also use recipes programmatically without the API:

```php
$laraGrep = app(LaraGrep::class);

// First run
$answer = $laraGrep->answerQuestion('Weekly sales by region');
$recipe = $laraGrep->extractRecipe($answer, 'Weekly sales by region', 'default');

// Later — replay with fresh data
$freshAnswer = $laraGrep->replayRecipe($recipe);
$tables = $laraGrep->formatResult($freshAnswer, 'export');
$notification = $laraGrep->formatResult($freshAnswer, 'notification');
```

When the monitor is enabled, use `MonitorRecorder` instead of `LaraGrep` to ensure replays appear in the dashboard:

```php
$recorder = app(MonitorRecorder::class);
$answer = $recorder->replayRecipe($recipe, $userId);
$result = $recorder->formatResult($answer, 'export');
```

The recipe stores the question, scope, and the SQL queries that worked — not the results. On replay, the AI adjusts date bindings and parameters automatically, converging in fewer iterations than a fresh question.

## Extending

### Custom AI Client

Implement `LaraGrep\Contracts\AiClientInterface` and rebind in a service provider:

```php
$this->app->singleton(AiClientInterface::class, fn () => new MyCustomClient());
```

### Custom Metadata Loader

LaraGrep auto-detects MySQL/MariaDB and PostgreSQL. For other databases (SQLite, SQL Server, etc.), implement `LaraGrep\Contracts\MetadataLoaderInterface`:

```php
$this->app->singleton(MetadataLoaderInterface::class, fn ($app) => new MySqliteSchemaLoader($app['db']));
```

### Custom Conversation Store

Implement `LaraGrep\Contracts\ConversationStoreInterface` for Redis, file-based storage, etc.:

```php
$this->app->singleton(ConversationStoreInterface::class, fn () => new RedisConversationStore());
```

## Environment Variables

| Variable                            | Default            | Description                          |
|-------------------------------------|--------------------|--------------------------------------|
| `LARAGREP_PROVIDER`                 | `openai`           | AI provider (`openai`, `anthropic`)  |
| `LARAGREP_API_KEY`                  | —                  | API key for the AI provider          |
| `LARAGREP_MODEL`                    | `gpt-4o-mini`      | Model identifier                     |
| `LARAGREP_BASE_URL`                 | —                  | Override API endpoint URL            |
| `LARAGREP_MAX_TOKENS`              | `1024`             | Max response tokens                  |
| `LARAGREP_TIMEOUT`                 | `300`              | HTTP timeout in seconds for AI calls |
| `LARAGREP_MAX_ITERATIONS`          | `10`               | Max query iterations per question    |
| `LARAGREP_MAX_ROWS`               | `20`               | Max rows per query (auto LIMIT)      |
| `LARAGREP_MAX_QUERY_TIME`         | `3`                | Max query execution time in seconds  |
| `LARAGREP_SMART_SCHEMA`           | —                  | Table count threshold for smart filtering |
| `LARAGREP_SCHEMA_MODE`             | `manual`           | Schema loading mode                  |
| `LARAGREP_USER_LANGUAGE`           | `en`               | AI response language                 |
| `LARAGREP_CONNECTION`              | —                  | Database connection name             |
| `LARAGREP_DATABASE_TYPE`           | —                  | DB type hint for AI (e.g., MySQL 8)  |
| `LARAGREP_DATABASE_NAME`           | `DB_DATABASE`      | DB name hint for AI                  |
| `LARAGREP_EXCLUDE_TABLES`          | —                  | Comma-separated tables to hide       |
| `LARAGREP_DEBUG`                    | `false`            | Enable debug mode                    |
| `LARAGREP_ROUTE_PREFIX`            | `laragrep`         | API route prefix                     |
| `LARAGREP_CONVERSATION_ENABLED`    | `true`             | Enable conversation persistence      |
| `LARAGREP_CONVERSATION_CONNECTION` | `sqlite`           | DB connection for conversations      |
| `LARAGREP_CONVERSATION_MAX_MESSAGES`| `10`               | Max messages per conversation        |
| `LARAGREP_CONVERSATION_RETENTION_DAYS`   | `10`               | Auto-delete conversations after days |
| `LARAGREP_MONITOR_ENABLED`        | `false`            | Enable monitoring dashboard          |
| `LARAGREP_MONITOR_CONNECTION`     | `sqlite`           | DB connection for monitor logs       |
| `LARAGREP_MONITOR_TABLE`          | `laragrep_logs`    | Table name for monitor logs          |
| `LARAGREP_MONITOR_RETENTION_DAYS` | `30`               | Auto-delete logs after days          |
| `LARAGREP_RECIPES_ENABLED`        | `false`            | Enable recipe auto-save              |
| `LARAGREP_RECIPES_CONNECTION`     | `sqlite`           | DB connection for recipes            |
| `LARAGREP_RECIPES_TABLE`          | `laragrep_recipes` | Table name for recipes               |
| `LARAGREP_RECIPES_RETENTION_DAYS` | `30`               | Auto-delete recipes after days       |

## Monitor

LaraGrep includes a built-in monitoring dashboard for tracking queries, errors, token usage, and performance. Disabled by default.

### Enabling

```env
LARAGREP_MONITOR_ENABLED=true
```

### Dashboard

Access the dashboard at `GET /laragrep/monitor`:

- **Logs** — Filterable list of all queries with status, duration, iterations, and token estimates
- **Overview** — Aggregate stats: success rate, errors, token usage, daily charts, top scopes, storage metrics
- **Detail** — Full agent loop trace for each query: SQL, bindings, results, AI reasoning, errors

### Protecting the Dashboard

```php
'monitor' => [
    'enabled' => true,
    'middleware' => ['auth:sanctum'],
],
```

### What Gets Tracked

- Question, scope, user ID, conversation ID
- Status (success/error) with full error details and stack trace
- Each agent loop step (SQL, bindings, results, AI reasoning)
- Smart schema filtering (tables total vs filtered)
- Estimated token usage
- Response time
- Raw query log with timing

## Security

- Only `SELECT` queries are generated and executed — mutations are rejected.
- All queries use parameterized bindings to prevent SQL injection.
- Table references are validated against the known schema metadata.
- The agent loop is capped at `max_iterations` to prevent runaway costs.
- Protect the endpoint with middleware (e.g., `auth:sanctum`).

## License

MIT
