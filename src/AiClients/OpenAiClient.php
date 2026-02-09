<?php

namespace LaraGrep\AiClients;

use Illuminate\Support\Facades\Http;
use LaraGrep\Contracts\AiClientInterface;
use RuntimeException;

class OpenAiClient implements AiClientInterface
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'gpt-4o-mini',
        protected string $baseUrl = 'https://api.openai.com/v1/chat/completions',
        protected int $timeout = 120,
    ) {
    }

    public function chat(array $messages): AiResponse
    {
        $response = Http::withToken($this->apiKey)->timeout($this->timeout)->post($this->baseUrl, [
            'model' => $this->model,
            'messages' => $messages,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI API call failed: ' . $response->body());
        }

        $data = $response->json();

        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI did not return message content.');
        }

        $usage = $data['usage'] ?? [];

        return new AiResponse(
            content: trim($content),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
        );
    }
}
