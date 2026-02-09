<?php

namespace LaraGrep\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraGrep\Events\RecipeDispatched;
use LaraGrep\Recipe\RecipeStore;

class RecipeController extends Controller
{
    public function __construct(
        protected RecipeStore $store,
    ) {
    }

    public function show(int $id): JsonResponse
    {
        $entry = $this->store->find($id);

        if ($entry === null) {
            return response()->json(['error' => 'Recipe not found.'], 404);
        }

        $recipe = json_decode($entry->recipe ?? '{}', true);

        return response()->json([
            'id' => $entry->id,
            'question' => $entry->question,
            'scope' => $entry->scope,
            'summary' => $entry->summary,
            'recipe' => $recipe,
            'conversation_id' => $entry->conversation_id,
            'user_id' => $entry->user_id,
            'created_at' => $entry->created_at,
        ]);
    }

    public function dispatch(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'string', 'in:export,notification'],
            'period' => ['sometimes', 'string'],
        ]);

        $entry = $this->store->find($id);

        if ($entry === null) {
            return response()->json(['error' => 'Recipe not found.'], 404);
        }

        $recipe = json_decode($entry->recipe ?? '{}', true);

        if (!is_array($recipe) || empty($recipe['queries'])) {
            return response()->json(['error' => 'Invalid recipe data.'], 422);
        }

        $format = $validated['format'];
        $period = $validated['period'] ?? 'now';

        $userIdResolver = config('laragrep.user_id_resolver');
        $userId = is_callable($userIdResolver) ? $userIdResolver() : auth()->id();

        RecipeDispatched::dispatch(
            recipeId: (int) $entry->id,
            recipe: $recipe,
            format: $format,
            period: $period,
            userId: $userId,
        );

        return response()->json(['status' => 'dispatched']);
    }
}
