<?php

namespace LaraGrep\Query;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class QueryExecutor
{
    public function __construct(
        protected ?string $connectionName = null,
        protected int $maxRows = 20,
        protected int $maxQueryTime = 3,
    ) {
    }

    public function setConnection(?string $connectionName): void
    {
        $this->connectionName = $connectionName;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array{results: array<int, array<string, mixed>>, queries: array<int, array<string, mixed>>}
     */
    public function execute(string $query, array $bindings): array
    {
        $query = $this->applyRowLimit($query);

        return $this->usingConnection(function (ConnectionInterface $connection) use ($query, $bindings) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();

            $this->applyQueryTimeout($connection);

            try {
                $results = collect($connection->select($query, $bindings))
                    ->map(fn($row) => (array) $row)
                    ->all();
            } finally {
                $queries = collect($connection->getQueryLog())
                    ->map(fn(array $entry) => [
                        'query' => $entry['query'] ?? '',
                        'bindings' => $entry['bindings'] ?? [],
                        'time' => $entry['time'] ?? null,
                    ])
                    ->all();

                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }

            return [
                'results' => $results,
                'queries' => $queries,
            ];
        });
    }

    /**
     * Inject a LIMIT clause if the query doesn't already have one.
     */
    protected function applyRowLimit(string $query): string
    {
        if ($this->maxRows <= 0) {
            return $query;
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($query)));

        if (str_contains($normalized, ' limit ')) {
            return $query;
        }

        return rtrim($query, "; \t\n\r") . ' LIMIT ' . $this->maxRows;
    }

    /**
     * Set a statement-level timeout on the connection.
     */
    protected function applyQueryTimeout(ConnectionInterface $connection): void
    {
        if ($this->maxQueryTime <= 0) {
            return;
        }

        $driver = $connection->getDriverName();
        $seconds = $this->maxQueryTime;

        match ($driver) {
            'mysql' => $connection->statement("SET SESSION MAX_EXECUTION_TIME = " . ($seconds * 1000)),
            'mariadb' => $connection->statement("SET SESSION max_statement_time = {$seconds}"),
            'pgsql' => $connection->statement("SET statement_timeout = " . ($seconds * 1000)),
            'sqlite' => $connection->getPdo()->setAttribute(\PDO::ATTR_TIMEOUT, $seconds),
            default => null,
        };
    }

    /**
     * @template T
     * @param  callable(ConnectionInterface): T  $callback
     * @return T
     */
    protected function usingConnection(callable $callback): mixed
    {
        $previous = null;
        $shouldRestore = false;

        if (is_string($this->connectionName) && $this->connectionName !== '') {
            $previous = DB::getDefaultConnection();
            $shouldRestore = $previous !== $this->connectionName;

            if ($shouldRestore) {
                DB::setDefaultConnection($this->connectionName);
            }

            $connection = DB::connection($this->connectionName);
        } else {
            $connection = DB::connection();
        }

        try {
            return $callback($connection);
        } finally {
            if ($shouldRestore && $previous !== null) {
                DB::setDefaultConnection($previous);
            }
        }
    }
}
