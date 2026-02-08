<?php

namespace LaraGrep\Query;

use RuntimeException;

class QueryValidator
{
    /**
     * @param  string  $query
     * @param  array<int, string>  $knownTables  Lowercased known table names.
     *
     * @throws RuntimeException
     */
    public function validate(string $query, array $knownTables): void
    {
        $this->assertSelectOnly($query);
        $this->assertTablesExist($query, $knownTables);
    }

    protected function assertSelectOnly(string $query): void
    {
        if (!str_starts_with(strtolower(trim($query)), 'select')) {
            throw new RuntimeException('Only SELECT queries are allowed.');
        }
    }

    protected function assertTablesExist(string $query, array $knownTables): void
    {
        if ($knownTables === []) {
            return;
        }

        foreach ($this->extractTableNames($query) as $table) {
            if (!in_array($table, $knownTables, true)) {
                throw new RuntimeException(sprintf(
                    'Query references unknown table "%s".',
                    $table
                ));
            }
        }
    }

    /**
     * @return array<int, string> Lowercased table names found in FROM/JOIN clauses.
     */
    public function extractTableNames(string $query): array
    {
        $pattern = '/\b(?:from|join)\s+([`"\[]?[\w.]+[`"\]]?(?:\s+as)?(?:\s+[\w`"\[]+)*)/i';

        if (!preg_match_all($pattern, $query, $matches)) {
            return [];
        }

        return collect($matches[1] ?? [])
            ->map(function ($match) {
                $table = trim((string) $match);
                $table = preg_replace('/\s+as\s+.*/i', '', $table) ?? $table;
                $table = preg_split('/\s+/', $table)[0] ?? $table;
                $table = trim($table, "`\"[]");

                if (str_contains($table, '.')) {
                    $parts = explode('.', $table);
                    $table = end($parts) ?: $table;
                }

                return strtolower($table);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
