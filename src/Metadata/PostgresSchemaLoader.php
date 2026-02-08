<?php

namespace LaraGrep\Metadata;

use Illuminate\Database\ConnectionResolverInterface;
use LaraGrep\Contracts\MetadataLoaderInterface;

use function collect;

class PostgresSchemaLoader implements MetadataLoaderInterface
{
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        protected ?string $connection = null,
        protected array $excludeTables = [],
    ) {
    }

    public function load(?string $connection = null, ?array $excludeTables = null): array
    {
        $connectionName = $connection ?? $this->connection;
        $excludeTables = $excludeTables ?? $this->excludeTables;

        $previous = null;
        $shouldRestore = false;

        if (is_string($connectionName) && $connectionName !== '') {
            if (method_exists($this->resolver, 'getDefaultConnection')) {
                $previous = $this->resolver->getDefaultConnection();
                $shouldRestore = $previous !== $connectionName;
            }

            if ($shouldRestore) {
                $this->resolver->setDefaultConnection($connectionName);
            }
        }

        try {
            $connection = $this->resolver->connection($connectionName);
        } finally {
            if ($shouldRestore && $previous !== null) {
                $this->resolver->setDefaultConnection($previous);
            }
        }

        $schema = 'public';

        $tables = collect($connection->select(
            "SELECT table_name, obj_description((quote_ident(table_schema) || '.' || quote_ident(table_name))::regclass) as table_comment
             FROM information_schema.tables
             WHERE table_schema = ? AND table_type = 'BASE TABLE'
             ORDER BY table_name",
            [$schema]
        ));

        $excluded = collect($excludeTables)
            ->filter()
            ->map(fn($name) => strtolower((string) $name))
            ->unique()
            ->values();

        $tables = $tables->filter(function ($table) use ($excluded) {
            $tableName = $table->table_name ?? null;

            if (!$tableName) {
                return false;
            }

            return !$excluded->contains(strtolower($tableName));
        });

        $columns = collect($connection->select(
            "SELECT c.table_name, c.column_name, c.data_type, c.udt_name,
                    c.character_maximum_length, c.numeric_precision, c.numeric_scale,
                    pgd.description as column_comment
             FROM information_schema.columns c
             LEFT JOIN pg_catalog.pg_statio_all_tables st ON st.relname = c.table_name AND st.schemaname = c.table_schema
             LEFT JOIN pg_catalog.pg_description pgd ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
             WHERE c.table_schema = ?
             ORDER BY c.table_name, c.ordinal_position",
            [$schema]
        ));

        $tableData = $tables->mapWithKeys(function ($table) {
            $tableName = $table->table_name ?? null;

            if (!$tableName) {
                return [];
            }

            return [
                $tableName => [
                    'name' => $tableName,
                    'description' => (string) ($table->table_comment ?? ''),
                    'columns' => [],
                ],
            ];
        })->all();

        $columns->each(function ($column) use (&$tableData) {
            $tableName = $column->table_name ?? null;
            $columnName = $column->column_name ?? null;

            if (!$tableName || !$columnName || !isset($tableData[$tableName])) {
                return;
            }

            $tableData[$tableName]['columns'][] = [
                'name' => $columnName,
                'type' => $this->formatColumnType($column),
                'description' => (string) ($column->column_comment ?? ''),
            ];
        });

        return collect($tableData)->map(function ($table) {
            $table['columns'] = collect($table['columns'])
                ->sortBy('name')
                ->values()
                ->all();

            return $table;
        })->values()->all();
    }

    protected function formatColumnType(object $column): string
    {
        $type = $column->data_type ?? $column->udt_name ?? 'unknown';

        if ($type === 'character varying') {
            $length = $column->character_maximum_length ?? null;
            return $length ? "varchar({$length})" : 'varchar';
        }

        if ($type === 'numeric') {
            $precision = $column->numeric_precision ?? null;
            $scale = $column->numeric_scale ?? null;
            if ($precision && $scale) {
                return "numeric({$precision},{$scale})";
            }
            return 'numeric';
        }

        if ($type === 'USER-DEFINED') {
            return $column->udt_name ?? 'unknown';
        }

        return $type;
    }
}
