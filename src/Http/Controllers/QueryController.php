<?php

namespace LaraGrep\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use LaraGrep\LaraGrep;
use LaraGrep\Monitor\MonitorRecorder;
use LaraGrep\Recipe\RecipeStore;
use Throwable;

class QueryController extends Controller
{
    public function __construct(
        protected LaraGrep $service,
        protected ?MonitorRecorder $recorder = null,
        protected ?RecipeStore $recipeStore = null,
    ) {
    }

    public function __invoke(Request $request, ?string $scope = null): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string'],
            'debug' => ['sometimes', 'boolean'],
            'conversation_id' => ['sometimes', 'nullable', 'string'],
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
