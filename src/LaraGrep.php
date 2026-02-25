<?php

namespace LaraGrep;

use Closure;
use Illuminate\Support\Facades\DB;
use LaraGrep\Config\Table;
use LaraGrep\Contracts\AiClientInterface;
use LaraGrep\Contracts\ConversationStoreInterface;
use LaraGrep\Contracts\MetadataLoaderInterface;
use LaraGrep\Prompt\PromptBuilder;
use LaraGrep\Prompt\ResponseParser;
use LaraGrep\Query\QueryExecutor;
use LaraGrep\Query\QueryValidator;
use RuntimeException;

class LaraGrep
{
    protected array $lastSchemaStats = [];
    protected int $lastPromptTokens = 0;
    protected int $lastCompletionTokens = 0;

    public function __construct(
        protected AiClientInterface $aiClient,
        protected PromptBuilder $promptBuilder,
        protected ResponseParser $responseParser,
        protected QueryExecutor $queryExecutor,
        protected QueryValidator $queryValidator,
        protected ?MetadataLoaderInterface $metadataLoader,
        protected ?ConversationStoreInterface $conversationStore,
        protected array $config = [],
    ) {
    }

    public function answerQuestion(
        string $question,
        ?string $scope = null,
        ?string $conversationId = null,
        ?Closure $onStep = null,
    ): array {
        $this->lastPromptTokens = 0;
        $this->lastCompletionTokens = 0;

        $scopeConfig = $this->resolveScopeConfig($scope);
        $userLanguage = $scopeConfig['user_language'] ?? $this->config['user_language'] ?? 'en';
        $maxIterations = (int) ($this->config['max_iterations'] ?? 10);

        $resolvedConnection = $this->resolveConnection($scopeConfig['connection'] ?? null);
        $this->queryExecutor->setConnection($resolvedConnection);

        $tables = $this->resolveMetadata($scopeConfig);
        $tables = $this->fillDefaultConnection($tables, $resolvedConnection);
        $tablesTotal = count($tables);
        $tables = $this->applySmartSchema($tables, $question, $scopeConfig);
        $this->lastSchemaStats = ['total' => $tablesTotal, 'filtered' => count($tables)];

        $knownTables = $this->extractKnownTables($tables);

        $conversationId = $this->normalizeId($conversationId);
        $history = [];
        if ($conversationId !== null && $this->conversationStore !== null) {
            $history = $this->conversationStore->getMessages($conversationId);
        }

        $messages = $this->promptBuilder->buildQueryMessages(
            question: $question,
            tables: $tables,
            userLanguage: $userLanguage,
            database: $scopeConfig['database'] ?? null,
            customSystemPrompt: $this->config['system_prompt'] ?? null,
            conversationHistory: $history,
        );

        $result = $this->runAgentLoop($messages, $knownTables, $maxIterations, $userLanguage, $onStep);

        if ($conversationId !== null && $this->conversationStore !== null) {
            $this->conversationStore->appendExchange($conversationId, $question, $result['summary']);
        }

        return $result;
    }

    /**
     * Extract a reusable recipe from an answer for future replay.
     *
     * @param  array  $answer  The full answer from answerQuestion()
     * @param  string  $question  The original question
     * @param  string|null  $scope  The scope used
     */
    public function extractRecipe(array $answer, string $question, ?string $scope = null): array
    {
        $steps = $answer['steps'] ?? [];
        $queries = [];

        foreach ($steps as $step) {
            if (isset($step['error']) || empty($step['results'])) {
                continue;
            }

            $queries[] = [
                'query' => $step['query'] ?? '',
                'bindings' => $step['bindings'] ?? [],
                'reason' => $step['reason'] ?? null,
            ];
        }

        return [
            'question' => $question,
            'scope' => $scope ?? 'default',
            'queries' => $queries,
        ];
    }

    /**
     * Replay a saved recipe with fresh data.
     * The AI receives the previous queries as context and adjusts parameters
     * (dates, filters) for the current execution, skipping the exploration phase.
     *
     * @param  array  $recipe  Recipe from extractRecipe()
     */
    public function replayRecipe(array $recipe, ?Closure $onStep = null): array
    {
        $this->lastPromptTokens = 0;
        $this->lastCompletionTokens = 0;

        $question = $recipe['question'] ?? '';
        $scope = $recipe['scope'] ?? 'default';
        $previousQueries = $recipe['queries'] ?? [];

        $scopeConfig = $this->resolveScopeConfig($scope);
        $userLanguage = $scopeConfig['user_language'] ?? $this->config['user_language'] ?? 'en';
        $maxIterations = (int) ($this->config['max_iterations'] ?? 10);

        $resolvedConnection = $this->resolveConnection($scopeConfig['connection'] ?? null);
        $this->queryExecutor->setConnection($resolvedConnection);

        $tables = $this->resolveMetadata($scopeConfig);
        $tables = $this->fillDefaultConnection($tables, $resolvedConnection);
        $tablesTotal = count($tables);
        $this->lastSchemaStats = ['total' => $tablesTotal, 'filtered' => $tablesTotal];

        $knownTables = $this->extractKnownTables($tables);

        $messages = $this->promptBuilder->buildReplayMessages(
            question: $question,
            tables: $tables,
            previousQueries: $previousQueries,
            userLanguage: $userLanguage,
            database: $scopeConfig['database'] ?? null,
            customSystemPrompt: $this->config['system_prompt'] ?? null,
        );

        return $this->runAgentLoop($messages, $knownTables, $maxIterations, $userLanguage, $onStep);
    }

