<?php

namespace LaraGrep\AiClients;

use Illuminate\Support\Facades\Http;
use LaraGrep\Contracts\AiClientInterface;
use RuntimeException;

class AnthropicClient implements AiClientInterface
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'claude-sonnet-4-20250514',
        protected int $maxTokens = 1024,
        protected string $baseUrl = 'https://api.anthropic.com/v1/messages',
        protected string $anthropicVersion = '2023-06-01',
    ) {
    }

    public function chat(array $messages): AiResponse
    {
        [$system, $prepared] = $this->prepareMessages($messages);

        if ($prepared === []) {
            throw new RuntimeException('No valid messages provided to Anthropic.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $prepared,
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->anthropicVersion,
        ])->post($this->baseUrl, $payload);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API call failed: ' . $response->body());
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('Failed to decode Anthropic response.');
        }

        $content = $this->extractContent($data);
        $usage = $data['usage'] ?? [];

        return new AiResponse(
            content: $content,
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
        );
    }

    /**
     * @return array{0: array|null, 1: array}
     */
    protected function prepareMessages(array $messages): array
    {
        $systemBlocks = [];
        $prepared = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? strtolower((string) $message['role']) : '';
            $text = $this->convertContentToString($message['content'] ?? '');

            if ($text === '') {
                continue;
            }

            if ($role === 'system') {
                $systemBlocks[] = [
                    'type' => 'text',
                    'text' => $text,
                    'cache_control' => ['type' => 'ephemeral'],
                ];
                continue;
            }

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $prepared[] = [
                'role' => $role,
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ];
        }

        return [
            $systemBlocks !== [] ? $systemBlocks : null,
            $prepared,
        ];
    }

    protected function extractContent(array $data): string
    {
        $content = $data['content'] ?? null;
        $segments = [];

        if (is_array($content)) {
            foreach ($content as $block) {
                if (!is_array($block)) {
                    continue;
                }

                if (($block['type'] ?? null) === 'text' && isset($block['text']) && is_string($block['text'])) {
                    $segments[] = $block['text'];
                }
            }
        }

        if ($segments === [] && isset($data['completion']) && is_string($data['completion'])) {
            $segments[] = $data['completion'];
        }

        if ($segments === []) {
            throw new RuntimeException('Anthropic did not return message content.');
        }

        return trim(implode("\n", $segments));
    }

    protected function convertContentToString(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $segments = [];

            foreach ($content as $segment) {
                if (is_string($segment)) {
                    $segments[] = $segment;
                } elseif (is_array($segment) && isset($segment['text']) && is_string($segment['text'])) {
                    $segments[] = $segment['text'];
                }
            }

            return trim(implode("\n", $segments));
        }

        return '';
    }
}
