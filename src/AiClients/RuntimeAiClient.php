<?php

namespace LaraGrep\AiClients;

use Closure;
use LaraGrep\Contracts\AiClientInterface;

/**
 * Lazy AI client proxy that resolves the underlying client on each call,
 * reading fresh config every time.
 *
 * Why: the AI client is a singleton — bound once at boot. If a middleware
 * later mutates config (e.g. `config()->set('laragrep.api_key', $tenantKey)`)
 * the singleton already exists with the old values and ignores the change.
 *
 * This proxy hashes the relevant config keys per call. While the hash matches
 * the previously-built client, the same instance is reused (HTTP client and
 * any internal state stay warm). When it changes, a new client is built.
 *
 * The factory closure receives the fresh config array and returns the actual
 * AiClientInterface (typically OpenAI/Anthropic, optionally wrapped in
 * FallbackClient).
 */
class RuntimeAiClient implements AiClientInterface
{
    private ?AiClientInterface $cached = null;

    private ?string $cachedSignature = null;

    /**
     * @param  Closure(array): AiClientInterface  $factory
     * @param  Closure(): array  $configResolver
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly Closure $configResolver,
    ) {
    }

    public function chat(array $messages): AiResponse
    {
        $config = ($this->configResolver)();
        $signature = $this->signature(is_array($config) ? $config : []);

        if ($this->cached === null || $this->cachedSignature !== $signature) {
            $this->cached = ($this->factory)(is_array($config) ? $config : []);
            $this->cachedSignature = $signature;
        }

        return $this->cached->chat($messages);
    }

    private function signature(array $config): string
    {
        $relevant = [
            'provider' => $config['provider'] ?? null,
            'api_key' => $config['api_key'] ?? null,
            'model' => $config['model'] ?? null,
            'base_url' => $config['base_url'] ?? null,
            'max_tokens' => $config['max_tokens'] ?? null,
            'anthropic_version' => $config['anthropic_version'] ?? null,
            'timeout' => $config['timeout'] ?? null,
            'fallback' => $config['fallback'] ?? null,
        ];

        return md5(serialize($relevant));
    }
}
