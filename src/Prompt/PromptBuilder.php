<?php

namespace LaraGrep\Prompt;

class PromptBuilder
{
    public function buildUserPrompt(string $question): string
    {
        return implode(PHP_EOL . PHP_EOL, array_filter([
            'Use the available schema to produce one or more safe SQL SELECT queries that answer the user\'s question. If multiple steps are required, describe them in the order they should be executed.',
            'Respond strictly in JSON with the format {"steps": [{"query": "...", "bindings": []}, ...]}. Only generate parameterized SELECT statements and never produce CREATE, INSERT, UPDATE, DELETE, DROP, ALTER, or any other mutating commands. If the user requests any write operation or an unsafe action, respond instead with {"steps": [], "summary": "<polite refusal in the user language>"}.',
            'If the question can be answered without running a database query (for example, it only references prior conversation, is outside the scope of the schema, or requests unsupported data), respond with {"steps": [], "summary": "<clear explanation in the user language>"}.',
            'Only reference tables that are explicitly listed in the schema summary. If the necessary table is missing, do not guess—return {"steps": [], "summary": "<explain the limitation in the user language>"}.',
            'Question: ' . $question,
        ]));
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

                        return sprintf(
                            '- %s (%s)%s',
                            $column['name'],
                            $type ?: 'unknown',
                            $description ? ': ' . $description : ''
                        );
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
     * @param  array<int, array{query: string, bindings: array, results: array}>  $executedSteps
     * @return array<int, array{role: string, content: string}>
     */
    public function buildInterpretationMessages(
        string $question,
        array $executedSteps,
        string $userLanguage = 'en',
        ?string $interpretationPrompt = null,
    ): array {
        $messages = [];

        $systemPrompt = is_string($interpretationPrompt) ? trim($interpretationPrompt) : '';

        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $stepsForModel = array_map(
            fn(array $step) => [
                'query' => $step['query'],
                'bindings' => array_values($step['bindings']),
                'results' => $step['results'],
            ],
            $executedSteps
        );

        $messages[] = [
            'role' => 'user',
            'content' => implode(PHP_EOL . PHP_EOL, [
                'Original question: ' . $question,
                'Executed queries (JSON): ' . json_encode($stepsForModel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                sprintf(
                    'Provide a concise, business-oriented summary in %s that only reports the requested result. Do not mention SQL, queries, bindings, code, or technical terms. Explain what the numbers mean only if the user explicitly asked for that. If the list is empty, politely state that no records were found. You can only use those html tags: table,b,ul,ol,i,td,tr. Do not use markdown!',
                    $userLanguage
                ),
            ]),
        ];

        return $messages;
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
