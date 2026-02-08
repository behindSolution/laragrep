<?php

namespace LaraGrep\Contracts;

interface ConversationStoreInterface
{
    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function getMessages(string $conversationId): array;

    public function appendExchange(
        string $conversationId,
        string $userMessage,
        string $assistantMessage
    ): void;
}
