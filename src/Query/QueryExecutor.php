<?php

namespace LaraGrep\Query;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class QueryExecutor
{
    public function __construct(
        protected ?string $connectionName = null,
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
    public function execute(string $query, array $bindings, bool $debug = false): array
    {
        return $this->usingConnection(function (ConnectionInterface $connection) use ($query, $bindings, $debug) {
            $queries = [];

            if ($debug) {
                $connection->flushQueryLog();
                $connection->enableQueryLog();
            }

            try {
                $results = collect($connection->select($query, $bindings))
                    ->map(fn($row) => (array) $row)
                    ->all();
            } finally {
                if ($debug) {
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
            }

            return [
                'results' => $results,
                'queries' => $queries,
            ];
        });
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
