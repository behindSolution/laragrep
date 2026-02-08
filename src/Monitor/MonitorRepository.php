<?php

namespace LaraGrep\Monitor;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MonitorRepository
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
    ) {
    }

    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->connection->table($this->table)
            ->select([
                'id', 'question', 'scope', 'model', 'provider', 'status', 'user_id',
                'duration_ms', 'iterations', 'prompt_tokens', 'completion_tokens',
                'token_estimate', 'conversation_id', 'created_at',
            ])
            ->orderByDesc('created_at');

        if (!empty($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $query->where('question', 'like', '%' . $filters['search'] . '%');
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): ?object
    {
        return $this->connection->table($this->table)->find($id);
    }

    public function overview(int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);
        $base = $this->connection->table($this->table)
            ->where('created_at', '>=', $since);

        return [
            'total_queries' => (clone $base)->count(),
            'success_count' => (clone $base)->where('status', 'success')->count(),
            'error_count' => (clone $base)->where('status', 'error')->count(),
            'avg_duration_ms' => round((clone $base)->avg('duration_ms') ?? 0, 2),
            'max_duration_ms' => round((clone $base)->max('duration_ms') ?? 0, 2),
            'total_prompt_tokens' => (int) (clone $base)->sum('prompt_tokens'),
            'total_completion_tokens' => (int) (clone $base)->sum('completion_tokens'),
            'total_tokens_estimate' => (int) (clone $base)->sum('token_estimate'),
            'avg_iterations' => round((clone $base)->avg('iterations') ?? 0, 1),
            'unique_users' => (clone $base)->distinct()->count('user_id'),
            'top_scopes' => $this->topScopes($since),
            'recent_errors' => $this->recentErrors($since),
            'daily_usage' => $this->dailyUsage($since),
            'storage' => $this->storageMetrics(),
        ];
    }

    public function distinctScopes(): array
    {
        return $this->connection->table($this->table)
            ->distinct()
            ->pluck('scope')
            ->all();
    }

    protected function topScopes(Carbon $since): Collection
    {
        return $this->connection->table($this->table)
            ->where('created_at', '>=', $since)
            ->selectRaw('scope, count(*) as total')
            ->groupBy('scope')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
    }

    protected function recentErrors(Carbon $since): Collection
    {
        return $this->connection->table($this->table)
            ->where('created_at', '>=', $since)
            ->where('status', 'error')
            ->select(['id', 'question', 'error_message', 'created_at'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    protected function dailyUsage(Carbon $since): Collection
    {
        return $this->connection->table($this->table)
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE(created_at) as date, count(*) as total, sum(case when status='error' then 1 else 0 end) as errors")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();
    }

    protected function storageMetrics(): array
    {
        $totalRows = $this->connection->table($this->table)->count();

        $dbSizeBytes = null;

        try {
            $pdo = $this->connection->getPdo();
            $result = $pdo->query('PRAGMA database_list')->fetch();
            $dbPath = $result['file'] ?? null;

            if (is_string($dbPath) && $dbPath !== '' && is_file($dbPath)) {
                $dbSizeBytes = filesize($dbPath);
            }
        } catch (\Throwable) {
            // Not SQLite or not accessible
        }

        return [
            'total_rows' => $totalRows,
            'db_size_bytes' => $dbSizeBytes,
        ];
    }
}
