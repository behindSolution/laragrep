<?php

namespace LaraGrep\AiClients;

class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
