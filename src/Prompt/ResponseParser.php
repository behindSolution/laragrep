<?php

namespace LaraGrep\Prompt;

use Illuminate\Support\Str;
use RuntimeException;

class ResponseParser
{
    /**
     * @param  string  $content  Raw AI text response.
     * @param  array<int, string>  $knownTables  Lowercased known table names for validation.
     * @return array{steps: array<int, array{query: string, bindings: array}>, summary: string|null}
     */
    public function parseQueryPlan(string $content, array $knownTables = []): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/im', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Language model response was not valid JSON.');
        }

        $steps = $decoded['steps'] ?? null;

        if (!is_array($steps)) {
            throw new RuntimeException('Language model response did not include query steps.');
        }

        $normalized = [];

        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw new RuntimeException('Language model response returned an invalid step.');
            }

            $query = isset($step['query']) ? trim((string) $step['query']) : '';

            if ($query === '') {
                throw new RuntimeException(sprintf(
                    'Language model response did not include a SQL query for step %d.',
                    $index + 1
                ));
            }

            if (!Str::startsWith(strtolower($query), 'select')) {
                throw new RuntimeException('Only SELECT queries are allowed.');
            }

            $bindings = $step['bindings'] ?? [];

            if (!is_array($bindings)) {
                throw new RuntimeException('Language model response provided invalid bindings.');
            }

            $normalized[] = [
                'query' => $query,
                'bindings' => array_values($bindings),
            ];
        }

        $summary = isset($decoded['summary']) && is_string($decoded['summary'])
            ? trim($decoded['summary'])
            : null;

        if ($normalized === [] && $summary === null) {
            throw new RuntimeException('Language model response did not include query steps.');
        }

        return [
            'steps' => $normalized,
            'summary' => $summary,
        ];
    }

    public function parseFinalResponse(string $content): string
    {
        $content = trim($content);

        if ($content === '') {
            throw new RuntimeException('Language model did not return a final answer.');
        }

        return $content;
    }
}
