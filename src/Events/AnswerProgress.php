<?php

namespace LaraGrep\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class AnswerProgress implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $queryId,
        public readonly int $iteration,
        public readonly string $message,
    ) {
    }

    /**
     * @return array<int, Channel|PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $prefix = config('laragrep.async.channel_prefix', 'laragrep');
        $channelName = "{$prefix}.{$this->queryId}";

        return [
            config('laragrep.async.private', false)
                ? new PrivateChannel($channelName)
                : new Channel($channelName),
        ];
    }

    public function broadcastAs(): string
    {
        return 'laragrep.answer.progress';
    }

    public function broadcastWhen(): bool
    {
        return $this->hasBroadcastDriver();
    }

    private function hasBroadcastDriver(): bool
    {
        $driver = config('broadcasting.default');

        return is_string($driver) && $driver !== '' && $driver !== 'null' && $driver !== 'log';
    }
}
