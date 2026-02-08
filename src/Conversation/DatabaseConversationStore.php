<?php

namespace LaraGrep\Conversation;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LaraGrep\Contracts\ConversationStoreInterface;

use function collect;

class DatabaseConversationStore implements ConversationStoreInterface
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected int $maxMessages = 10,
        protected int $ttlDays = 10
    ) {
        $this->maxMessages = max(1, $this->maxMessages);
        $this->ttlDays = max(0, $this->ttlDays);

        $this->ensureTableExists();
    }

    public function getMessages(string $conversationId): array
    {
        $conversationId = trim($conversationId);

        if ($conversationId === '') {
            return [];
        }

        $this->purgeExpired();

        /** @var Collection<int, object> $rows */
        $rows = $this->connection->table($this->table)
            ->select(['role', 'content'])
            ->where('context', $conversationId)
            ->orderByDesc('id')
            ->limit($this->maxMessages)
            ->get();

        return collect($rows)
            ->reverse()
            ->map(fn($row) => [
                'role' => (string) ($row->role ?? ''),
                'content' => (string) ($row->content ?? ''),
            ])
            ->filter(fn(array $message) => $message['role'] !== '' && $message['content'] !== '')
            ->values()
            ->all();
    }

    public function appendExchange(string $conversationId, string $userMessage, string $assistantMessage): void
    {
        $conversationId = trim($conversationId);

        if ($conversationId === '') {
            return;
        }

        $this->purgeExpired();

        $now = Carbon::now();

        $entries = [];

        $userMessage = trim($userMessage);
        $assistantMessage = trim($assistantMessage);

        if ($userMessage !== '') {
            $entries[] = [
                'context' => $conversationId,
                'role' => 'user',
                'content' => $userMessage,
                'created_at' => $now,
            ];
        }

        if ($assistantMessage !== '') {
            $entries[] = [
                'context' => $conversationId,
                'role' => 'assistant',
                'content' => $assistantMessage,
                'created_at' => $now,
            ];
        }

        if ($entries === []) {
            return;
        }

        $this->connection->transaction(function () use ($conversationId, $entries) {
            $this->connection->table($this->table)->insert($entries);

            $this->trimContext($conversationId);
        });
    }

    protected function trimContext(string $conversationId): void
    {
        /** @var Collection<int, int> $excess */
        $excess = $this->connection->table($this->table)
            ->where('context', $conversationId)
            ->orderByDesc('id')
            ->limit(PHP_INT_MAX)
            ->offset($this->maxMessages)
            ->pluck('id');

        if ($excess->isEmpty()) {
            return;
        }

        $this->connection->table($this->table)
            ->whereIn('id', $excess->all())
            ->delete();
    }

    protected function purgeExpired(): void
    {
        if ($this->ttlDays <= 0) {
            return;
        }

        $threshold = Carbon::now()->subDays($this->ttlDays);

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
            $table->string('context', 255);
            $table->string('role', 32);
            $table->text('content');
            $table->timestamp('created_at')->nullable();
            $table->index('context');
            $table->index('created_at');
        });
    }
}
