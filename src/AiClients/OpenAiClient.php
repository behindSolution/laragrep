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
    ) {
    }

    public function chat(array $messages): string
    {
        $response = Http::withToken($this->apiKey)->post($this->baseUrl, [
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

        return trim($content);
    }
}
