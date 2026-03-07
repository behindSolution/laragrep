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

        if (config('laragrep.clarification.enabled', false) && $clarificationAnswers === null) {
            try {
                $clarification = ($this->recorder !== null)
                    ? $this->recorder->clarifyQuestion($question, $scope, $userId)
                    : $this->service->clarifyQuestion($question, $scope);

                if ($clarification !== null) {
                    $response = $clarification;

                    if ($conversationId !== null) {
                        $response['conversation_id'] = $conversationId;
                    }

                    return response()->json($response);
                }
            } catch (Throwable) {
                // Clarification failure should never block the main flow
            }
        }

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
                );
            } else {
                $answer = $this->service->answerQuestion(
                    $question,
                    $scope,
                    $conversationId,
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
}
