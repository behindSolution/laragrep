<?php

namespace LaraGrep\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class MonitorDiagnosticCommand extends Command
{
    protected $signature = 'laragrep:monitor-diagnostic';

    protected $description = 'Test monitor database connectivity and insert';

    public function handle(): int
    {
        $config = config('laragrep.monitor', []);

        $this->info('=== Monitor Config ===');
        $this->table(['Key', 'Value'], [
            ['enabled', var_export($config['enabled'] ?? false, true)],
            ['connection', $config['connection'] ?? '(default)'],
            ['table', $config['table'] ?? 'laragrep_logs'],
            ['retention_days', $config['retention_days'] ?? 30],
        ]);

        if (! ($config['enabled'] ?? false)) {
            $this->error('Monitor is DISABLED. Set LARAGREP_MONITOR_ENABLED=true');
            return 1;
        }

        $connectionName = $config['connection'] ?? null;
        $tableName = $config['table'] ?? 'laragrep_logs';

        try {
            $connection = is_string($connectionName) && $connectionName !== ''
                ? DB::connection($connectionName)
                : DB::connection();

            $driver = $connection->getDriverName();
            $database = $connection->getDatabaseName();

            $this->info("Connection: {$connectionName} (driver: {$driver}, database: {$database})");
        } catch (Throwable $e) {
            $this->error("Failed to connect: {$e->getMessage()}");
            return 1;
        }

        // Check table exists
        try {
            $exists = $connection->getSchemaBuilder()->hasTable($tableName);
            if (! $exists) {
                $this->error("Table '{$tableName}' does NOT exist in database '{$database}'");
                return 1;
            }
            $this->info("Table '{$tableName}' exists.");
        } catch (Throwable $e) {
            $this->error("Failed to check table: {$e->getMessage()}");
            return 1;
        }

        // List columns
        try {
            $columns = $connection->getSchemaBuilder()->getColumnListing($tableName);
            $this->info('Columns: ' . implode(', ', $columns));
        } catch (Throwable $e) {
            $this->warn("Could not list columns: {$e->getMessage()}");
        }

        // Try insert with realistic data
        $this->info('');
        $this->info('=== Testing INSERT ===');

        $data = [
            'question' => 'Diagnostic test question',
            'scope' => 'default',
            'model' => 'test-model',
            'provider' => 'openai',
            'conversation_id' => null,
            'user_id' => null,
            'status' => 'success',
            'summary' => 'This is a diagnostic test summary.',
            'steps' => json_encode([['action' => 'query', 'queries' => ['SELECT 1'], 'results' => [['1' => 1]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => null,
            'error_class' => null,
            'error_trace' => null,
            'duration_ms' => round(1234.56, 2),
            'iterations' => 1,
            'prompt_tokens' => 500,
            'completion_tokens' => 100,
            'token_estimate' => 600,
            'tables_total' => 5,
            'tables_filtered' => 3,
            'debug_queries' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => Carbon::now(),
        ];

        try {
            $connection->table($tableName)->insert($data);
            $this->info('INSERT OK!');
        } catch (Throwable $e) {
            $this->error('INSERT FAILED: ' . $e->getMessage());
            $this->newLine();
            $this->line($e->getTraceAsString());
            return 1;
        }

        // Verify
        try {
            $row = $connection->table($tableName)
                ->where('question', 'Diagnostic test question')
                ->first();

            if ($row) {
                $this->info('SELECT OK - Row ID: ' . $row->id);

                // Cleanup
                $connection->table($tableName)->where('id', $row->id)->delete();
                $this->info('Cleanup OK - diagnostic row deleted.');
            } else {
                $this->error('INSERT succeeded but SELECT returned no rows!');
                return 1;
            }
        } catch (Throwable $e) {
            $this->error('SELECT/DELETE failed: ' . $e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info('All checks passed. Monitor should be working.');
        $this->info('If it still does not record, the issue is in MonitorRecorder resolution.');

        // Check if MonitorRecorder resolves
        $this->newLine();
        $this->info('=== Checking DI Resolution ===');

        try {
            $recorder = app(\LaraGrep\Monitor\MonitorRecorder::class);
            if ($recorder === null) {
                $this->error('MonitorRecorder resolved to NULL. The store or recorder singleton is returning null.');

                $store = app(\LaraGrep\Monitor\MonitorStore::class);
                if ($store === null) {
                    $this->error('MonitorStore also resolved to NULL. Check that LARAGREP_MONITOR_ENABLED=true is set correctly.');
                } else {
                    $this->info('MonitorStore resolved OK.');
                }
            } else {
                $this->info('MonitorRecorder resolved OK: ' . get_class($recorder));
            }
        } catch (Throwable $e) {
            $this->error('DI resolution failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
