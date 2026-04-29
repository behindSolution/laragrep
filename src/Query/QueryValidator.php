<?php

namespace LaraGrep\Query;

use RuntimeException;

class QueryValidator
{
    /**
     * @param  string  $query
     * @param  array<int, string>  $knownTables  Lowercased known table names.
     * @param  int  $maxRows  Maximum allowed LIMIT value (0 = no limit).
     * @param  array<string, string>  $globalFilters  Map of table => required SQL fragment.
     *
     * @throws RuntimeException
     */
    public function validate(string $query, array $knownTables, int $maxRows = 0, array $globalFilters = []): void
    {
        $this->assertSelectOnly($query);
        $this->assertTablesExist($query, $knownTables);
        $this->assertLimitWithinBounds($query, $maxRows);
        $this->assertGlobalFiltersApplied($query, $globalFilters);
    }

    /**
     * Ensure that every required global filter is present whenever its table
     * is referenced in the query. The fragment must appear verbatim — the AI
     * is instructed to copy it without modification.
     *
     * @param  array<string, string>  $globalFilters  Map of table => SQL fragment.
     */
    protected function assertGlobalFiltersApplied(string $query, array $globalFilters): void
    {
        if ($globalFilters === []) {
            return;
        }

        $tablesInQuery = $this->extractTableNames($query);

        if ($tablesInQuery === []) {
            return;
        }

        foreach ($globalFilters as $table => $fragment) {
            if (!is_string($table) || !is_string($fragment)) {
                continue;
            }

            $table = strtolower(trim($table));
            $fragment = trim($fragment);

            if ($table === '' || $fragment === '') {
                continue;
            }

            if (!in_array($table, $tablesInQuery, true)) {
                continue;
            }

            if (str_contains($query, $fragment)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Query references table "%s" but is missing the required global filter. '
                . 'Append this fragment to the WHERE clause exactly as written: %s',
                $table,
                $fragment
            ));
        }
    }

    protected function assertSelectOnly(string $query): void
    {
        $normalized = strtolower(trim($query));

        if (!str_starts_with($normalized, 'select') && !str_starts_with($normalized, 'with')) {
            throw new RuntimeException('Only SELECT queries are allowed.');
        }
    }

    protected function assertLimitWithinBounds(string $query, int $maxRows): void
    {
        if ($maxRows <= 0) {
            return;
        }

        $cleaned = $this->stripNonCode($query);

        if (preg_match('/\bLIMIT\s+(\d+)/i', $cleaned, $matches)) {
            $limit = (int) $matches[1];

            if ($limit > $maxRows) {
                throw new RuntimeException(sprintf(
                    'LIMIT %d exceeds the maximum allowed value of %d.',
                    $limit,
                    $maxRows
                ));
            }
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
        $cleaned = $this->stripNonCode($query);
        $cteAliases = $this->extractCteAliases($cleaned);

        $pattern = '/\b(?:from|join)\s+([`"\[]?[\w.]+[`"\]]?)/i';

        if (!preg_match_all($pattern, $cleaned, $matches)) {
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
            ->reject(fn(string $t) => in_array($t, $cteAliases, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Extract CTE alias names from WITH clauses.
     *
     * @return array<int, string> Lowercased CTE aliases.
     */
    protected function extractCteAliases(string $query): array
    {
        $aliases = [];

        // First CTE: WITH [RECURSIVE] name AS (
        if (preg_match_all('/\bWITH\s+(?:RECURSIVE\s+)?(\w+)\s+AS\s*\(/i', $query, $matches)) {
            $aliases = array_map('strtolower', $matches[1]);
        }

        // Additional CTEs: , name AS (
        if (preg_match_all('/,\s*(\w+)\s+AS\s*\(/i', $query, $matches)) {
            $aliases = array_merge($aliases, array_map('strtolower', $matches[1]));
        }

        return array_unique($aliases);
    }

    /**
     * Remove string literals and comments to avoid false table name matches.
     */
    protected function stripNonCode(string $query): string
    {
        // Remove block comments
        $query = preg_replace('/\/\*.*?\*\//s', '', $query) ?? $query;

        // Remove line comments
        $query = preg_replace('/--[^\n]*/', '', $query) ?? $query;

        // Remove string literals (single and double quoted)
        $query = preg_replace("/'[^']*'/", '', $query) ?? $query;
        $query = preg_replace('/"[^"]*"/', '', $query) ?? $query;

        return $query;
    }
}
