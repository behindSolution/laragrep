<?php

namespace LaraGrep\Prompt;

use Illuminate\Support\Str;
use RuntimeException;

class ResponseParser
{
    /**
     * Parse the AI response from a schema filtering call.
     *
     * @return string[] List of table names the AI identified as relevant.
     *
     * @throws RuntimeException
     */
    public function parseTableSelection(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/im', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
            throw new RuntimeException('Schema filter response must be a JSON object with a "tables" array.');
        }

        return array_values(array_filter(
            array_map(fn($t) => is_string($t) ? strtolower(trim($t)) : '', $decoded['tables']),
            fn($t) => $t !== '',
        ));
    }

    /**
     * Parse an agent loop response into a structured action.
     *
     * @param  string  $content  Raw AI text response.
     * @return array{action: 'query'|'answer', queries?: array<int, array{query: string, bindings: array, reason: string|null}>, summary?: string}
     *
     * @throws RuntimeException
     */
    public function parseAction(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/im', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $firstJson = $this->extractFirstJson($content);

            if ($firstJson !== null) {
                $decoded = json_decode($firstJson, true);
            }
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Language model response was not valid JSON.');
        }

        $action = $decoded['action'] ?? null;

        if (!is_string($action) || !in_array($action, ['query', 'answer'], true)) {
            throw new RuntimeException('Language model response must include "action" as "query" or "answer".');
        }

        if ($action === 'answer') {
            $summary = isset($decoded['summary']) && is_string($decoded['summary'])
                ? trim($decoded['summary'])
                : '';

            if ($summary === '') {
                throw new RuntimeException('Language model returned an answer action without a summary.');
            }

            return [
                'action' => 'answer',
                'summary' => $summary,
            ];
        }

        // action === 'query'
        $queries = $decoded['queries'] ?? null;

        if (!is_array($queries) || $queries === []) {
            throw new RuntimeException('Language model returned a query action without a "queries" array.');
        }

        $normalized = [];

        foreach ($queries as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException(sprintf('Invalid query entry at index %d.', $index));
            }

            $query = isset($entry['query']) ? trim((string) $entry['query']) : '';

            if ($query === '') {
                throw new RuntimeException(sprintf('Empty SQL query at index %d.', $index));
            }

            $lower = strtolower($query);
            if (!Str::startsWith($lower, 'select') && !Str::startsWith($lower, 'with')) {
                throw new RuntimeException('Only SELECT queries are allowed.');
            }

            $bindings = $entry['bindings'] ?? [];

            if (!is_array($bindings)) {
                throw new RuntimeException(sprintf('Invalid bindings at index %d.', $index));
            }

            $reason = isset($entry['reason']) && is_string($entry['reason'])
                ? trim($entry['reason'])
                : null;

            $connection = isset($entry['connection']) && is_string($entry['connection'])
                ? trim($entry['connection'])
                : null;

            $item = [
                'query' => $query,
                'bindings' => array_values($bindings),
                'reason' => $reason,
            ];

            if ($connection !== null && $connection !== '') {
                $item['connection'] = $connection;
            }

            $normalized[] = $item;
        }

        return [
            'action' => 'query',
            'queries' => $normalized,
        ];
    }

    /**
     * Parse the AI response from a question reformulation call.
     *
     * @return string The reformulated question.
     *
     * @throws RuntimeException
     */
    public function parseReformulation(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:\w+)?\s*/im', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        if (preg_match('/^["\'](.+)["\']$/s', $content, $matches)) {
            $content = trim($matches[1]);
        }

        if ($content === '') {
            throw new RuntimeException('Reformulation response was empty.');
        }

        return $content;
    }

    /**
     * Parse the AI response from a clarification analysis call.
     *
     * @return array{action: 'clarification'|'proceed', questions?: string[]}
     *
     * @throws RuntimeException
     */
    public function parseClarification(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/im', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $firstJson = $this->extractFirstJson($content);

            if ($firstJson !== null) {
                $decoded = json_decode($firstJson, true);
            }
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Clarification response was not valid JSON.');
        }

        $action = $decoded['action'] ?? null;

        if (!is_string($action) || !in_array($action, ['clarification', 'proceed'], true)) {
            throw new RuntimeException('Clarification response must include "action" as "clarification" or "proceed".');
        }

        if ($action === 'proceed') {
            return ['action' => 'proceed'];
        }

        $questions = $decoded['questions'] ?? null;

        if (!is_array($questions) || $questions === []) {
            throw new RuntimeException('Clarification action must include a non-empty "questions" array.');
        }

        $filtered = array_values(array_filter(
            array_map(fn($q) => is_string($q) ? trim($q) : '', $questions),
            fn($q) => $q !== '',
        ));

        if ($filtered === []) {
            throw new RuntimeException('Clarification action must include a non-empty "questions" array.');
        }

        $result = [
            'action' => 'clarification',
            'questions' => $filtered,
        ];

        $suggestions = $decoded['suggestions'] ?? [];

        if (is_array($suggestions) && $suggestions !== []) {
            $result['suggestions'] = array_values(array_filter(
                array_map(function ($s) {
                    if (!is_array($s)) {
                        return null;
                    }

                    $label = trim((string) ($s['label'] ?? ''));
                    $url = trim((string) ($s['url'] ?? ''));

                    if ($label === '' || $url === '') {
                        return null;
                    }

                    return ['label' => $label, 'url' => $url];
                }, $suggestions),
            ));

            if ($result['suggestions'] === []) {
                unset($result['suggestions']);
            }
        }

        return $result;
    }

    private function extractFirstJson(string $content): ?string
    {
        $start = strpos($content, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($content);

        for ($i = $start; $i < $len; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
