<?php

namespace LaraGrep\Monitor;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

class MonitorStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected int $retentionDays = 30,
    ) {
        $this->retentionDays = max(0, $this->retentionDays);
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
}
