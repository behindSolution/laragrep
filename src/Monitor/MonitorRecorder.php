<?php

namespace LaraGrep\Monitor;

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
        bool $debug = false,
        ?string $scope = null,
        ?string $conversationId = null,
        ?int $userId = null,
    ): array {
        $startTime = microtime(true);
        $error = null;
        $answer = [];

        try {
            // Always capture debug data internally for monitoring
            $answer = $this->laraGrep->answerQuestion($question, true, $scope, $conversationId);
        } catch (Throwable $e) {
            $error = $e;
        } finally {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $schemaStats = $this->laraGrep->getLastSchemaStats();
            $tokenUsage = $this->laraGrep->getLastTokenUsage();
            $steps = $answer['steps'] ?? [];

            try {
                $this->store->record([
                    'question' => mb_substr($question, 0, 1000),
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
                // Monitoring must never break the actual query
            }
        }

        if ($error) {
            throw $error;
        }

        // Strip debug data if the caller didn't request it
        if (!$debug) {
            unset($answer['steps'], $answer['bindings'], $answer['results'], $answer['debug']);
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
