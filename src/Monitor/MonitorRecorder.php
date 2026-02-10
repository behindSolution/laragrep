<?php

namespace LaraGrep\Monitor;

use Closure;
use LaraGrep\LaraGrep;
use Throwable;

class MonitorRecorder
{
    protected const MAX_RESULTS_PER_STEP = 50;

    public function __construct(
        protected LaraGrep $laraGrep,
        protected MonitorStore $store,
        protected TokenEstimator $tokenEstimator,
        protected string $model = '',
        protected string $provider = '',
    ) {
    }

    public function answerQuestion(
        string $question,
        ?string $scope = null,
        ?string $conversationId = null,
        string|int|null $userId = null,
        ?Closure $onStep = null,
    ): array {
        return $this->recordExecution(
            fn () => $this->laraGrep->answerQuestion($question, $scope, $conversationId, $onStep),
            $question,
            $scope,
            $conversationId,
            $userId,
            'query',
        );
    }

    public function replayRecipe(
        array $recipe,
        string|int|null $userId = null,
        ?Closure $onStep = null,
    ): array {
        return $this->recordExecution(
            fn () => $this->laraGrep->replayRecipe($recipe, $onStep),
            $recipe['question'] ?? '',
            $recipe['scope'] ?? 'default',
            null,
            $userId,
            'replay',
        );
    }

    public function formatResult(
        array $answer,
        string $format,
    ): array {
        $result = $this->laraGrep->formatResult($answer, $format);

        // Token usage from formatResult is accumulated in LaraGrep
        // No separate monitoring entry — it's a lightweight formatting call

        return $result;
    }

    public function getLaraGrep(): LaraGrep
    {
        return $this->laraGrep;
    }

    protected function recordExecution(
        callable $operation,
        string $question,
        ?string $scope,
        ?string $conversationId,
        string|int|null $userId,
        string $type,
    ): array {
        $startTime = microtime(true);
        $error = null;
        $answer = [];

        try {
            $answer = $operation();
        } catch (Throwable $e) {
            $error = $e;
        } finally {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $schemaStats = $this->laraGrep->getLastSchemaStats();
            $tokenUsage = $this->laraGrep->getLastTokenUsage();
            $steps = $answer['steps'] ?? [];

            try {
                $this->store->record([
                    'question' => mb_substr(($type === 'replay' ? '[Replay] ' : '') . $question, 0, 1000),
                    'scope' => $scope ?? 'default',
                    'model' => $this->model ?: null,
                    'provider' => $this->provider ?: null,
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'status' => $error ? 'error' : 'success',
                    'summary' => $answer['summary'] ?? null,
                    'steps' => json_encode($this->truncateStepResults($steps), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'error_message' => $error ? mb_substr($error->getMessage(), 0, 2000) : null,
                    'error_class' => $error ? get_class($error) : null,
                    'error_trace' => $error?->getTraceAsString(),
                    'duration_ms' => round($durationMs, 2),
                    'iterations' => $answer['debug']['iterations'] ?? 0,
                    'prompt_tokens' => $tokenUsage['prompt_tokens'],
                    'completion_tokens' => $tokenUsage['completion_tokens'],
                    'token_estimate' => $this->tokenEstimator->estimateFromSteps($steps, $question, $answer['summary'] ?? ''),
                    'tables_total' => $schemaStats['total'] ?? null,
                    'tables_filtered' => $schemaStats['filtered'] ?? null,
                    'debug_queries' => json_encode($answer['debug']['queries'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable) {
                // Monitoring must never break the actual operation
            }
        }

        if ($error) {
            throw $error;
        }

        return $answer;
    }

    protected function truncateStepResults(array $steps): array
    {
        foreach ($steps as &$step) {
            if (isset($step['results']) && is_array($step['results']) && count($step['results']) > self::MAX_RESULTS_PER_STEP) {
                $step['results'] = array_slice($step['results'], 0, self::MAX_RESULTS_PER_STEP);
                $step['results_truncated'] = true;
            }
        }

        return $steps;
    }
}
