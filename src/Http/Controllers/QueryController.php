<?php

namespace LaraGrep\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use LaraGrep\Async\AsyncStore;
use LaraGrep\Async\ProcessQuestionJob;
use LaraGrep\Contracts\RecipeStoreInterface;
use LaraGrep\LaraGrep;
use LaraGrep\Monitor\MonitorRecorder;
use Throwable;

class QueryController extends Controller
{
    public function __construct(
        protected LaraGrep $service,
        protected ?MonitorRecorder $recorder = null,
        protected ?RecipeStoreInterface $recipeStore = null,
    ) {
    }

    public function __invoke(Request $request, ?string $scope = null): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string'],
            'debug' => ['sometimes', 'boolean'],
            'conversation_id' => ['sometimes', 'nullable', 'string'],
            'clarification_answers' => ['sometimes', 'nullable', 'array'],
            'clarification_answers.*.question' => ['required_with:clarification_answers', 'string'],
            'clarification_answers.*.answer' => ['required_with:clarification_answers', 'string'],
        ]);

        $question = $validated['question'];

        $debug = array_key_exists('debug', $validated)
            ? (bool) $validated['debug']
            : (bool) config('laragrep.debug', false);

        $conversationId = $validated['conversation_id'] ?? null;

        if (is_string($conversationId)) {
            $conversationId = trim($conversationId);

            if ($conversationId === '') {
                $conversationId = null;
            }
        }

        $scope = ($scope === null || $scope === '') ? 'default' : $scope;

        $conversationEnabled = (bool) config('laragrep.conversation.enabled', true);

        if ($conversationEnabled && $conversationId === null) {
            $conversationId = (string) Str::uuid();
        }

        $userIdResolver = config('laragrep.user_id_resolver');
        $userId = is_callable($userIdResolver) ? $userIdResolver() : auth()->id();

        $clarificationAnswers = $validated['clarification_answers'] ?? null;

        if (is_array($clarificationAnswers)) {
            $clarificationAnswers = array_values(array_filter(
                $clarificationAnswers,
                fn(array $pair) => trim($pair['question'] ?? '') !== '' && trim($pair['answer'] ?? '') !== '',
            ));

            if ($clarificationAnswers === []) {
                $clarificationAnswers = null;
            }
        }

        if ($clarificationAnswers !== null) {
            try {
                $question = ($this->recorder !== null)
                    ? $this->recorder->reformulateQuestion($question, $clarificationAnswers, $scope, $userId)
                    : $this->service->reformulateQuestion($question, $clarificationAnswers, $scope);
            } catch (Throwable) {
                // Reformulation failure: fall back to original question
            }
        }

        // Filter suggestions independently (lightweight AI call)
        $suggestions = [];

        if ($clarificationAnswers === null) {
            try {
                $suggestions = ($this->recorder !== null)
                    ? $this->recorder->matchSuggestions($question, $scope, $userId)
                    : $this->service->matchSuggestions($question, $scope);
            } catch (Throwable) {
                // Suggestion filtering must never block the main flow
            }
        }

        if (config('laragrep.clarification.enabled', false) && $clarificationAnswers === null) {
            try {
                $clarification = ($this->recorder !== null)
                    ? $this->recorder->clarifyQuestion($question, $scope, $userId, $conversationId)
                    : $this->service->clarifyQuestion($question, $scope, $conversationId);

                if ($clarification !== null) {
                    $response = $clarification;

                    if ($suggestions !== []) {
                        $response['suggestions'] = $suggestions;
                    }

                    if ($conversationId !== null) {
                        $response['conversation_id'] = $conversationId;
                    }

                    return response()->json($response);
                }
            } catch (Throwable) {
                // Clarification failure should never block the main flow
            }
        }

        // Resolve global filters here, in HTTP context, while auth() / request()
        // are available. Closures that depend on request state cannot run safely
        // inside a queue worker, so we hand the resolved array of strings to the job.
        $globalFilters = $this->service->resolveGlobalFilters($scope);

        // Snapshot AI-related config as it stands NOW (after middleware may have
        // mutated it for this request — e.g. setting a tenant-specific api_key
        // or model). The job restores these into config before invoking LaraGrep,
        // so the worker uses the same provider/model the request was dispatched with.
        $aiOverrides = $this->collectAiOverrides();

        if (config('laragrep.async.enabled', false)) {
            $queryId = (string) Str::uuid();
            $store = app(AsyncStore::class);

            $store->create($queryId, [
                'user_id' => $userId,
                'scope' => $scope,
                'question' => mb_substr($question, 0, 1000),
                'conversation_id' => $conversationId,
            ]);

            ProcessQuestionJob::dispatch(
                $queryId, $question, $scope, $conversationId, $userId, $debug,
                config('laragrep.user_language'),
                $globalFilters,
                $aiOverrides,
            )->onQueue(config('laragrep.async.queue', 'default'))
             ->onConnection(config('laragrep.async.queue_connection'));

            $channelPrefix = config('laragrep.async.channel_prefix', 'laragrep');

            return response()->json([
                'query_id' => $queryId,
                'channel' => "{$channelPrefix}.{$queryId}",
            ], 202);
        }

        try {
            if ($this->recorder !== null) {
                $answer = $this->recorder->answerQuestion(
                    $question,
                    $scope,
                    $conversationId,
                    $userId,
                    null,
                    $globalFilters,
                );
            } else {
                $answer = $this->service->answerQuestion(
                    $question,
                    $scope,
                    $conversationId,
                    null,
                    $globalFilters,
                );
            }
        } catch (Throwable) {
            // MonitorRecorder already logged the real error.
            // Return a clean response so the frontend never breaks.
            $errorMessage = config('laragrep.error_message', 'Sorry, something went wrong while processing your question. Please try again.');

            $response = ['summary' => $errorMessage, 'error' => true];

            if ($conversationId !== null) {
                $response['conversation_id'] = $conversationId;
            }

            return response()->json($response, 500);
        }

        if ($suggestions !== []) {
            $answer['suggestions'] = $suggestions;
        }

        if ($conversationId !== null) {
            $answer['conversation_id'] = $conversationId;
        }

        // Auto-save recipe
        if ($this->recipeStore !== null) {
            try {
                $recipe = $this->service->extractRecipe($answer, $question, $scope);
                $recipeId = $this->recipeStore->save([
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'scope' => $scope,
                    'question' => mb_substr($question, 0, 1000),
                    'summary' => $answer['summary'] ?? null,
                    'recipe' => json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $answer['recipe_id'] = $recipeId;
            } catch (Throwable) {
                // Recipe storage must never break the query
            }
        }

        if (!$debug) {
            unset($answer['steps'], $answer['debug']);
        }

        return response()->json($answer);
    }

    /**
     * Capture the active AI-related config so the async job can restore it.
     * Only includes values that are non-null in config — keeps the payload
     * small and avoids overwriting worker defaults with stray nulls.
     *
     * @return array<string, mixed>
     */
    protected function collectAiOverrides(): array
    {
        $keys = [
            'provider',
            'api_key',
            'model',
            'base_url',
            'max_tokens',
            'timeout',
            'anthropic_version',
            'fallback.provider',
            'fallback.api_key',
            'fallback.model',
            'fallback.base_url',
        ];

        $snapshot = [];

        foreach ($keys as $key) {
            $value = config('laragrep.' . $key);

            if ($value !== null && $value !== '') {
                $snapshot[$key] = $value;
            }
        }

        return $snapshot;
    }
}
