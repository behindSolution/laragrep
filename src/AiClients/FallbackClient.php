<?php

namespace LaraGrep\AiClients;

use LaraGrep\Contracts\AiClientInterface;
use RuntimeException;
use Throwable;

class FallbackClient implements AiClientInterface
{
    /**
     * @param  AiClientInterface[]  $clients
     */
    public function __construct(
        protected array $clients,
    ) {
    }

    public function chat(array $messages): AiResponse
    {
        $lastException = null;

        foreach ($this->clients as $client) {
            try {
                return $client->chat($messages);
            } catch (Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new RuntimeException('No AI clients configured.');
    }
}
