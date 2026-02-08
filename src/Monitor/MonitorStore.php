<?php

namespace LaraGrep\Monitor;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

class MonitorStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected int $retentionDays = 30,
    ) {
        $this->retentionDays = max(0, $this->retentionDays);

        $this->ensureTableExists();
        $this->ensureColumnsUpToDate();
    }

    public function record(array $data): void
    {
        $this->purgeExpired();

        $data['created_at'] = Carbon::now();

        $this->connection->table($this->table)->insert($data);
    }

    protected function purgeExpired(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $threshold = Carbon::now()->subDays($this->retentionDays);

        $this->connection->table($this->table)
            ->where('created_at', '<', $threshold)
            ->delete();
    }

    protected function ensureTableExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        if ($schema->hasTable($this->table)) {
            return;
        }

        $schema->create($this->table, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('question', 1000);
            $table->string('scope', 100)->default('default');
            $table->string('model', 100)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('conversation_id', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('success');
            $table->text('summary')->nullable();
            $table->text('steps');
            $table->string('error_message', 2000)->nullable();
            $table->string('error_class', 255)->nullable();
            $table->text('error_trace')->nullable();
            $table->float('duration_ms')->default(0);
            $table->unsignedSmallInteger('iterations')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('token_estimate')->default(0);
            $table->unsignedSmallInteger('tables_total')->nullable();
            $table->unsignedSmallInteger('tables_filtered')->nullable();
            $table->text('debug_queries');
            $table->timestamp('created_at')->nullable();

            $table->index('scope');
            $table->index('status');
            $table->index('user_id');
            $table->index('conversation_id');
            $table->index('created_at');
        });
    }

    /**
     * Add columns introduced after the initial release.
     * Safe to call on every boot — only alters when columns are missing.
     */
    protected function ensureColumnsUpToDate(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        if ($schema->hasColumn($this->table, 'model')) {
            return;
        }

        $schema->table($this->table, function (Blueprint $table) {
            $table->string('model', 100)->nullable();
            $table->string('provider', 50)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
        });
    }
}
