<?php

namespace LaraGrep\Recipe;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

class RecipeStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
    ) {
    }

    public function save(array $data): int
    {
        $data['created_at'] = $data['created_at'] ?? Carbon::now();

        return (int) $this->connection->table($this->table)->insertGetId($data);
    }

    public function find(int $id): ?object
    {
        return $this->connection->table($this->table)->find($id);
    }
}
