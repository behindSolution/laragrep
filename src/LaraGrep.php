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
        $responseFormat = $scopeConfig['response_format'] ?? $this->config['response_format'] ?? 'html';
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

        $maxRows = (int) ($this->config['max_rows'] ?? 20);

        $messages = $this->promptBuilder->buildQueryMessages(
            question: $question,
            tables: $tables,
            userLanguage: $userLanguage,
            database: $scopeConfig['database'] ?? null,
            customSystemPrompt: $this->config['system_prompt'] ?? null,
            conversationHistory: $history,
            responseFormat: $responseFormat,
            maxRows: $maxRows,
        );

        $result = $this->runAgentLoop($messages, $knownTables, $maxIterations, $userLanguage, $onStep, $maxRows);

        if ($conversationId !== null && $this->conversationStore !== null) {
            $this->conversationStore->appendExchange($conversationId, $question, $result['summary']);
        }

        return $result;
    }

    /**
     * Analyze the question against clarification rules before running the agent loop.
     *
     * Returns null if the question is clear enough (proceed), or an array with
     * clarification questions if the AI determines the question is too vague.
     *
     * @return array{action: string, questions: string[], original_question: string}|null
     */
    public function clarifyQuestion(
        string $question,
        ?string $scope = null,
        ?string $conversationId = null,
    ): ?array {
        $scopeConfig = $this->resolveScopeConfig($scope);

        if (empty($this->config['clarification']['enabled'])) {
            return null;
        }

        $conversationId = $this->normalizeId($conversationId);
        $conversationHistory = [];

        if ($conversationId !== null && $this->conversationStore !== null) {
            $conversationHistory = $this->conversationStore->getMessages($conversationId);
        }

        $rules = $scopeConfig['clarification_rules'] ?? [];

        if (!is_array($rules) || $rules === []) {
            return null;
        }

        $rules = array_values(array_filter(
            array_map(fn($r) => is_string($r) ? trim($r) : '', $rules),
            fn($r) => $r !== '',
        ));

        if ($rules === []) {
            return null;
        }

        $this->lastPromptTokens = 0;
        $this->lastCompletionTokens = 0;

        $resolvedConnection = $this->resolveConnection($scopeConfig['connection'] ?? null);
        $tables = $this->resolveMetadata($scopeConfig);
        $tables = $this->fillDefaultConnection($tables, $resolvedConnection);

        $userLanguage = $scopeConfig['user_language'] ?? $this->config['user_language'] ?? 'en';

        $messages = $this->promptBuilder->buildClarificationMessages(
            question: $question,
            tables: $tables,
            rules: $rules,
            userLanguage: $userLanguage,
            conversationHistory: $conversationHistory,
        );

        $aiResponse = $this->aiClient->chat($messages);
        $this->lastPromptTokens += $aiResponse->promptTokens;
        $this->lastCompletionTokens += $aiResponse->completionTokens;

        $parsed = $this->responseParser->parseClarification($aiResponse->content);

        if ($parsed['action'] === 'proceed') {
            return null;
        }

        return [
            'action' => 'clarification',
            'questions' => $parsed['questions'],
            'original_question' => $question,
        ];
    }

    /**
     * Reformulate a vague question using clarification answers.
     *
     * Takes the original question and Q&A pairs from the user,
     * calls the AI to merge them into a single precise question.
     *
     * @param  string  $question  The original vague question
     * @param  array<int, array{question: string, answer: string}>  $answers  Clarification Q&A pairs
     * @param  string|null  $scope  Scope for language resolution
     * @return string  The reformulated question
     */
    public function reformulateQuestion(
        string $question,
        array $answers,
        ?string $scope = null,
    ): string {
        $this->lastPromptTokens = 0;
        $this->lastCompletionTokens = 0;

        $scopeConfig = $this->resolveScopeConfig($scope);
        $userLanguage = $scopeConfig['user_language'] ?? $this->config['user_language'] ?? 'en';

        $messages = $this->promptBuilder->buildReformulationMessages(
            question: $question,
            answers: $answers,
            userLanguage: $userLanguage,
        );

        $aiResponse = $this->aiClient->chat($messages);
        $this->lastPromptTokens += $aiResponse->promptTokens;
        $this->lastCompletionTokens += $aiResponse->completionTokens;

        return $this->responseParser->parseReformulation($aiResponse->content);
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
        $responseFormat = $scopeConfig['response_format'] ?? $this->config['response_format'] ?? 'html';
        $maxIterations = (int) ($this->config['max_iterations'] ?? 10);

        $resolvedConnection = $this->resolveConnection($scopeConfig['connection'] ?? null);
        $this->queryExecutor->setConnection($resolvedConnection);

        $tables = $this->resolveMetadata($scopeConfig);
        $tables = $this->fillDefaultConnection($tables, $resolvedConnection);
        $tablesTotal = count($tables);
        $this->lastSchemaStats = ['total' => $tablesTotal, 'filtered' => $tablesTotal];

        $knownTables = $this->extractKnownTables($tables);

        $maxRows = (int) ($this->config['max_rows'] ?? 20);

        $messages = $this->promptBuilder->buildReplayMessages(
            question: $question,
            tables: $tables,
            previousQueries: $previousQueries,
            userLanguage: $userLanguage,
            database: $scopeConfig['database'] ?? null,
            customSystemPrompt: $this->config['system_prompt'] ?? null,
            responseFormat: $responseFormat,
            maxRows: $maxRows,
        );

        return $this->runAgentLoop($messages, $knownTables, $maxIterations, $userLanguage, $onStep, $maxRows);
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
        int $maxRows = 0,
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
                    $this->queryValidator->validate($entry['query'], $knownTables, $maxRows);
                    $execution = $this->queryExecutor->execute($entry['query'], $entry['bindings'], $entryConnection);
                } catch (\Throwable $e) {
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

    /**
     * Resolve and filter suggestions relevant to the question.
     *
     * Returns only the suggestions that the AI considers relevant.
     * Returns an empty array if no suggestions are configured or none match.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function matchSuggestions(string $question, ?string $scope = null): array
    {
        $scopeConfig = $this->resolveScopeConfig($scope);
        $suggestions = $this->resolveSuggestions($scopeConfig);

        if ($suggestions === []) {
            return [];
        }

        return $this->filterSuggestions($question, $suggestions);
    }

    /**
     * Ask the AI which suggestions are relevant to the question.
     *
     * @param  array<int, array{label: string, description: string, url: string}>  $suggestions
     * @return array<int, array{label: string, url: string}>
     */
    protected function filterSuggestions(string $question, array $suggestions): array
    {
        try {
            $messages = $this->promptBuilder->buildSuggestionFilterMessages($question, $suggestions);

            $aiResponse = $this->aiClient->chat($messages);
            $this->lastPromptTokens += $aiResponse->promptTokens;
            $this->lastCompletionTokens += $aiResponse->completionTokens;

            $indexes = $this->responseParser->parseSuggestionFilter($aiResponse->content);

            $filtered = [];

            foreach ($indexes as $index) {
                if (isset($suggestions[$index])) {
                    $filtered[] = [
                        'label' => $suggestions[$index]['label'],
                        'url' => $suggestions[$index]['url'],
                    ];
                }
            }

            return $filtered;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{label: string, description: string, url: string}>
     */
    protected function resolveSuggestions(array $scopeConfig): array
    {
        $raw = $scopeConfig['suggestions'] ?? [];

        if (!is_array($raw) || $raw === []) {
            return [];
        }

        return array_values(array_filter(
            array_map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                $label = trim((string) ($item['label'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));
                $url = trim((string) ($item['url'] ?? ''));

                if ($label === '' || $url === '') {
                    return null;
                }

                return [
                    'label' => $label,
                    'description' => $description,
                    'url' => $url,
                ];
            }, $raw),
        ));
    }

    protected function resolveScopeConfig(?string $scope): array
    {
        $config = $this->getFreshConfig();
        $scopeName = $scope ?? 'default';
        $contexts = $config['contexts'] ?? [];

        if (isset($contexts[$scopeName]) && is_array($contexts[$scopeName])) {
            return array_replace_recursive($config, $contexts[$scopeName]);
        }

        return $config;
    }

    /**
     * Get fresh config from the Laravel container, falling back to the
     * captured config when running outside Laravel (e.g. unit tests).
     */
    protected function getFreshConfig(): array
    {
        if (function_exists('config')) {
            $fresh = config('laragrep');

            if (is_array($fresh) && $fresh !== []) {
                return $fresh;
            }
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
                $table['connection_default'] = true;
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
