<?php

namespace LaraGrep\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RecipeDispatched
{
    use Dispatchable;

    public function __construct(
        public readonly int $recipeId,
        public readonly array $recipe,
        public readonly string $format,
        public readonly string $period,
        public readonly string|int|null $userId = null,
    ) {
    }
}
