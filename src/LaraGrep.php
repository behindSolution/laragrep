<?php

namespace LaraGrep;

use LaraGrep\Contracts\AiClientInterface;
use LaraGrep\Contracts\ConversationStoreInterface;
use LaraGrep\Contracts\MetadataLoaderInterface;
use LaraGrep\Prompt\PromptBuilder;
use LaraGrep\Prompt\ResponseParser;
use LaraGrep\Query\QueryExecutor;
use LaraGrep\Query\QueryValidator;

class LaraGrep
{
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
        bool $debug = false,
        ?string $scope = null,
        ?string $conversationId = null,
    ): array {
        $scopeConfig = $this->resolveScopeConfig($scope);

        $this->queryExecutor->setConnection($scopeConfig['connection'] ?? null);

        $tables = $this->resolveMetadata($scopeConfig);

        $conversationId = $this->normalizeId($conversationId);
        $history = [];
        if ($conversationId !== null && $this->conversationStore !== null) {
            $history = $this->conversationStore->getMessages($conversationId);
        }

        $queryMessages = $this->promptBuilder->buildQueryMessages(
            question: $question,
            tables: $tables,
            userLanguage: $scopeConfig['user_language'] ?? $this->config['user_language'] ?? 'en',
            database: $scopeConfig['database'] ?? null,
            customSystemPrompt: $this->config['system_prompt'] ?? null,
            conversationHistory: $history,
        );

        $queryResponse = $this->aiClient->chat($queryMessages);

        $knownTables = array_map(
            fn(array $t) => strtolower($t['name'] ?? ''),
            $tables
        );
        $knownTables = array_values(array_filter($knownTables));

        $plan = $this->responseParser->parseQueryPlan($queryResponse, $knownTables);

        if ($plan['steps'] === []) {
            $summary = $plan['summary'] ?? 'Sorry, I can only help with read-only queries.';

            if ($conversationId !== null && $this->conversationStore !== null) {
                $this->conversationStore->appendExchange($conversationId, $question, $summary);
            }

            $answer = ['summary' => $summary];
            if ($debug) {
                $answer['steps'] = [];
                $answer['results'] = [];
            }

            return $answer;
        }

        $executedSteps = [];
        $debugQueries = [];

        foreach ($plan['steps'] as $step) {
            $this->queryValidator->validate($step['query'], $knownTables);
            $execution = $this->queryExecutor->execute($step['query'], $step['bindings'], $debug);

            $executedSteps[] = [
                'query' => $step['query'],
                'bindings' => $step['bindings'],
                'results' => $execution['results'],
            ];

            if ($debug) {
                $debugQueries = array_merge($debugQueries, $execution['queries']);
            }
        }

        $interpretMessages = $this->promptBuilder->buildInterpretationMessages(
            question: $question,
            executedSteps: $executedSteps,
            userLanguage: $scopeConfig['user_language'] ?? $this->config['user_language'] ?? 'en',
            interpretationPrompt: $this->config['interpretation_prompt'] ?? null,
        );

        $finalResponse = $this->aiClient->chat($interpretMessages);
        $summary = $this->responseParser->parseFinalResponse($finalResponse);

        if ($conversationId !== null && $this->conversationStore !== null) {
            $this->conversationStore->appendExchange($conversationId, $question, $summary);
        }

        $answer = ['summary' => $summary];

        if ($debug) {
            $answer['steps'] = $executedSteps;

            if ($executedSteps !== []) {
                $answer['bindings'] = $executedSteps[0]['bindings'];
                $answer['results'] = $executedSteps[0]['results'];
            } else {
                $answer['results'] = [];
            }

            $answer['debug'] = ['queries' => $debugQueries];
        }

        return $answer;
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
     * Resolve table metadata based on schema_mode setting.
     *
     * @return array<int, array{name: string, description: string, columns: array}>
     */
    protected function resolveMetadata(array $scopeConfig): array
    {
        $mode = $scopeConfig['schema_mode'] ?? $this->config['schema_mode'] ?? 'manual';
        $configTables = $scopeConfig['tables'] ?? [];

        if (!is_array($configTables)) {
            $configTables = [];
        }

        $configTables = array_values(array_filter($configTables, fn($t) => is_array($t)));

        if ($mode === 'manual') {
            return $configTables;
        }

        $autoTables = [];
        if ($this->metadataLoader !== null) {
            $connection = $scopeConfig['connection'] ?? null;
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

        // 'merged': auto-loaded as base, config overlays on top
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

    protected function normalizeId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = trim($id);

        return $id === '' ? null : $id;
    }
}