    /**
     * Transform raw answer data into a structured format using AI.
     *
     * @param  array  $answer  The full answer from answerQuestion() or replayRecipe()
     * @param  string  $format  'query' or 'notification'
     * @return array  Structured data ready for consumption
     */
    public function formatResult(array $answer, string $format): array
    {
        $steps = $answer['steps'] ?? [];
        $summary = $answer['summary'] ?? '';
        $userLanguage = $this->config['user_language'] ?? 'en';

        $messages = match ($format) {
            'query' => $this->promptBuilder->buildFormatQueryMessages($steps, $summary, $userLanguage),
            'notification' => $this->promptBuilder->buildFormatNotificationMessages($steps, $summary, $userLanguage),
            default => throw new RuntimeException("Unsupported format: {$format}. Use 'query' or 'notification'."),
        };

        $aiResponse = $this->aiClient->chat($messages);
        $this->lastPromptTokens += $aiResponse->promptTokens;
        $this->lastCompletionTokens += $aiResponse->completionTokens;

        $content = trim($aiResponse->content);
        $content = preg_replace('/^```(?:json)?\s*/im', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('AI returned invalid JSON for format transformation.');
        }

        return $decoded;
    }

    /**
     * Run the agent loop: AI decides queries, executes them, iterates until answer.
     */
    protected function runAgentLoop(
        array $messages,
        array $knownTables,
        int $maxIterations,
        string $userLanguage,
        ?Closure $onStep = null,
    ): array {
        $executedSteps = [];
        $debugQueries = [];
        $summary = null;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $aiResponse = $this->aiClient->chat($messages);
            $this->lastPromptTokens += $aiResponse->promptTokens;
            $this->lastCompletionTokens += $aiResponse->completionTokens;
            $response = $aiResponse->content;

            try {
                $action = $this->responseParser->parseAction($response);
            } catch (RuntimeException) {
                $summary = trim($response) ?: 'Sorry, I could not process your question.';
                break;
            }

            if ($action['action'] === 'answer') {
                $summary = $action['summary'];
                break;
            }

            $batchResults = [];

            foreach ($action['queries'] as $entry) {
                $entryConnection = $entry['connection'] ?? null;

                try {
                    $this->queryValidator->validate($entry['query'], $knownTables);
                    $execution = $this->queryExecutor->execute($entry['query'], $entry['bindings'], $entryConnection);
                } catch (RuntimeException $e) {
                    $errorMsg = $e->getMessage();
                    $availableTables = implode(', ', $knownTables);

                    $step = [
                        'query' => $entry['query'],
                        'bindings' => $entry['bindings'],
                        'results' => [],
                        'reason' => $entry['reason'] ?? null,
                        'error' => $errorMsg,
                    ];

                    if ($entryConnection !== null) {
                        $step['connection'] = $entryConnection;
                    }

                    $executedSteps[] = $step;

                    $batchResults[] = [
                        'query' => $entry['query'],
                        'error' => "{$errorMsg} Available tables: {$availableTables}.",
                    ];

                    continue;
                }

                $step = [
                    'query' => $entry['query'],
                    'bindings' => $entry['bindings'],
                    'results' => $execution['results'],
                    'reason' => $entry['reason'] ?? null,
                ];

                if ($entryConnection !== null) {
                    $step['connection'] = $entryConnection;
                }

                $executedSteps[] = $step;

                $batchResults[] = [
                    'query' => $entry['query'],
                    'results' => $execution['results'],
                ];

                $queriesWithConnection = $entryConnection !== null
                    ? array_map(fn($q) => array_merge($q, ['connection' => $entryConnection]), $execution['queries'])
                    : $execution['queries'];

                $debugQueries = array_merge($debugQueries, $queriesWithConnection);
            }

            if ($onStep !== null) {
                $reasons = array_filter(array_column($action['queries'], 'reason'));
                $message = implode('; ', $reasons) ?: 'Processing step ' . ($iteration + 1);
                $onStep($iteration + 1, $message);
            }

            $messages[] = ['role' => 'assistant', 'content' => $response];
            $messages[] = [
                'role' => 'user',
                'content' => 'Query results: ' . json_encode(
                    $batchResults,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        }

        if ($summary === null) {
            $messages[] = $this->promptBuilder->buildForceAnswerMessage($userLanguage);

            $aiResponse = $this->aiClient->chat($messages);
            $this->lastPromptTokens += $aiResponse->promptTokens;
            $this->lastCompletionTokens += $aiResponse->completionTokens;
            $response = $aiResponse->content;

            try {
                $action = $this->responseParser->parseAction($response);
                $summary = $action['action'] === 'answer'
                    ? $action['summary']
                    : trim($response);
            } catch (RuntimeException) {
                $summary = trim($response) ?: 'Sorry, I could not complete the analysis.';
            }
        }

        return [
            'summary' => $summary,
            'steps' => $executedSteps,
            'debug' => [
                'queries' => $debugQueries,
                'iterations' => count($executedSteps),
            ],
        ];
    }

    protected function resolveScopeConfig(?string $scope): array
    {
        $scopeName = $scope ?? 'default';
        $contexts = $this->config['contexts'] ?? [];

        if (isset($contexts[$scopeName]) && is_array($contexts[$scopeName])) {
            return array_replace_recursive($this->config, $contexts[$scopeName]);
        }

        return $this->config;
    }

    /**
     * @return array<int, array{name: string, description: string, columns: array}>
     */
    protected function resolveMetadata(array $scopeConfig): array
    {
        $mode = $scopeConfig['schema_mode'] ?? $this->config['schema_mode'] ?? 'manual';
        $configTables = $scopeConfig['tables'] ?? [];

        if (!is_array($configTables)) {
            $configTables = [];
        }

        $configTables = array_map(
            fn($t) => $t instanceof Table ? $t->toArray() : $t,
            $configTables,
        );

        $configTables = array_values(array_filter($configTables, fn($t) => is_array($t)));

        if ($mode === 'manual') {
            return $configTables;
        }

        $autoTables = [];
        if ($this->metadataLoader !== null) {
            $connection = $this->resolveConnection($scopeConfig['connection'] ?? null);
            $excludeTables = $scopeConfig['exclude_tables'] ?? [];

            if (is_string($excludeTables)) {
                $excludeTables = array_map('trim', explode(',', $excludeTables));
            }

            if (!is_array($excludeTables)) {
                $excludeTables = [];
            }

            $autoTables = $this->metadataLoader->load($connection, $excludeTables);
        }

        if ($mode === 'auto') {
            return $autoTables;
        }

        if ($configTables === []) {
            return $autoTables;
        }

        $merged = [];
        foreach ($autoTables as $table) {
            $key = strtolower($table['name'] ?? '');
            if ($key !== '') {
                $merged[$key] = $table;
            }
        }

        foreach ($configTables as $configTable) {
            $key = strtolower($configTable['name'] ?? '');
            if ($key === '') {
                continue;
            }

            if (isset($merged[$key])) {
                $merged[$key] = array_replace_recursive($merged[$key], $configTable);
            } else {
                $merged[$key] = $configTable;
            }
        }

        return array_values($merged);
    }

    /**
     * @return array<int, array{name: string, description: string, columns: array}>
     */
    protected function applySmartSchema(array $tables, string $question, array $scopeConfig): array
    {
        $threshold = $scopeConfig['smart_schema'] ?? $this->config['smart_schema'] ?? null;

        if ($threshold === null || $threshold === false) {
            return $tables;
        }

        $threshold = (int) $threshold;

        if ($threshold < 1 || count($tables) < $threshold) {
            return $tables;
        }

        $messages = $this->promptBuilder->buildSchemaFilterMessages($question, $tables);

        try {
            $aiResponse = $this->aiClient->chat($messages);
            $this->lastPromptTokens += $aiResponse->promptTokens;
            $this->lastCompletionTokens += $aiResponse->completionTokens;
            $selectedNames = $this->responseParser->parseTableSelection($aiResponse->content);
        } catch (RuntimeException) {
            return $tables;
        }

        if ($selectedNames === []) {
            return $tables;
        }

        $filtered = array_values(array_filter(
            $tables,
            fn(array $t) => in_array(strtolower($t['name'] ?? ''), $selectedNames, true),
        ));

        return $filtered !== [] ? $filtered : $tables;
    }

    protected function extractKnownTables(array $tables): array
    {
        return array_values(array_filter(array_map(
            fn(array $t) => strtolower($t['name'] ?? ''),
            $tables
        )));
    }

    public function getLastSchemaStats(): array
    {
        return $this->lastSchemaStats;
    }

    public function getLastTokenUsage(): array
    {
        return [
            'prompt_tokens' => $this->lastPromptTokens,
            'completion_tokens' => $this->lastCompletionTokens,
        ];
    }

    protected function normalizeId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = trim($id);

        return $id === '' ? null : $id;
    }

    protected function fillDefaultConnection(array $tables, ?string $scopeConnection): array
    {
        $hasExplicit = false;

        foreach ($tables as $table) {
            if (!empty($table['connection'])) {
                $hasExplicit = true;
                break;
            }
        }

        if (!$hasExplicit) {
            return $tables;
        }

        $defaultConnection = $scopeConnection ?? DB::getDefaultConnection();

        foreach ($tables as &$table) {
            if (empty($table['connection'])) {
                $table['connection'] = $defaultConnection;
            }
        }

        return $tables;
    }

    protected function resolveConnection(mixed $connection): ?string
    {
        if ($connection instanceof Closure) {
            $connection = $connection();
        }

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
