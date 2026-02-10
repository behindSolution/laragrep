<?php

namespace LaraGrep\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaraGrep\Async\AsyncStore;

class AsyncQueryController extends Controller
{
    public function __construct(
        protected AsyncStore $store,
    ) {
    }

    public function __invoke(string $queryId): JsonResponse
    {
        $record = $this->store->find($queryId);

        if ($record === null) {
            return response()->json(['error' => 'Query not found.'], 404);
        }

        if ($record->status === 'completed') {
            $result = json_decode($record->result ?? '{}', true);

            return response()->json(array_merge(
                ['status' => 'completed'],
                is_array($result) ? $result : [],
            ));
        }

        if ($record->status === 'failed') {
            return response()->json([
                'status' => 'failed',
                'error' => $record->error ?? 'Unknown error.',
            ]);
        }

        $response = ['status' => 'processing'];

        if (!empty($record->progress)) {
            $response['progress'] = $record->progress;
        }

        return response()->json($response);
    }
}
