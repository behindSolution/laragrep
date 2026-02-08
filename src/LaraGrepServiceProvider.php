<?php

namespace LaraGrep;

use Illuminate\Support\ServiceProvider;
use LaraGrep\AiClients\AnthropicClient;
use LaraGrep\AiClients\OpenAiClient;
use LaraGrep\Contracts\AiClientInterface;
use LaraGrep\Contracts\ConversationStoreInterface;
use LaraGrep\Contracts\MetadataLoaderInterface;
use LaraGrep\Conversation\DatabaseConversationStore;
use LaraGrep\Metadata\MysqlSchemaLoader;
use LaraGrep\Monitor\MonitorRecorder;
use LaraGrep\Monitor\MonitorRepository;
use LaraGrep\Monitor\MonitorStore;
use LaraGrep\Monitor\TokenEstimator;
use LaraGrep\Prompt\PromptBuilder;
use LaraGrep\Prompt\ResponseParser;
use LaraGrep\Query\QueryExecutor;
use LaraGrep\Query\QueryValidator;

class LaraGrepServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laragrep.php', 'laragrep');

        $this->app->singleton(AiClientInterface::class, function ($app) {
            $config = $app['config']->get('laragrep', []);
            $provider = strtolower((string) ($config['provider'] ?? 'openai'));
            $apiKey = (string) ($config['api_key'] ?? '');
            $model = (string) ($config['model'] ?? '');
            $baseUrl = $config['base_url'] ?? null;

            return match ($provider) {
                'anthropic' => new AnthropicClient(
                    apiKey: $apiKey,
                    model: $model ?: 'claude-sonnet-4-20250514',
                    maxTokens: (int) ($config['max_tokens'] ?? 1024),
                    baseUrl: is_string($baseUrl) && $baseUrl !== ''
                        ? $baseUrl
                        : 'https://api.anthropic.com/v1/messages',
                    anthropicVersion: (string) ($config['anthropic_version'] ?? '2023-06-01'),
                ),
                default => new OpenAiClient(
                    apiKey: $apiKey,
                    model: $model ?: 'gpt-4o-mini',
                    baseUrl: is_string($baseUrl) && $baseUrl !== ''
                        ? $baseUrl
                        : 'https://api.openai.com/v1/chat/completions',
                ),
            };
        });

        $this->app->singleton(MetadataLoaderInterface::class, function ($app) {
            return new MysqlSchemaLoader($app['db']);
        });

        $this->app->singleton(ConversationStoreInterface::class, function ($app) {
            $config = $app['config']->get('laragrep.conversation', []);

            if (!is_array($config) || !($config['enabled'] ?? true)) {
                return null;
            }

            $connectionName = $config['connection'] ?? null;
            $connectionConfig = $this->getConnectionConfig($app, $connectionName);

            if ($connectionConfig === null) {
                return null;
            }

            if (!$this->connectionSupportsConversations($app, $connectionConfig)) {
                return null;
            }

            $connection = $connectionName
                ? $app['db']->connection($connectionName)
                : $app['db']->connection();

            return new DatabaseConversationStore(
                $connection,
                (string) ($config['table'] ?? 'laragrep_conversations'),
                (int) ($config['max_messages'] ?? 10),
                (int) ($config['ttl_days'] ?? 10),
            );
        });

        $this->app->singleton(PromptBuilder::class);
        $this->app->singleton(ResponseParser::class);
        $this->app->singleton(QueryValidator::class);

        $this->app->singleton(QueryExecutor::class, function ($app) {
            $config = $app['config']->get('laragrep', []);
            $defaultConnection = $config['contexts']['default']['connection'] ?? null;

            return new QueryExecutor($defaultConnection);
        });

        $this->app->singleton(LaraGrep::class, function ($app) {
            $config = $app['config']->get('laragrep', []);

            return new LaraGrep(
                aiClient: $app->make(AiClientInterface::class),
                promptBuilder: $app->make(PromptBuilder::class),
                responseParser: $app->make(ResponseParser::class),
                queryExecutor: $app->make(QueryExecutor::class),
                queryValidator: $app->make(QueryValidator::class),
                metadataLoader: $app->make(MetadataLoaderInterface::class),
                conversationStore: $app->make(ConversationStoreInterface::class),
                config: $config,
            );
        });

        $this->app->singleton(TokenEstimator::class);

        $this->app->singleton(MonitorStore::class, function ($app) {
            $config = $app['config']->get('laragrep.monitor', []);

            if (!is_array($config) || !($config['enabled'] ?? false)) {
                return null;
            }

            $connectionName = $config['connection'] ?? null;
            $connectionConfig = $this->getConnectionConfig($app, $connectionName);

            if ($connectionConfig === null) {
                return null;
            }

            if (!$this->connectionSupportsConversations($app, $connectionConfig)) {
                return null;
            }

            $connection = $connectionName
                ? $app['db']->connection($connectionName)
                : $app['db']->connection();

            return new MonitorStore(
                $connection,
                (string) ($config['table'] ?? 'laragrep_logs'),
                (int) ($config['retention_days'] ?? 30),
            );
        });

        $this->app->singleton(MonitorRepository::class, function ($app) {
            $config = $app['config']->get('laragrep.monitor', []);

            if (!is_array($config) || !($config['enabled'] ?? false)) {
                return null;
            }

            $connectionName = $config['connection'] ?? null;
            $connection = is_string($connectionName) && $connectionName !== ''
                ? $app['db']->connection($connectionName)
                : $app['db']->connection();

            return new MonitorRepository(
                $connection,
                (string) ($config['table'] ?? 'laragrep_logs'),
            );
        });

        $this->app->singleton(MonitorRecorder::class, function ($app) {
            $store = $app->make(MonitorStore::class);

            if ($store === null) {
                return null;
            }

            $config = $app['config']->get('laragrep', []);

            return new MonitorRecorder(
                $app->make(LaraGrep::class),
                $store,
                $app->make(TokenEstimator::class),
                model: (string) ($config['model'] ?? ''),
                provider: (string) ($config['provider'] ?? 'openai'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laragrep.php' => config_path('laragrep.php'),
        ], 'laragrep-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laragrep');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/laragrep'),
        ], 'laragrep-views');

        if (config('laragrep.monitor.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/monitor.php');
        }
    }

    private function getConnectionConfig($app, ?string $connectionName): ?array
    {
        $configRepository = $app['config'];

        if (is_string($connectionName) && $connectionName !== '') {
            $config = $configRepository->get("database.connections.$connectionName");

            return is_array($config) ? $config : null;
        }

        $defaultConnection = $configRepository->get('database.default');

        if (!is_string($defaultConnection) || $defaultConnection === '') {
            return null;
        }

        $config = $configRepository->get("database.connections.$defaultConnection");

        return is_array($config) ? $config : null;
    }

    private function connectionSupportsConversations($app, array $connectionConfig): bool
    {
        $driver = $connectionConfig['driver'] ?? null;

        if ($driver !== 'sqlite') {
            return true;
        }

        $databasePath = (string) ($connectionConfig['database'] ?? '');

        if ($databasePath === '') {
            return false;
        }

        if ($databasePath === ':memory:') {
            return true;
        }

        $resolvedPath = $this->isAbsolutePath($databasePath)
            ? $databasePath
            : $app->databasePath($databasePath);

        $directory = dirname($resolvedPath);

        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                return false;
            }
        }

        if (is_file($resolvedPath)) {
            return true;
        }

        return @touch($resolvedPath);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        return preg_match('#^[A-Za-z]:\\\\#', $path) === 1;
    }
}
