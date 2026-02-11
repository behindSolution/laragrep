<?php

namespace LaraGrep\Prompt;

class PromptBuilder
{
    public function buildUserPrompt(string $question, bool $hasMultipleConnections = false): string
    {
        $queryExample = $hasMultipleConnections
            ? '{"query": "SELECT ...", "bindings": [], "reason": "Why this query is needed", "connection": "connection_name"}'
            : '{"query": "SELECT ...", "bindings": [], "reason": "Why this query is needed"}';

        $connectionRules = $hasMultipleConnections
            ? PHP_EOL . '- Some tables are on different database connections. When querying a table that has a specific "Connection" defined in the schema, include its "connection" name in the query entry. Do not JOIN tables across different connections; query them separately and combine results in the final answer.'
            . PHP_EOL . '- When a table specifies an "Engine" (e.g., ClickHouse, PostgreSQL), write SQL compatible with that engine\'s dialect and capabilities.'
            : '';

        return implode(PHP_EOL . PHP_EOL, [
            'You are a database assistant. Answer the user\'s question by executing SQL queries.',
            'You MUST respond with a single JSON object per turn. Choose one of two actions:',
            '1. Execute queries: {"action": "query", "queries": [' . $queryExample . ']}',
            '2. Provide the final answer: {"action": "answer", "summary": "Your human-readable answer here"}',
            'The "queries" array can contain one or more queries. Use multiple queries in a single turn when they are independent of each other (e.g., counting users and counting orders). Use separate turns when a query depends on the result of a previous one.',
            'Rules:'
            . PHP_EOL . '- CRITICAL: Respond with EXACTLY ONE JSON object per turn. Never output multiple JSON objects, extra text, or any content outside the single JSON object.'
            . PHP_EOL . '- CRITICAL: NEVER fabricate, invent, or guess data. Only include data that was returned from actual query execution results. If you have not executed a query yet, do NOT include data in your answer.'
            . PHP_EOL . '- Only generate parameterized SELECT statements. Never produce CREATE, INSERT, UPDATE, DELETE, DROP, ALTER, or any mutating command.'
            . PHP_EOL . '- Only reference tables explicitly listed in the schema. If a table is missing, use {"action": "answer", "summary": "<explain the limitation>"}.'
            . PHP_EOL . '- If the question cannot be answered with a query (out of scope, unsafe request, etc.), respond directly with {"action": "answer", "summary": "<polite explanation>"}.'
            . PHP_EOL . '- Write the "summary" in the user\'s language.'
            . PHP_EOL . '- After receiving query results, analyze them and decide: run more queries if needed, or provide the final answer.'
            . PHP_EOL . '- Do not mention SQL, queries, bindings, or technical terms in the final summary. Give a clear, business-oriented answer.'
            . PHP_EOL . '- A LIMIT clause is automatically applied to queries without one. If you need more rows, add an explicit LIMIT. For counting, always use COUNT(*) instead of fetching all rows.'
            . PHP_EOL . '- You can use these HTML tags in the summary: table, b, ul, ol, i, td, tr, th, thead, tbody. Do not use markdown.'
            . $connectionRules,
            'Question: ' . $question,
        ]);
    }

