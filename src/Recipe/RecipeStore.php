<?php

namespace LaraGrep\Recipe;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

class RecipeStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected int $retentionDays = 30,
    ) {
        $this->retentionDays = max(0, $this->retentionDays);
    }

    public function save(array $data): int
    {
        $this->purgeExpired();

        $data['created_at'] = $data['created_at'] ?? Carbon::now();

        return (int) $this->connection->table($this->table)->insertGetId($data);
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

    public function find(int $id): ?object
    {
        return $this->connection->table($this->table)->find($id);
    }
}
