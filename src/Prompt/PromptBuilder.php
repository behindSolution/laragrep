<?php

namespace LaraGrep\Prompt;

class PromptBuilder
{
    public function buildUserPrompt(string $question): string
    {
        return implode(PHP_EOL . PHP_EOL, [
            'You are a database assistant. Answer the user\'s question by executing SQL queries.',
            'You MUST respond with a single JSON object per turn. Choose one of two actions:',
            '1. Execute queries: {"action": "query", "queries": [{"query": "SELECT ...", "bindings": [], "reason": "Why this query is needed"}]}',
            '2. Provide the final answer: {"action": "answer", "summary": "Your human-readable answer here"}',
            'The "queries" array can contain one or more queries. Use multiple queries in a single turn when they are independent of each other (e.g., counting users and counting orders). Use separate turns when a query depends on the result of a previous one.',
            'Rules:'
            . PHP_EOL . '- Only generate parameterized SELECT statements. Never produce CREATE, INSERT, UPDATE, DELETE, DROP, ALTER, or any mutating command.'
            . PHP_EOL . '- Only reference tables explicitly listed in the schema. If a table is missing, use {"action": "answer", "summary": "<explain the limitation>"}.'
            . PHP_EOL . '- If the question cannot be answered with a query (out of scope, unsafe request, etc.), respond directly with {"action": "answer", "summary": "<polite explanation>"}.'
            . PHP_EOL . '- Write the "summary" in the user\'s language.'
            . PHP_EOL . '- After receiving query results, analyze them and decide: run more queries if needed, or provide the final answer.'
            . PHP_EOL . '- Do not mention SQL, queries, bindings, or technical terms in the final summary. Give a clear, business-oriented answer.'
            . PHP_EOL . '- A LIMIT clause is automatically applied to queries without one. If you need more rows, add an explicit LIMIT. For counting, always use COUNT(*) instead of fetching all rows.'
            . PHP_EOL . '- You can use these HTML tags in the summary: table, b, ul, ol, i, td, tr, th, thead, tbody. Do not use markdown.',
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

                $sections = array_filter([
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

        $messages[] = ['role' => 'user', 'content' => $this->buildUserPrompt($question)];

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
            $this->buildUserPrompt($question),
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
     * Build messages for formatting raw query results into an export-ready structure.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildFormatExportMessages(array $steps, string $summary, string $userLanguage = 'en'): array
    {
        $data = $this->summarizeStepsForFormat($steps);

        return [
            [
                'role' => 'system',
                'content' => 'You are a data formatting assistant. You receive raw SQL query results and a summary. Your job is to organize the data into clean tables ready for spreadsheet export. Respond with ONLY a JSON array of table objects.',
            ],
            [
                'role' => 'user',
                'content' => implode(PHP_EOL . PHP_EOL, [
                    'Summary: ' . $summary,
                    'Query results:',
                    $data,
                    'Organize this data into clean tables for spreadsheet export. Use clear column headers in ' . $userLanguage . '.',
                    'Respond with ONLY a JSON array: [{"title": "...", "headers": ["Col1", "Col2"], "rows": [["val1", "val2"], ...]}, ...]',
                    'Rules:',
                    '- Each distinct dataset should be a separate table object.',
                    '- Use human-readable headers (not raw column names). Translate to ' . $userLanguage . '.',
                    '- Format numbers and dates for readability.',
                    '- Exclude exploratory/intermediate data that is not relevant to the final answer.',
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
