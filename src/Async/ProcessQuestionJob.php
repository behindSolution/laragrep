<?php

namespace LaraGrep\Async;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaraGrep\Contracts\RecipeStoreInterface;
use LaraGrep\Events\AnswerFailed;
use LaraGrep\Events\AnswerProgress;
use LaraGrep\Events\AnswerReady;
use LaraGrep\LaraGrep;
use LaraGrep\Monitor\MonitorRecorder;
use Throwable;

class ProcessQuestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param  array<string, string>  $globalFilters  Pre-resolved filters from the controller.
     * @param  array<string, mixed>  $aiOverrides  Snapshot of AI-related config (provider,
     *         api_key, model, base_url, max_tokens, timeout, anthropic_version, fallback.*)
     *         captured by the controller while in HTTP context. Restored into config inside
     *         handle() so the worker uses the same provider/model/key the request was
     *         dispatched with — middleware overrides survive the queue boundary.
     */
    public function __construct(
        public readonly string $queryId,
        public readonly string $question,
        public readonly ?string $scope,
        public readonly ?string $conversationId,
        public readonly string|int|null $userId,
        public readonly bool $debug,
        public readonly ?string $userLanguage = null,
        public readonly array $globalFilters = [],
        public readonly array $aiOverrides = [],
    ) {
        $this->timeout = (int) config('laragrep.timeout', 300);
    }

    public function handle(): void
    {
        if ($this->userLanguage !== null) {
            config()->set('laragrep.user_language', $this->userLanguage);
        }

        foreach ($this->aiOverrides as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            config()->set('laragrep.' . $key, $value);
        }
        $store = app(AsyncStore::class);
        $service = app(LaraGrep::class);
        $recorder = app(MonitorRecorder::class);
        $recipeStore = app(RecipeStoreInterface::class);

        $store->markProcessing($this->queryId);

        $onStep = function (int $iteration, string $message) use ($store) {
            $store->updateProgress($this->queryId, $message);
            AnswerProgress::dispatch($this->queryId, $iteration, $message);
        };

        if ($recorder !== null) {
            $answer = $recorder->answerQuestion(
                $this->question,
                $this->scope,
                $this->conversationId,
                $this->userId,
                $onStep,
                $this->globalFilters,
            );
        } else {
            $answer = $service->answerQuestion(
                $this->question,
                $this->scope,
                $this->conversationId,
                $onStep,
                $this->globalFilters,
            );
        }

        $recipeId = null;

        if ($recipeStore !== null) {
            try {
                $recipe = $service->extractRecipe($answer, $this->question, $this->scope);
                $recipeId = $recipeStore->save([
                    'conversation_id' => $this->conversationId,
                    'user_id' => $this->userId,
                    'scope' => $this->scope ?? 'default',
                    'question' => mb_substr($this->question, 0, 1000),
                    'summary' => $answer['summary'] ?? null,
                    'recipe' => json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable) {
                // Recipe storage must never break the query
            }
        }

        $result = ['summary' => $answer['summary'] ?? ''];

        if ($this->conversationId !== null) {
            $result['conversation_id'] = $this->conversationId;
        }

        if ($recipeId !== null) {
            $result['recipe_id'] = $recipeId;
        }

        $store->markCompleted($this->queryId, $result);

        AnswerReady::dispatch(
            $this->queryId,
            $result['summary'],
            $this->conversationId,
            $recipeId,
        );
    }

    public function failed(Throwable $e): void
    {
        try {
            $store = app(AsyncStore::class);
            $errorMessage = config(
                'laragrep.error_message',
                'Sorry, something went wrong while processing your question. Please try again.',
            );

            $store->markFailed($this->queryId, $errorMessage);

            AnswerFailed::dispatch(
                $this->queryId,
                $errorMessage,
            );
        } catch (Throwable) {
            // Cleanup must never throw
        }
    }
}
