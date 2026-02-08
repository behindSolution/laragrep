<?php

namespace LaraGrep\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use LaraGrep\LaraGrep;
use LaraGrep\Monitor\MonitorRecorder;

class QueryController extends Controller
{
    public function __construct(
        protected LaraGrep $service,
        protected ?MonitorRecorder $recorder = null,
    ) {
    }

    public function __invoke(Request $request, ?string $scope = null): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string'],
            'debug' => ['sometimes', 'boolean'],
            'conversation_id' => ['sometimes', 'nullable', 'string'],
        ]);

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

        if ($this->recorder !== null) {
            $answer = $this->recorder->answerQuestion(
                $validated['question'],
                $debug,
                $scope,
                $conversationId,
                auth()->id(),
            );
        } else {
            $answer = $this->service->answerQuestion(
                $validated['question'],
                $debug,
                $scope,
                $conversationId,
            );
        }

        if ($conversationId !== null) {
            $answer['conversation_id'] = $conversationId;
        }

        return response()->json($answer);
    }
}