    /**
     * @param  array  $tables
     * @param  string  $userLanguage
     * @param  array|null  $database  ['type' => '...', 'name' => '...']
     * @param  string|null  $customSystemPrompt
     */
    public function buildSystemPrompt(
        array $tables,
        string $userLanguage = 'en',
        ?array $database = null,
        ?string $customSystemPrompt = null,
    ): string {
        $metadataSummary = collect($tables)
            ->map(function (array $table) {
                $columnSummary = collect($table['columns'] ?? [])
                    ->map(function (array $column) {
                        $description = $column['description'] ?? '';
                        $type = $column['type'] ?? '';
                        $template = $column['template'] ?? null;

                        $line = sprintf(
                            '- %s (%s)%s',
                            $column['name'],
                            $type ?: 'unknown',
                            $description ? ': ' . $description : ''
                        );

                        if (is_string($template) && $template !== '') {
                            $line .= PHP_EOL . '  Example: ' . $template;
                        }

                        return $line;
                    })
                    ->implode(PHP_EOL);

                $relationshipSummary = collect($table['relationships'] ?? [])
                    ->map(function (array $relationship) {
                        $type = $relationship['type'] ?? 'unknown';
                        $relatedTable = $relationship['table'] ?? 'unknown';
                        $foreignKey = $relationship['foreign_key'] ?? null;

                        return sprintf(
                            '- %s %s%s',
                            $type,
                            $relatedTable,
                            $foreignKey ? sprintf(' (foreign key: %s)', $foreignKey) : ''
                        );
                    })
                    ->implode(PHP_EOL);

                $tableDescription = trim(($table['description'] ?? '') ?: '');
                $tableConnection = trim(($table['connection'] ?? '') ?: '');
                $tableEngine = trim(($table['engine'] ?? '') ?: '');

                $connectionLine = match (true) {
                    $tableConnection !== '' && $tableEngine !== '' => "Connection: {$tableConnection} (Engine: {$tableEngine})",
                    $tableConnection !== '' => "Connection: {$tableConnection}",
                    $tableEngine !== '' => "Engine: {$tableEngine}",
                    default => null,
                };

                $sections = array_filter([
                    $connectionLine,
                    $columnSummary !== '' ? "Columns:\n" . $columnSummary : null,
                    $relationshipSummary !== '' ? "Relationships:\n" . $relationshipSummary : null,
                ]);

                return sprintf(
                    "Table %s%s\n%s",
                    $table['name'],
                    $tableDescription ? ' — ' . $tableDescription : '',
                    implode(PHP_EOL . PHP_EOL, $sections)
                );
            })
            ->implode(PHP_EOL . PHP_EOL);

        $parts = array_filter([
            $this->buildDatabaseContextLine($database),
            'User language: ' . $userLanguage,
            'Available schema:',
            $metadataSummary,
        ]);

        $customSystemPrompt = is_string($customSystemPrompt) ? trim($customSystemPrompt) : '';

        if ($customSystemPrompt !== '') {
            array_unshift($parts, $customSystemPrompt);
        }

        return implode(PHP_EOL . PHP_EOL, $parts);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function buildQueryMessages(
        string $question,
        array $tables,
        string $userLanguage = 'en',
        ?array $database = null,
        ?string $customSystemPrompt = null,
        array $conversationHistory = [],
    ): array {
        $messages = [];

        $messages[] = [
            'role' => 'system',
            'content' => $this->buildSystemPrompt($tables, $userLanguage, $database, $customSystemPrompt),
        ];

        foreach ($conversationHistory as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;

            if (!is_string($role) || !is_string($content)) {
                continue;
            }

            $role = trim(strtolower($role));
            $content = trim($content);

            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        $messages[] = ['role' => 'user', 'content' => $this->buildUserPrompt($question, $this->hasMultipleConnections($tables))];

        return $messages;
    }

    /**
     * Build messages for replaying a saved recipe.
     * The AI receives the previous queries as context and adjusts parameters for the current execution.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildReplayMessages(
        string $question,
        array $tables,
        array $previousQueries,
        string $userLanguage = 'en',
        ?array $database = null,
        ?string $customSystemPrompt = null,
    ): array {
        $messages = [];

        $messages[] = [
            'role' => 'system',
            'content' => $this->buildSystemPrompt($tables, $userLanguage, $database, $customSystemPrompt),
        ];

        $recipeContext = collect($previousQueries)
            ->map(function (array $query, int $index) {
                $sql = $query['query'] ?? '';
                $bindings = $query['bindings'] ?? [];
                $reason = $query['reason'] ?? '';

                $line = sprintf('%d. %s', $index + 1, $sql);

                if ($bindings !== []) {
                    $line .= PHP_EOL . '   Bindings: ' . json_encode($bindings, JSON_UNESCAPED_UNICODE);
                }

                if ($reason !== '') {
                    $line .= PHP_EOL . '   Reason: ' . $reason;
                }

                return $line;
            })
            ->implode(PHP_EOL . PHP_EOL);

        $userContent = implode(PHP_EOL . PHP_EOL, [
            $this->buildUserPrompt($question, $this->hasMultipleConnections($tables)),
            'This question was previously answered using these queries:',
            $recipeContext,
            sprintf(
                'Today is %s. Re-execute these queries with parameters adjusted for the current date and context. '
                . 'If the queries are still appropriate, use them with updated bindings. '
                . 'If not, you may modify or add new queries as needed.',
                date('Y-m-d')
            ),
        ]);

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }

    /**
     * Build a message asking the AI to provide a final answer with whatever data it has so far.
     */
    public function buildForceAnswerMessage(string $userLanguage = 'en'): array
    {
        return [
            'role' => 'user',
            'content' => sprintf(
                'You have reached the maximum number of queries. Based on the data collected so far, provide your best final answer now. Respond with: {"action": "answer", "summary": "<your answer in %s>"}',
                $userLanguage
            ),
        ];
    }

    /**
     * Build messages for the schema filtering call.
     * The AI sees all tables (names + descriptions only) and identifies which ones are relevant.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildSchemaFilterMessages(string $question, array $tables): array
    {
        $tableList = collect($tables)
            ->map(function (array $table) {
                $name = $table['name'] ?? '';
                $description = trim(($table['description'] ?? '') ?: '');

                return $description !== ''
                    ? sprintf('- %s: %s', $name, $description)
                    : sprintf('- %s', $name);
            })
            ->implode(PHP_EOL);

        return [
            [
                'role' => 'system',
                'content' => 'You are a database schema analyst. Given a list of tables and a user question, identify which tables are needed to answer the question. Include tables that might be needed for JOINs or relationships, even if not directly mentioned in the question.',
            ],
            [
                'role' => 'user',
                'content' => implode(PHP_EOL . PHP_EOL, [
                    'Available tables:',
                    $tableList,
                    'Question: ' . $question,
                    'Respond with ONLY a JSON object: {"tables": ["table1", "table2", ...]}',
                ]),
            ],
        ];
    }

    /**
     * Build messages for formatting raw query results into a notification-ready structure.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildFormatNotificationMessages(array $steps, string $summary, string $userLanguage = 'en'): array
    {
        $data = $this->summarizeStepsForFormat($steps);

        return [
            [
                'role' => 'system',
                'content' => 'You are a data formatting assistant. You receive raw SQL query results and a summary. Your job is to create notification-ready content (for email, Slack, etc). Respond with ONLY a JSON object.',
            ],
            [
                'role' => 'user',
                'content' => implode(PHP_EOL . PHP_EOL, [
                    'Summary: ' . $summary,
                    'Query results:',
                    $data,
                    'Create notification-ready content in ' . $userLanguage . '.',
                    'Respond with ONLY a JSON object: {"title": "...", "html": "...", "text": "..."}',
                    'Rules:',
                    '- "title": A short, descriptive title for the notification.',
                    '- "html": Well-formatted HTML content with tables, bold text, and bullet points as needed. Use inline styles for email compatibility. Include the key data, not just the summary.',
                    '- "text": Plain text version of the same content, using pipes for tables and line breaks for structure. Suitable for Slack, SMS, or logs.',
                    '- Write everything in ' . $userLanguage . '.',
                    '- Focus on the final answer and key metrics, skip intermediate/exploratory data.',
                ]),
            ],
        ];
    }

    /**
     * Build messages for generating a single consolidated query for bulk export.
     * Instead of returning data, the AI returns the query itself for the developer
     * to execute with cursor/chunk (no memory limits).
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildFormatQueryMessages(array $steps, string $summary, string $userLanguage = 'en'): array
    {
        $queryContext = [];

        foreach ($steps as $i => $step) {
            $query = $step['query'] ?? '';
            $bindings = $step['bindings'] ?? [];
            $reason = $step['reason'] ?? 'Query ' . ($i + 1);
            $error = $step['error'] ?? null;

            if ($query === '' || $error !== null) {
                continue;
            }

            $line = sprintf('[%s] %s', $reason, $query);

            if ($bindings !== []) {
                $line .= PHP_EOL . 'Bindings: ' . json_encode($bindings, JSON_UNESCAPED_UNICODE);
            }

            $queryContext[] = $line;
        }

        $data = $queryContext !== [] ? implode(PHP_EOL . PHP_EOL, $queryContext) : 'No queries available.';

        return [
            [
                'role' => 'system',
                'content' => 'You are a SQL expert. You receive a set of queries that were used to answer a question. Your job is to consolidate them into a SINGLE optimized query that returns all the data needed. Respond with ONLY a JSON object.',
            ],
            [
                'role' => 'user',
                'content' => implode(PHP_EOL . PHP_EOL, [
                    'Question: ' . $summary,
                    'Queries used:',
                    $data,
                    'Consolidate into a single query optimized for bulk data export.',
                    'Respond with ONLY a JSON object: {"title": "...", "headers": ["Col1", "Col2"], "query": "SELECT ...", "bindings": [...]}',
                    'Rules:',
                    '- Write ONE single SELECT query that returns all the relevant data.',
                    '- Do NOT add LIMIT — the developer will handle pagination/streaming.',
                    '- Use clear column aliases that match the headers.',
                    '- "headers": human-readable column names in ' . $userLanguage . '.',
                    '- "title": a short title describing the dataset in ' . $userLanguage . '.',
                    '- "bindings": array of parameter values matching ? placeholders in the query.',
                    '- If the original queries used date filters, keep them with current values.',
                    '- Prefer JOINs over subqueries when possible.',
                ]),
            ],
        ];
    }

    protected function summarizeStepsForFormat(array $steps): string
    {
        $parts = [];

        foreach ($steps as $i => $step) {
            $results = $step['results'] ?? [];

            if (!is_array($results) || $results === []) {
                continue;
            }

            $reason = $step['reason'] ?? 'Query ' . ($i + 1);
            $json = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $parts[] = sprintf('[%s] %s', $reason, $json);
        }

        return $parts !== [] ? implode(PHP_EOL, $parts) : 'No query results available.';
    }

    protected function hasMultipleConnections(array $tables): bool
    {
        foreach ($tables as $table) {
            if (isset($table['connection']) && trim((string) $table['connection']) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function buildDatabaseContextLine(?array $database): ?string
    {
        if (!is_array($database)) {
            return null;
        }

        $type = isset($database['type']) ? trim((string) $database['type']) : '';
        $name = isset($database['name']) ? trim((string) $database['name']) : '';

        if ($type === '' && $name === '') {
            return null;
        }

        if ($type !== '' && $name !== '') {
            return sprintf('Database: %s — %s', $type, $name);
        }

        return sprintf('Database: %s', $type !== '' ? $type : $name);
    }
}
