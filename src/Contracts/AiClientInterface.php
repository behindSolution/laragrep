<?php

namespace LaraGrep\Contracts;

use LaraGrep\AiClients\AiResponse;

interface AiClientInterface
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws \RuntimeException
     */
    public function chat(array $messages): AiResponse;
}
