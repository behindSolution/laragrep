<?php

namespace LaraGrep\Async;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

class AsyncStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected int $retentionHours = 24,
    ) {
        $this->retentionHours = max(1, $this->retentionHours);
    }

    public function create(string $id, array $data): void
    {
        $this->purgeExpired();

        $now = Carbon::now();

        $this->connection->table($this->table)->insert(array_merge($data, [
            'id' => $id,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public function find(string $id): ?object
    {
        return $this->connection->table($this->table)->where('id', $id)->first();
    }

    public function markProcessing(string $id): void
    {
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => 'processing',
                'updated_at' => Carbon::now(),
            ]);
    }

    public function updateProgress(string $id, string $message): void
    {
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'progress' => $message,
                'updated_at' => Carbon::now(),
            ]);
    }

    public function markCompleted(string $id, array $result): void
    {
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => 'completed',
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => Carbon::now(),
            ]);
    }

    public function markFailed(string $id, string $error): void
    {
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => 'failed',
                'error' => $error,
                'updated_at' => Carbon::now(),
            ]);
    }

    protected function purgeExpired(): void
    {
        $threshold = Carbon::now()->subHours($this->retentionHours);

        $this->connection->table($this->table)
            ->where('created_at', '<', $threshold)
            ->delete();
    }
}
