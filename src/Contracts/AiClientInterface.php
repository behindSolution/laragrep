<?php

namespace LaraGrep\Contracts;

interface AiClientInterface
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return string The raw text content from the model's response.
     *
     * @throws \RuntimeException
     */
    public function chat(array $messages): string;
}
